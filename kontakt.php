<?php
/**
 * kontakt.php: Versand des Kontaktformulars von fotomek.de (Entwurf vom 30.10.2026, NICHT live getestet).
 *
 * Liegt im Webroot neben index.html und ersetzt das bisherige kontakt.php (Ziel der Formulare ist /kontakt.php).
 * Lauffaehig unter PHP 5.6 und PHP 8.x (keine Typangaben, kein ??, keine neuen Sprachmittel).
 *
 * Grundlage: legal/kontaktformular-vorgaben-webdesign.md und Datenschutzerklaerung Abschnitt 6 (Baustein H).
 *  - Felder: Firma, Name, E-Mail, Nachricht (Pflicht), Telefon (optional). Weitere Felder werden ignoriert.
 *  - Versand per SMTP (Entscheidung Thomas, 30.10.2026). Zugangsdaten nur aus einer Datei ausserhalb des
 *    Webroots oder aus Umgebungsvariablen, nie im Code. Fehlt die Konfiguration oder enthaelt sie noch
 *    Platzhalter, wird NICHT gesendet (kein stiller mail()-Fallback), Besucher sehen die Fehlermeldung.
 *    PHP mail() nur, wenn in der Konfiguration ausdruecklich 'versand' => 'mail' steht.
 *  - Keine Speicherung: keine Datenbank, keine Dateien, kein Log, kein error_log mit Formularinhalten,
 *    keine Cookies, keine Sessions.
 *  - Schutz: nur POST, nur HTTPS, Herkunftspruefung (Origin/Referer), Honeypot, Mindest-Ausfuellzeit,
 *    serverseitige Pruefung mit Laengengrenzen, keine Zeilenumbrueche in Kopfzeilenfeldern,
 *    E-Mail per filter_var, Reply-To nur bei gueltiger Adresse, From immer eine Adresse der eigenen Domain.
 *
 * Konfiguration (Pflicht fuer den Versand):
 *  1. Datei  ../fotomek-kontakt-config.php  (eine Ebene ueber dem Webroot) oder Pfad aus der
 *     Umgebungsvariable FOTOMEK_KONTAKT_CONFIG. Die Datei gibt nur ein Array zurueck, Vorlage:
 *     kontakt-config.example.php (NICHT in den Webroot hochladen).
 *     Empfaenger: Schluessel 'to' (Standard thomas@malyska.de, spaeter z. B. info@fotomek.de).
 *  2. Umgebungsvariablen (ueberschreiben die Datei): FOTOMEK_KONTAKT_VERSAND (smtp|mail), FOTOMEK_KONTAKT_AN,
 *     FOTOMEK_KONTAKT_VON, FOTOMEK_SMTP_HOST, FOTOMEK_SMTP_PORT, FOTOMEK_SMTP_SECURE (ssl|tls),
 *     FOTOMEK_SMTP_USER, FOTOMEK_SMTP_PASS.
 */

@ini_set('display_errors', '0');   // keine technischen Details an Besucher

// Standard-Empfaenger: oeffentliche Kontaktadresse laut Impressum (kein Geheimnis). In der Config per 'to' aenderbar.
define('EMPFAENGER', 'thomas@malyska.de');
define('ABSENDER', 'no-reply@fotomek.de');      // Adresse der eigenen Domain (SPF beachten), wie bisher
define('ABSENDERNAME', 'Fotomek Online-Auftritt');
define('TELEFON_ANZEIGE', '+49 175 7770603');
define('ZIEL_OK', '/#anfrage-gesendet');         // 303 nach erfolgreichem Versand (ohne JavaScript)
define('ZIEL_FORMULAR', '/#kontakt');
define('HTTPS_URL', 'https://fotomek.de');       // Ziel, wenn das Skript ohne HTTPS aufgerufen wird
define('MIN_SEKUNDEN', 3);                       // schneller ausgefuellt gilt als Bot

