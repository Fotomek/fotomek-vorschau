/* fotomek.de, Entwurf 30.10.2026. Ohne externe Bibliotheken. */
(function(){
'use strict';
var d=document,root=d.documentElement;
var reduce=window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/* ---------- Hell/Dunkel-Umschalter ---------- */
/* PERSIST=false: Die Wahl gilt nur fuer die aktuelle Seite. Die Datenschutzerklaerung sagt
   "speichert keine Daten auf Ihrem Endgeraet". Soll die Wahl seitenuebergreifend gemerkt werden
   (localStorage), PERSIST=true setzen UND die Datenschutzerklaerung anpassen (offene Frage im README). */
var PERSIST=window.FM_PERSIST_THEME===true;
/* Hell ist immer der Start, unabhaengig von der Systemeinstellung; Dunkel nur per Umschalter. */
function eff(){return root.getAttribute('data-theme')==='dark'?'dark':'light'}
function paint(){var e=eff();root.setAttribute('data-theme-eff',e);
  d.querySelectorAll('.theme-toggle').forEach(function(b){b.setAttribute('aria-pressed',e==='dark'?'true':'false');b.setAttribute('aria-label',e==='dark'?'Helle Darstellung einschalten':'Dunkle Darstellung einschalten')});
  var m=d.querySelector('meta[name="theme-color"]');if(m)m.setAttribute('content',e==='dark'?'#111213':'#fcfcfb')}
d.querySelectorAll('.theme-toggle').forEach(function(b){b.addEventListener('click',function(){
  var n=eff()==='dark'?'light':'dark';root.setAttribute('data-theme',n);
  if(PERSIST){try{localStorage.setItem('fm-theme',n)}catch(e){}}paint()})});
paint();

/* ---------- Menue ---------- */
var burger=d.querySelector('.burger'),menu=d.getElementById('menu');
if(burger&&menu){
  var setMenu=function(o){burger.setAttribute('aria-expanded',o?'true':'false');burger.setAttribute('aria-label',o?'Menü schließen':'Menü öffnen');menu.classList.toggle('open',o)};
  burger.addEventListener('click',function(){setMenu(burger.getAttribute('aria-expanded')!=='true')});
  menu.querySelectorAll('a').forEach(function(a){a.addEventListener('click',function(){setMenu(false)})});
  d.addEventListener('keydown',function(e){if(e.key==='Escape')setMenu(false)});
}

/* ---------- Alte Anker der bisherigen Startseite (fotomek.de/#...) ---------- */
/* Anker werden vom Browser nicht an den Server geschickt, darum leitet hier ein kleines Skript um. */
if(d.body.getAttribute('data-page')==='start'&&location.hash.length>1){
  var h=decodeURIComponent(location.hash.slice(1));
  if(!d.getElementById(h)){
    var target=null;
    if(h==='kontakt'||h==='kontakt-form'||h==='kontakt-title')target='/kontakt/';
    if(target){location.replace(target)}
  }
}

/* ---------- Einblenden ---------- */
var rev=d.querySelectorAll('.reveal');
if(reduce||!('IntersectionObserver' in window)){rev.forEach(function(e){e.classList.add('in')})}
else{var io=new IntersectionObserver(function(es){es.forEach(function(e){if(e.isIntersecting){e.target.classList.add('in');io.unobserve(e.target)}})},{threshold:.08,rootMargin:'0px 0px -5% 0px'});
  rev.forEach(function(e){io.observe(e)});setTimeout(function(){rev.forEach(function(e){e.classList.add('in')})},4000)}
d.querySelectorAll('details').forEach(function(x){x.addEventListener('toggle',function(){if(x.open)x.querySelectorAll('.reveal').forEach(function(r){r.classList.add('in')})})});

/* ---------- Anker in eingeklappten Bereichen oeffnen ---------- */
function openFor(id){if(!id)return;var el=d.getElementById(id);if(!el)return;
  for(var n=el;n&&n!==d.body;n=n.parentElement){if(n.tagName==='DETAILS')n.open=true}
  var acc=d.getElementById('acc-'+id);if(acc)acc.open=true;
  if(el.tagName==='DETAILS')el.open=true;
  setTimeout(function(){el.scrollIntoView({behavior:reduce?'auto':'smooth',block:'start'})},30)}
if(location.hash.length>1)openFor(decodeURIComponent(location.hash.slice(1)));
window.addEventListener('hashchange',function(){openFor(decodeURIComponent(location.hash.slice(1)))});
d.addEventListener('click',function(e){var a=e.target.closest&&e.target.closest('a[href^="#"]');if(a&&a.getAttribute('href').length>1){var id=a.getAttribute('href').slice(1);var el=d.getElementById(id);if(el){for(var n=el;n&&n!==d.body;n=n.parentElement){if(n.tagName==='DETAILS')n.open=true}}}});

/* ---------- Karussell ---------- */
d.querySelectorAll('.carousel').forEach(function(c){var t=c.querySelector('.car-track');if(!t)return;
  var step=function(){var s=t.querySelector('.slide');return s?s.getBoundingClientRect().width+14:300};
  var p=c.querySelector('.car-btn.prev'),n=c.querySelector('.car-btn.next');
  if(p)p.addEventListener('click',function(){t.scrollBy({left:-step(),behavior:reduce?'auto':'smooth'})});
  if(n)n.addEventListener('click',function(){t.scrollBy({left:step(),behavior:reduce?'auto':'smooth'})});
  t.addEventListener('keydown',function(e){if(e.key==='ArrowRight')t.scrollBy({left:step()});if(e.key==='ArrowLeft')t.scrollBy({left:-step()})});
});

/* ---------- Vorher/Nachher-Regler ---------- */
d.querySelectorAll('.compare').forEach(function(c){var r=c.querySelector('input[type=range]');if(!r)return;
  var l=c.querySelector('.tag.l');
  var upd=function(){c.style.setProperty('--p',r.value+'%');r.setAttribute('aria-valuetext',r.value+' % '+(l?l.textContent:'Vorher'))};
  r.addEventListener('input',upd);upd();
  /* WCAG 2.5.7: Alternative zum Ziehen, drei Tasten */
  var g=d.createElement('div');g.className='compare-btns';g.setAttribute('role','group');g.setAttribute('aria-label','Vergleich umschalten');
  var bs=[['Vorher',100],['Beides',50],['Nachher',0]].map(function(x){var b=d.createElement('button');b.type='button';b.textContent=x[0];
    b.addEventListener('click',function(){r.value=x[1];upd();mark()});g.appendChild(b);return [b,x[1]]});
  function mark(){bs.forEach(function(p){p[0].setAttribute('aria-pressed',String(+r.value===p[1]))})}
  r.addEventListener('input',mark);mark();c.parentNode.insertBefore(g,c.nextSibling)});

/* ---------- Tabs (Home Staging, Composing) ---------- */
[['.hs-vtab','hs-pane-'],['.bc-tab','bc-pane-']].forEach(function(cfg){
  var tabs=[].slice.call(d.querySelectorAll(cfg[0]));if(!tabs.length)return;
  function sel(t){tabs.forEach(function(x){var on=x===t;x.setAttribute('aria-selected',on?'true':'false');x.tabIndex=on?0:-1;var p=d.getElementById(x.getAttribute('aria-controls'));if(p)p.hidden=!on})}
  tabs.forEach(function(t,i){t.addEventListener('click',function(){sel(t)});
    t.addEventListener('keydown',function(e){if(e.key==='ArrowRight'||e.key==='ArrowLeft'){var n=tabs[(i+(e.key==='ArrowRight'?1:tabs.length-1))%tabs.length];sel(n);n.focus()}})});
});

/* ---------- Film: Pause-Knopf, spielt nur im Bild und nicht bei reduzierter Bewegung ---------- */
var v=d.getElementById('projektfilm-video'),fb=d.getElementById('film-toggle');
if(v&&fb){var manual=false;
  var lab=function(){fb.textContent=v.paused?'Abspielen':'Pause';fb.setAttribute('aria-label',v.paused?'Video abspielen':'Video pausieren')};
  fb.addEventListener('click',function(){manual=true;if(v.paused){var q=v.play();if(q&&q.catch)q.catch(function(){})}else{v.pause()}});
  v.addEventListener('play',lab);v.addEventListener('pause',lab);lab();
  if(!reduce&&'IntersectionObserver' in window){new IntersectionObserver(function(es){es.forEach(function(e){if(manual)return;if(e.isIntersecting){var q=v.play();if(q&&q.catch)q.catch(function(){})}else{v.pause()}})},{threshold:.35}).observe(v)}}


/* ---------- Header-Video: nur grosse Bildschirme, keine reduzierte Bewegung, kein Datensparen ---------- */
var hv=d.getElementById('hero-video'),hb=d.getElementById('hero-toggle');
if(hv){var sd=navigator.connection&&navigator.connection.saveData;
  if(reduce||sd||window.matchMedia('(max-width:640px)').matches){hv.remove();if(hb)hb.hidden=true}
  else{var src=d.createElement('source');src.src=hv.getAttribute('data-src');src.type='video/mp4';hv.appendChild(src);
    hv.addEventListener('playing',function(){hv.classList.add('on')});
    var hl=function(){if(hb){hb.textContent=hv.paused?'Abspielen':'Pause';hb.setAttribute('aria-label',hv.paused?'Header-Video abspielen':'Header-Video pausieren')}};
    hv.addEventListener('play',hl);hv.addEventListener('pause',hl);
    if(hb){hb.hidden=false;hb.addEventListener('click',function(){if(hv.paused){var q=hv.play();if(q&&q.catch)q.catch(function(){})}else{hv.pause()}})}
    hv.load();var q=hv.play();if(q&&q.catch)q.catch(function(){if(hb)hb.hidden=true});hl()}}

/* ---------- Grossansicht fuer Galeriebilder (nativer dialog) ---------- */
var lb=d.getElementById('lb');
if(lb&&lb.showModal){var li=lb.querySelector('img'),lc=lb.querySelector('p');
  function big(img){var full=img.closest('[data-full]');if(full)return full.getAttribute('data-full');
    var ss=(img.getAttribute('srcset')||'').split(',').map(function(s){return s.trim().split(/\s+/)});var best=null,w=0;
    ss.forEach(function(x){var n=parseInt(x[1],10)||0;if(n>w){w=n;best=x[0]}});return best||img.currentSrc||img.src}
  d.querySelectorAll('.slide-open').forEach(function(b){b.addEventListener('click',function(){var img=b.querySelector('img');if(!img)return;
    li.src=big(img);li.alt=img.alt;var fc=b.parentElement.querySelector('figcaption');lc.textContent=fc?fc.textContent:'';lb.showModal()})});
  lb.querySelector('.lb-close').addEventListener('click',function(){lb.close()});
  lb.addEventListener('click',function(e){if(e.target===lb)lb.close()});
  lb.addEventListener('close',function(){li.removeAttribute('src')});
}

/* ---------- Kontaktformular ---------- */
var f=d.getElementById('kontakt-form');
if(f&&f.tagName==='FORM'){
  var NAMES={webseiten:'Online-Auftritt bauen',audits:'Audit (Online-Auftritt prüfen)','gefunden-werden':'Gefunden werden: SEO und GEO'};
  var qs=new URLSearchParams(location.search);
  var radios=[].slice.call(f.querySelectorAll('input[name=angebot]'));
  var a=qs.get('angebot');if(a&&NAMES[a]){radios.forEach(function(r){r.checked=r.value===a})}
  var p=qs.get('paket');
  if(p){f.querySelectorAll('select[name=paket]').forEach(function(ps){[].slice.call(ps.options).forEach(function(o){if(o.value===p||o.text===p)ps.value=o.value})})}
  function cur(){var r=f.querySelector('input[name=angebot]:checked');return r?r.value:''}
  function sync(){var c=cur();f.querySelectorAll('[data-show]').forEach(function(el){var on=el.getAttribute('data-show').split(' ').indexOf(c)>-1;el.hidden=!on;
      el.querySelectorAll('input,select,textarea').forEach(function(i){i.disabled=!on})})}
  radios.forEach(function(r){r.addEventListener('change',sync)});sync();
  var ts=d.getElementById('f-ts');if(ts)ts.value=Math.floor(Date.now()/1000);
  var msg=d.getElementById('form-msg'),btn=f.querySelector('button[type=submit]');
  function g(n){var L=[].slice.call(f.querySelectorAll('[name="'+n+'"]')).filter(function(e){return !e.disabled&&(e.type!=='checkbox'&&e.type!=='radio'||e.checked)});return L.length&&L[0].value?L[0].value:''}
  function prefix(){var c=cur(),L=[];if(c)L.push('Angebot: '+NAMES[c]);if(g('paket'))L.push('Interesse: '+g('paket'));if(g('webadresse'))L.push('Webadresse: '+g('webadresse'));return L.join('\n')}
  function mailto(){return 'mailto:thomas@malyska.de?subject='+encodeURIComponent('Anfrage über fotomek.de: '+(NAMES[cur()]||'Allgemein'))+'&body='+encodeURIComponent(prefix()+'\n\nName: '+g('name')+'\nFirma: '+g('firma')+'\nE-Mail: '+g('email')+(g('projektgroesse')?'\nProjektgröße (Wohneinheiten): '+g('projektgroesse'):'')+(g('vertriebsstart')?'\nGeplanter Vertriebsstart: '+g('vertriebsstart'):'')+(g('bildanzahl')?'\nGeschätzte Bildanzahl: '+g('bildanzahl'):'')+'\n\n'+g('nachricht'))}
  /* Per E-Mail senden: oeffnet das eigene E-Mail-Programm mit allen Angaben (inkl. gewaehltem Angebot). Nichts wird ueber den Server gesendet. */
  /* Inline-Pruefung beim Verlassen eines Pflichtfelds (keine verfruehten Meldungen) */
  var MSG={name:'Bitte tragen Sie Ihren Namen ein.',email:'Bitte tragen Sie eine gültige E-Mail-Adresse ein, zum Beispiel name@firma.de.',nachricht:'Bitte schreiben Sie mindestens 10 Zeichen.',webadresse:'Bitte geben Sie die Adresse mit https:// an.'};
  Object.keys(MSG).forEach(function(n){var el=f.elements[n];if(!el||!el.id)return;
    var p=d.createElement('p');p.className='field-err';p.id=el.id+'-err';p.hidden=true;el.parentNode.appendChild(p);
    var db=el.getAttribute('aria-describedby');el.setAttribute('aria-describedby',(db?db+' ':'')+p.id);
    function chk(){var bad=el.value!==''||el.required?!el.checkValidity():false;el.setAttribute('aria-invalid',bad?'true':'false');p.hidden=!bad;p.textContent=bad?MSG[n]:''}
    el.addEventListener('blur',chk);el.addEventListener('input',function(){if(el.getAttribute('aria-invalid')==='true')chk()})});
  var ms=d.getElementById('mail-send');
  if(ms){ms.addEventListener('click',function(e){
    var need=['name','nachricht','unternehmer'];
    for(var i=0;i<need.length;i++){var el=f.elements[need[i]];if(el&&!el.checkValidity()){e.preventDefault();el.reportValidity();return}}
    var u=mailto()+encodeURIComponent('\n\nIch frage als Unternehmer an: ja');ms.href=u;
    msg.textContent=u.length>1900?'Ihre Nachricht ist lang. Bitte prüfen Sie im E-Mail-Programm, ob sie vollständig übernommen wurde.':'Ihr E-Mail-Programm öffnet sich mit Ihren Angaben. Bitte dort absenden.';
  })}
  function fail(t){msg.textContent='';msg.appendChild(d.createTextNode((t||'Der Versand ist gerade nicht möglich.')+' Bitte senden Sie Ihre Nachricht per '));
    var m=d.createElement('a');m.href=mailto();m.textContent='E-Mail an thomas@malyska.de';msg.appendChild(m);msg.appendChild(d.createTextNode('.'));btn.disabled=false}
  f.addEventListener('submit',function(e){
    if(!window.fetch||!window.FormData)return;
    if(!f.checkValidity())return;
    e.preventDefault();btn.disabled=true;msg.textContent='Wird gesendet …';
    var fd=new FormData(f);   /* kontakt.php (SMTP-Entwurf) uebernimmt Angebot, Paket und Webadresse selbst */
    fetch(f.getAttribute('action'),{method:'POST',body:fd,headers:{'X-Requested-With':'fetch','Accept':'application/json'}})
      .then(function(r){return r.json().then(function(j){return {ok:r.ok&&j.ok,j:j}})})
      .then(function(x){if(x.ok){location.href=new URL(x.j.redirect||'danke/',location.origin+'/').href}else{fail(x.j&&x.j.message)}})
      .catch(function(){fail()});
  });
}
})();
