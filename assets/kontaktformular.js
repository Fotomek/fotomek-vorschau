/* Kontaktformular fotomek.de (30.10.2026): Versand an /kontakt.php per fetch; ohne JavaScript klassischer POST. Speichert nichts im Browser. */
(function(){
  var f=document.getElementById('anfrage-form');if(!f||!window.fetch||!window.FormData)return;
  var msg=document.getElementById('anfrage-msg'),btn=f.querySelector('button[type=submit]');
  var ts=document.getElementById('anfrage-ts');function stamp(){if(ts)ts.value=Math.floor(Date.now()/1000)}stamp();
  var OK='Vielen Dank. Ihre Nachricht wurde übermittelt. Wir melden uns in der Regel innerhalb von 1 bis 2 Werktagen.';
  var FEHLER='Die Nachricht konnte nicht gesendet werden. Bitte schreiben Sie an thomas@malyska.de oder rufen Sie +49 175 7770603 an.';
  var TXT={firma:'Bitte geben Sie Ihre Firma, Ihren Verein oder Ihre Behörde an.',name:'Bitte geben Sie Ihren Namen an.',email:'Bitte geben Sie eine gültige E-Mail-Adresse an.',telefon:'Bitte prüfen Sie die Telefonnummer.',nachricht:'Bitte beschreiben Sie Ihr Anliegen in einigen Sätzen.',gewerblich:'Bitte bestätigen Sie, dass Sie nicht als Verbraucher anfragen.'};
  /* Vorauswahl aus Angebotsseiten (?angebot=...&paket=...) als erste Zeile der Nachricht */
  var NAMES={webseiten:'Online-Auftritt',formular:'Formular (Zusatz)',audits:'Audit (Online-Auftritt prüfen)','gefunden-werden':'Gefunden werden: SEO und GEO'};
  try{var q=new URLSearchParams(location.search),an=NAMES[q.get('angebot')]||'',pk=(q.get('paket')||'').slice(0,60);
    var t=f.elements.nachricht;if(t&&!t.value&&(an||pk))t.value='Interesse an: '+[an,pk].filter(Boolean).join(', ')+'\n'}catch(e){}
  function setErr(n,s){var el=f.elements[n],e=document.getElementById('anfrage-e-'+n);if(el)el.setAttribute('aria-invalid',s?'true':'false');if(e){e.textContent=s||'';e.hidden=!s}}
  f.addEventListener('change',function(e){if(e.target&&e.target.name==='gewerblich'&&e.target.checked)setErr('gewerblich','')});
  function clearAll(){Object.keys(TXT).forEach(function(n){setErr(n,'')});msg.textContent='';msg.classList.remove('err')}
  function check(){var first=null;Object.keys(TXT).forEach(function(n){var el=f.elements[n];if(!el)return;var v=el.value.trim();
    var bad=!el.checkValidity()||(el.required&&!v)||(n==='telefon'&&v&&!/^[0-9+()\/. -]{5,40}$/.test(v));setErr(n,bad?TXT[n]:'');if(bad&&!first)first=el});return first}
  f.addEventListener('submit',function(e){
    e.preventDefault();clearAll();
    var bad=check();if(bad){msg.classList.add('err');msg.textContent='Bitte prüfen Sie die markierten Felder.';bad.focus();return}
    btn.disabled=true;msg.textContent='Ihre Nachricht wird gesendet.';
    fetch(f.getAttribute('action'),{method:'POST',body:new FormData(f),headers:{'Accept':'application/json','X-Requested-With':'fetch'},credentials:'same-origin'})
      .then(function(r){return r.json().catch(function(){return {ok:false}})})
      .then(function(d){
        if(d&&d.ok){f.reset();stamp();msg.textContent=d.message||OK;return}
        var errs=(d&&d.errors)||{},k=Object.keys(errs);k.forEach(function(n){setErr(n,errs[n])});
        msg.classList.add('err');msg.textContent=k.length?'Bitte prüfen Sie die markierten Felder.':FEHLER;
        if(k.length&&f.elements[k[0]])f.elements[k[0]].focus()})
      .catch(function(){msg.classList.add('err');msg.textContent=FEHLER})
      .then(function(){btn.disabled=false});
  });
})();