define('MSG_OK', 'Vielen Dank. Ihre Nachricht wurde übermittelt. Wir melden uns in der Regel innerhalb von 1 bis 2 Werktagen.');
define('MSG_FEHLER', 'Die Nachricht konnte nicht gesendet werden. Bitte schreiben Sie an thomas@malyska.de oder rufen Sie +49 175 7770603 an.');

// ------------------------------------------------------------------ Hilfsfunktionen
function k_post($key)
{
    return (isset($_POST[$key]) && is_string($_POST[$key])) ? $_POST[$key] : '';
}

function k_len($s)
{
    if (function_exists('mb_strlen')) { return mb_strlen($s, 'UTF-8'); }
    return (int)preg_match_all('/./su', $s, $m);
}

/** Steuerzeichen entfernen (Zeilenumbrueche bleiben), Ränder kuerzen. */
function k_clean($s)
{
    $s = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
    return trim($s);
}

/** Fuer Kopfzeilen und Betreff: niemals CR/LF. */
function k_oneline($s)
{
    return trim((string)preg_replace('/[\r\n\t]+/', ' ', k_clean($s)));
}

function k_has_break($s)
{
    return strpbrk($s, "\r\n") !== false;
}

function k_enc_header($s)
{
    return '=?UTF-8?B?' . base64_encode($s) . '?=';
}

function k_wants_json()
{
    $xr = isset($_SERVER['HTTP_X_REQUESTED_WITH']) ? $_SERVER['HTTP_X_REQUESTED_WITH'] : '';
    $ac = isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : '';
    return $xr === 'fetch' || strpos($ac, 'application/json') !== false;
}

function k_is_https()
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') { return true; }
    if (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443') { return true; }
    if (isset($_SERVER['REQUEST_SCHEME']) && strtolower((string)$_SERVER['REQUEST_SCHEME']) === 'https') { return true; }
    return false;
}

function k_norm_host($h)
{
    $h = (string)preg_replace('/:\d+$/', '', (string)$h);
    return strtolower((string)preg_replace('/^www\./i', '', $h));
}

/** Pfad der Formularseite aus dem Referer (nur gleiche Domain, nur einfacher Pfad), sonst null. */
function k_formular_pfad()
{
    if (empty($_SERVER['HTTP_REFERER'])) { return null; }
    $ref = (string)$_SERVER['HTTP_REFERER'];
    $h = parse_url($ref, PHP_URL_HOST);
    $p = parse_url($ref, PHP_URL_PATH);
    $host = k_norm_host(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '');
    if (!is_string($h) || $host === '' || k_norm_host($h) !== $host) { return null; }
    if (!is_string($p) || !preg_match('#^/[A-Za-z0-9/_.\-]{0,200}$#', $p) || strpos($p, '..') !== false || strpos($p, '//') !== false) { return null; }
    return $p;
}

function k_status($code)
{
    if (function_exists('http_response_code')) { http_response_code($code); return; }
    header('X-Status: ' . $code, true, $code);
}

