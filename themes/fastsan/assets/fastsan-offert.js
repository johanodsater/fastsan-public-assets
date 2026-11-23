/*
 * Fastsan offert-form binding v1.2.0 (2026-11-23, B)
 * 1.2.0: robotskydd mot fastsan-lead-pipeline v0.7.0 — ett dolt kontrollfalt (fs_kontroll, ska vara tomt)
 *        och en biljett fran GET /wp-json/fastsan/v1/lead-token (ft + fs) som servern kraver ska vara
 *        minst 3 s gammal. Biljetten hamtas nar nagon borjar fylla i; ar den for gammal hamtas en ny.
 *        Dessutom autocomplete pa namn, e-post, telefon och foretag om sidans HTML saknar det.
 * 1.1.0: konverteringsevent vid bekraftat 201 — GA4 generate_lead (gtag ar alltid
 *        laddad, Consent Mode skoter samtycket) och Meta Lead (fbq finns bara om
 *        CookieYes gett advertisement:yes, darfor typeof-vakt). Rela B->C 2026-10-19 P0.
 * Binder .fs-offert-form (/kontakt/#offert) mot POST /wp-json/fastsan/v1/lead.
 * Kontrakt: fastsan-lead-pipeline.php v0.1.0 — name + (email|phone) krävs,
 * enum-fält object_type/urgency, consent_marketing, source. Inget filstöd:
 * foto-fältet döljs tills pipelinen kan ta emot bilagor.
 */
(function () {
  'use strict';

  var form = document.getElementById('offert');
  if (!form || form.dataset.fsBound) return;
  form.dataset.fsBound = '1';

  // Pipeline v0.1.0 saknar filstöd — dölj foto-fältet så inget lovas som slängs.
  var foto = document.getElementById('of-foto');
  if (foto) {
    var fotoField = foto.closest('.fs-field');
    if (fotoField) fotoField.hidden = true;
  }

  var btn = form.querySelector('button[type="submit"]');
  var btnLabel = btn ? btn.textContent : '';

  // Autofyll: webblasaren ska kunna fylla i namn, e-post, telefon och foretag (WCAG 1.3.5).
  [['of-name', 'name'], ['of-email', 'email'], ['of-tel', 'tel'], ['of-org', 'organization']].forEach(function (p) {
    var el = document.getElementById(p[0]);
    if (el && !el.getAttribute('autocomplete')) el.setAttribute('autocomplete', p[1]);
  });

  // Robotskydd 1: dolt kontrollfalt. En manniska ser det inte; en robot som fyller i allt avslojar sig.
  var hp = document.createElement('input');
  hp.type = 'text';
  hp.name = 'fs_kontroll';
  hp.tabIndex = -1;
  hp.autocomplete = 'off';
  hp.setAttribute('aria-hidden', 'true');
  hp.style.cssText = 'position:absolute;left:-9999px;width:1px;height:1px;opacity:0';
  form.appendChild(hp);

  // Robotskydd 2: biljett fran servern. Servern kraver att den ar minst 3 s gammal vid inskick.
  var MIN_AGE_MS = 3300;
  var MAX_AGE_MS = 23 * 3600 * 1000;
  var ticket = null, ticketAt = 0, ticketReq = null;

  function getTicket() {
    if (ticket && Date.now() - ticketAt < MAX_AGE_MS) return Promise.resolve(ticket);
    if (ticketReq) return ticketReq;
    ticketReq = fetch('/wp-json/fastsan/v1/lead-token?_=' + Date.now(), { cache: 'no-store' })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (j) { ticket = j; ticketAt = Date.now(); ticketReq = null; return j; })
      .catch(function (e) { ticketReq = null; throw e; });
    return ticketReq;
  }

  function ticketReady() {
    return getTicket().then(function (t) {
      var wait = MIN_AGE_MS - (Date.now() - ticketAt);
      if (wait <= 0) return t;
      return new Promise(function (resolve) { setTimeout(function () { resolve(t); }, wait); });
    });
  }

  function warmTicket() { getTicket().catch(function () {}); }
  form.addEventListener('focusin', warmTicket, { once: true });
  form.addEventListener('pointerdown', warmTicket, { once: true });

  function setStatus(kind, html) {
    var el = form.querySelector('.fs-form-status');
    if (!el) {
      el = document.createElement('p');
      el.setAttribute('role', 'status');
      form.insertBefore(el, btn);
    }
    el.className = 'fs-form-status fs-form-status--' + kind;
    el.innerHTML = html;
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (!form.reportValidity()) return;

    var f = new FormData(form);
    var tjanst = String(f.get('tjanst') || '');
    var tjanstLabel = '';
    var sel = document.getElementById('of-tjanst');
    if (sel && sel.selectedIndex > -1) tjanstLabel = sel.options[sel.selectedIndex].text;

    var msgParts = [];
    if (tjanstLabel) msgParts.push('Tj\u00e4nst: ' + tjanstLabel);
    var org = String(f.get('organisation') || '').trim();
    if (org) msgParts.push('F\u00f6retag/BRF: ' + org);
    msgParts.push('');
    msgParts.push(String(f.get('beskrivning') || '').trim());

    var payload = {
      name: String(f.get('namn') || '').trim(),
      email: String(f.get('epost') || '').trim(),
      phone: String(f.get('telefon') || '').trim(),
      message: msgParts.join('\n'),
      object_type: tjanst === 'markmiljo' ? 'mark' : 'unknown',
      urgency: tjanst === 'akut' ? 'akut' : 'normal',
      consent_marketing: !!f.get('marknad'),
      source: 'kontakt-offert'
    };

    if (btn) { btn.disabled = true; btn.textContent = 'Skickar\u2026'; }

    function send(retry) {
      return ticketReady().then(function (t) {
        payload.ft = t.t;
        payload.fs = t.s;
        payload.fs_kontroll = hp.value;
        return fetch('/wp-json/fastsan/v1/lead', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });
      }).then(function (res) {
        // 403 = biljetten godtogs inte (t.ex. for gammal): hamta en ny och forsok en gang till.
        if (res.status === 403 && retry) { ticket = null; return send(false); }
        return res;
      });
    }

    send(true).then(function (res) {
      if (res.status === 201) {
        try {
          if (typeof window.gtag === 'function') {
            window.gtag('event', 'generate_lead', { currency: 'SEK', form_id: 'offert', page_location: location.href });
          }
          if (typeof window.fbq === 'function') {
            window.fbq('track', 'Lead', { content_name: location.pathname });
          }
        } catch (e) {}
        form.innerHTML =
          '<p class="fs-form-status fs-form-status--success" role="status">' +
          '<strong>Tack! Din f\u00f6rfr\u00e5gan \u00e4r mottagen.</strong><br>' +
          'Vi \u00e5terkommer med offert inom 24 timmar. Br\u00e5ttom? Ring ' +
          '<a href="tel:+46701424639">070\u2011142\u00a046\u00a039</a>.</p>';
        form.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
      }
      throw new Error('HTTP ' + res.status);
    }).catch(function () {
      setStatus('error',
        'N\u00e5got gick fel och f\u00f6rfr\u00e5gan skickades inte. ' +
        'F\u00f6rs\u00f6k igen, eller ring <a href="tel:+46701424639">070\u2011142\u00a046\u00a039</a> ' +
        '/ mejla <a href="mailto:daniel.stalbrand@fastsan.se">daniel.stalbrand@fastsan.se</a>.');
      if (btn) { btn.disabled = false; btn.textContent = btnLabel; }
    });
  });
})();