/** Antwort senden und beenden. $fehler: Liste von Feldfehlern (Feldname => Text). */
function k_finish($status, $ok, $msg, $fehler = array())
{
    k_status($status);
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    header('X-Content-Type-Options: nosniff');
    if (k_wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        $flags = defined('JSON_UNESCAPED_UNICODE') ? JSON_UNESCAPED_UNICODE : 0;
        echo json_encode(array('ok' => (bool)$ok, 'message' => $msg, 'errors' => (object)$fehler), $flags);
        exit;
    }
    if ($ok) {
        $pfad = k_formular_pfad();   // zurueck auf die Seite, auf der das Formular steht
        header('Location: ' . ($pfad !== null ? $pfad . '#anfrage-gesendet' : ZIEL_OK), true, 303);
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    $h = function ($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
    $liste = '';
    foreach ($fehler as $t) { $liste .= '<li>' . $h($t) . '</li>'; }
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
       . '<meta name="color-scheme" content="light"><title>Anfrage nicht gesendet | Fotomek</title><style>'
       . '@font-face{font-family:Inter;src:url(/assets/fonts/inter-latin.woff2) format("woff2");font-weight:300 600;font-display:swap}'
       . '@font-face{font-family:Sora;src:url(/assets/fonts/sora-latin.woff2) format("woff2");font-weight:300 600;font-display:swap}'
       . 'body{margin:0;background:#fbfbfd;color:#1d1d1f;font:17px/1.65 Inter,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;display:grid;min-height:100vh;place-items:center;padding:24px;box-sizing:border-box}'
       . 'main{max-width:580px;background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:20px;padding:40px 36px;box-shadow:0 30px 70px -50px rgba(0,0,0,.35)}'
       . 'h1{font-family:Sora,Inter,system-ui,sans-serif;font-weight:500;font-size:26px;line-height:1.2;margin:0 0 14px;letter-spacing:-.02em}'
       . 'a{color:#8a6a2f}ul{padding-left:20px;margin:0 0 18px}.btn{display:inline-block;margin-top:8px;padding:13px 24px;border-radius:999px;background:#c9a96e;color:#141414;text-decoration:none;font-weight:500}'
       . '</style></head><body><main><h1>Ihre Nachricht wurde nicht gesendet</h1>'
       . ($liste !== '' ? '<ul>' . $liste . '</ul>' : '<p>' . $h($msg) . '</p>')
       . '<p>Sie erreichen uns auch direkt per E-Mail an <a href="mailto:' . $h(EMPFAENGER) . '">' . $h(EMPFAENGER) . '</a>'
       . ' oder telefonisch unter <a href="tel:' . $h(str_replace(' ', '', TELEFON_ANZEIGE)) . '">' . $h(TELEFON_ANZEIGE) . '</a>.</p>'
       . '<p><a class="btn" href="' . $h(k_formular_pfad() !== null ? k_formular_pfad() : ZIEL_FORMULAR) . '">Zurück zum Formular</a></p></main></body></html>';
    exit;
}

/** Konfiguration: Datei ausserhalb des Webroots, danach Umgebungsvariablen. Ohne beides: mail(). */
function k_config()
{
    $c = array('versand' => 'smtp', 'to' => EMPFAENGER, 'from' => ABSENDER, 'from_name' => ABSENDERNAME,
               'host' => '', 'port' => 465, 'secure' => 'ssl', 'user' => '', 'pass' => '', 'timeout' => 15);
    $pfade = array();
    $envPfad = getenv('FOTOMEK_KONTAKT_CONFIG');
    if (is_string($envPfad) && $envPfad !== '') { $pfade[] = $envPfad; }
    $pfade[] = dirname(__DIR__) . '/fotomek-kontakt-config.php';
    $gefunden = false;
    foreach ($pfade as $p) {
        if (@is_file($p) && @is_readable($p)) {
            $gefunden = true;
            $datei = include $p;
            if (is_array($datei)) {
                foreach ($datei as $k => $v) { if (array_key_exists($k, $c)) { $c[$k] = $v; } }
            }
            break;
        }
    }
    $env = array('versand' => 'FOTOMEK_KONTAKT_VERSAND', 'to' => 'FOTOMEK_KONTAKT_AN', 'from' => 'FOTOMEK_KONTAKT_VON',
                 'host' => 'FOTOMEK_SMTP_HOST', 'port' => 'FOTOMEK_SMTP_PORT', 'secure' => 'FOTOMEK_SMTP_SECURE',
                 'user' => 'FOTOMEK_SMTP_USER', 'pass' => 'FOTOMEK_SMTP_PASS');
    foreach ($env as $k => $name) {
        $v = getenv($name);
        if (is_string($v) && $v !== '') { $c[$k] = $v; if ($k === 'host') { $gefunden = true; } }
    }
    if (!$gefunden) { $c['versand'] = 'ungueltig'; return $c; }   // keine Konfiguration: nicht senden
    $c['versand'] = strtolower((string)$c['versand']) === 'mail' ? 'mail' : 'smtp';
    $c['port'] = (int)$c['port'];
    $c['timeout'] = (int)$c['timeout'] > 0 ? (int)$c['timeout'] : 15;
    $c['secure'] = strtolower((string)$c['secure']);
    foreach (array('to', 'from') as $k) {
        $c[$k] = k_oneline((string)$c[$k]);
        if (!filter_var($c[$k], FILTER_VALIDATE_EMAIL)) { $c[$k] = ($k === 'to') ? EMPFAENGER : ABSENDER; }
    }
    $c['from_name'] = k_oneline((string)$c['from_name']);
    if ($c['versand'] === 'smtp') {
        $lokal = in_array($c['host'], array('127.0.0.1', 'localhost'), true);
        $sicher = in_array($c['secure'], array('ssl', 'tls'), true) || ($c['secure'] === 'none' && $lokal);
        $platzhalter = preg_match('/SMTP-SERVER|POSTFACH@|HIER-PASSWORT/i', $c['host'] . ' ' . $c['user'] . ' ' . $c['pass'] . ' ' . $c['from']);
        if ($c['host'] === '' || !$sicher || ($c['user'] === '' && !$lokal) || $platzhalter) { $c['versand'] = 'ungueltig'; }
    }
    return $c;
}

/** Minimaler SMTP-Client (EHLO, STARTTLS oder SSL, AUTH LOGIN, MAIL, RCPT, DATA). Liefert true/false, protokolliert nichts. */
function k_smtp_send($c, $to, $data)
{
    $remote = ($c['secure'] === 'ssl' ? 'ssl://' : 'tcp://') . $c['host'] . ':' . $c['port'];
    $ctx = stream_context_create(array('ssl' => array('verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $c['host'])));
    $errno = 0; $errstr = '';
    $fp = @stream_socket_client($remote, $errno, $errstr, $c['timeout'], STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) { return false; }
    stream_set_timeout($fp, $c['timeout']);
    $ok = false;
    try {
        $helo = (string)preg_replace('/[^a-z0-9.\-]/i', '', isset($_SERVER['SERVER_NAME']) ? (string)$_SERVER['SERVER_NAME'] : '');
        if ($helo === '') { $helo = 'localhost'; }
        $ehlo = k_smtp_cmd($fp, '', array(220));
        $ehlo = k_smtp_cmd($fp, 'EHLO ' . $helo, array(250));
        if ($c['secure'] === 'tls') {
            k_smtp_cmd($fp, 'STARTTLS', array(220));
            $m = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) { $m = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT; }
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) { $m = $m | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT; }
            if (!@stream_socket_enable_crypto($fp, true, $m)) { throw new RuntimeException('tls'); }
            $ehlo = k_smtp_cmd($fp, 'EHLO ' . $helo, array(250));
        }
        if ($c['user'] !== '') {
            k_smtp_cmd($fp, 'AUTH LOGIN', array(334));
            k_smtp_cmd($fp, base64_encode((string)$c['user']), array(334));
            k_smtp_cmd($fp, base64_encode((string)$c['pass']), array(235));
        }
        k_smtp_cmd($fp, 'MAIL FROM:<' . $c['from'] . '>', array(250));
        k_smtp_cmd($fp, 'RCPT TO:<' . $to . '>', array(250, 251));
        k_smtp_cmd($fp, 'DATA', array(354));
        $data = str_replace(array("\r\n", "\r"), "\n", $data);
        $data = (string)preg_replace('/^\./m', '..', $data);
        k_smtp_cmd($fp, str_replace("\n", "\r\n", $data) . "\r\n.", array(250));
        @fwrite($fp, "QUIT\r\n");
        $ok = true;
    } catch (Exception $e) {
        $ok = false;   // bewusst kein Logging
    }
    @fclose($fp);
    return $ok;
}

function k_smtp_cmd($fp, $line, $okCodes)
{
    if ($line !== '') { fwrite($fp, $line . "\r\n"); }
    $txt = '';
    while (($l = fgets($fp, 1024)) !== false) {
        $txt .= $l;
        if (strlen($l) < 4 || $l[3] === ' ') { break; }
    }
    if (!in_array((int)substr($txt, 0, 3), $okCodes, true)) { throw new RuntimeException('smtp'); }
    return $txt;
}

// ------------------------------------------------------------------ Ablauf
$methode = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : '';
if ($methode !== 'POST') {
    header('Allow: POST');
    header('Location: ' . (k_is_https() ? '' : HTTPS_URL) . ZIEL_FORMULAR, true, 303);
    exit;
}

// Nur HTTPS: unverschluesselt gesendete Formulare werden nicht verarbeitet (Inhalt wird verworfen).
if (!k_is_https()) {
    k_finish(403, false, MSG_FEHLER);
}

// Herkunft pruefen, falls der Browser sie mitsendet (gleiche Domain; www. und Port werden ignoriert).
$host = k_norm_host(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '');
foreach (array('HTTP_ORIGIN', 'HTTP_REFERER') as $k) {
    if (!empty($_SERVER[$k])) {
        $h = parse_url((string)$_SERVER[$k], PHP_URL_HOST);
        if (is_string($h) && $host !== '' && k_norm_host($h) !== $host) { k_finish(403, false, MSG_FEHLER); }
    }
}

// Honeypot: ausgefuellt heisst Bot. Es wird nichts gesendet, die Antwort sieht aus wie ein Erfolg.
if (k_post('website') !== '') { k_finish(200, true, MSG_OK); }
// Zeitsperre (Feld setzt das Seiten-Skript; fehlt es, z. B. ohne JavaScript, entfaellt die Pruefung).
$ts = (int)k_post('ts');
if ($ts > 0 && $ts <= time() && (time() - $ts) < MIN_SEKUNDEN) { k_finish(200, true, MSG_OK); }

// Rohwerte
$roh = array('firma' => k_post('firma'), 'name' => k_post('name'), 'email' => k_post('email'),
             'telefon' => k_post('telefon'), 'nachricht' => k_post('nachricht'));
$max = array('firma' => 150, 'name' => 100, 'email' => 254, 'telefon' => 40, 'nachricht' => 5000);

$fehler = array();
// Zeilenumbrueche in einzeiligen Feldern ablehnen (Header Injection)
foreach (array('firma', 'name', 'email', 'telefon') as $k) {
    if (k_has_break($roh[$k])) { $fehler[$k] = 'Bitte verwenden Sie in diesem Feld keine Zeilenumbrüche.'; }
}
$firma     = k_oneline($roh['firma']);
$name      = k_oneline($roh['name']);
$email     = k_oneline($roh['email']);
$telefon   = k_oneline($roh['telefon']);
$nachricht = k_clean(str_replace(array("\r\n", "\r"), "\n", $roh['nachricht']));

if (!isset($fehler['firma'])) {
    if ($firma === '') { $fehler['firma'] = 'Bitte geben Sie Ihre Firma, Ihren Verein oder Ihre Behörde an.'; }
    elseif (k_len($firma) > $max['firma']) { $fehler['firma'] = 'Der Firmenname ist zu lang (höchstens 150 Zeichen).'; }
}
if (!isset($fehler['name'])) {
    if (k_len($name) < 2) { $fehler['name'] = 'Bitte geben Sie Ihren Namen an.'; }
    elseif (k_len($name) > $max['name']) { $fehler['name'] = 'Der Name ist zu lang (höchstens 100 Zeichen).'; }
}
if (!isset($fehler['email'])) {
    if ($email === '' || k_len($email) > $max['email'] || !filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\s,;<>"]/', $email)) {
        $fehler['email'] = 'Bitte geben Sie eine gültige E-Mail-Adresse an.';
    }
}
if (!isset($fehler['telefon']) && $telefon !== '') {
    if (k_len($telefon) > $max['telefon'] || !preg_match('/^[0-9+()\/. \-]{5,40}$/', $telefon)) {
        $fehler['telefon'] = 'Bitte prüfen Sie die Telefonnummer. Erlaubt sind Ziffern, Leerzeichen, Schrägstrich, Klammern, Punkt, Pluszeichen und Bindestrich.';
    }
}
if (k_len($nachricht) < 10) { $fehler['nachricht'] = 'Bitte beschreiben Sie Ihr Anliegen in einigen Sätzen.'; }
elseif (k_len($nachricht) > $max['nachricht']) { $fehler['nachricht'] = 'Die Nachricht ist zu lang (höchstens 5000 Zeichen).'; }

// Pflicht-Ankreuzbox Geschaeftskunde (Wortlaut: GrokBot/legal/vorabpruefung-positionierung-2026-10-30.md)
if (k_post('gewerblich') !== 'ja') { $fehler['gewerblich'] = 'Bitte bestätigen Sie, dass Sie nicht als Verbraucher anfragen.'; }

if ($fehler) {
    k_finish(422, false, 'Bitte prüfen Sie Ihre Angaben.', $fehler);
}

// ------------------------------------------------------------------ Nachricht zusammenstellen
$c = k_config();
if ($c['versand'] === 'ungueltig') { k_finish(500, false, MSG_FEHLER); }

$betreff = k_oneline('Anfrage über fotomek.de von ' . $firma);
if (function_exists('mb_substr')) { $betreff = mb_substr($betreff, 0, 120, 'UTF-8'); }
$betreffKodiert = k_enc_header($betreff);

$text = "Neue Anfrage über das Kontaktformular auf fotomek.de\n\n"
      . 'Firma:    ' . $firma . "\n"
      . 'Name:     ' . $name . "\n"
      . 'E-Mail:   ' . $email . "\n"
      . 'Telefon:  ' . ($telefon !== '' ? $telefon : '(nicht angegeben)') . "\n"
      . 'Bestätigt: Ich frage für ein Unternehmen, einen Verein oder eine öffentliche Stelle an, nicht als Verbraucher.' . "\n\n"
      . "Nachricht:\n" . $nachricht . "\n\n"
      . "-- \nDiese Nachricht wurde nur per E-Mail zugestellt; auf dem Webserver wird sie nicht gespeichert.\n";
$body = quoted_printable_encode(str_replace("\n", "\r\n", $text));

$kopf = array();
$kopf[] = 'From: ' . ($c['from_name'] !== '' ? k_enc_header($c['from_name']) . ' ' : '') . '<' . $c['from'] . '>';
if (filter_var($email, FILTER_VALIDATE_EMAIL) && !k_has_break($email)) {
    $kopf[] = 'Reply-To: <' . $email . '>';
}
$kopf[] = 'MIME-Version: 1.0';
$kopf[] = 'Content-Type: text/plain; charset=UTF-8';
$kopf[] = 'Content-Transfer-Encoding: quoted-printable';
$kopf[] = 'X-Mailer: fotomek-kontakt';

if ($c['versand'] === 'smtp') {
    $domain = substr(strrchr($c['from'], '@'), 1);
    $msgId = '<' . sha1(uniqid('', true) . mt_rand()) . '@' . $domain . '>';
    $daten = 'Date: ' . date('r') . "\r\n"
           . 'To: <' . $c['to'] . ">\r\n"
           . 'Subject: ' . $betreffKodiert . "\r\n"
           . 'Message-ID: ' . $msgId . "\r\n"
           . implode("\r\n", $kopf) . "\r\n\r\n" . $body;
    $gesendet = k_smtp_send($c, $c['to'], $daten);
} else {
    $param = preg_match('/^[A-Za-z0-9._+\-]+@[A-Za-z0-9.\-]+$/', $c['from']) ? '-f' . $c['from'] : '';
    $gesendet = $param !== ''
        ? @mail($c['to'], $betreffKodiert, $body, implode("\r\n", $kopf), $param)
        : @mail($c['to'], $betreffKodiert, $body, implode("\r\n", $kopf));
}

if (!$gesendet) {
    k_finish(500, false, MSG_FEHLER);
}
k_finish(200, true, MSG_OK);
