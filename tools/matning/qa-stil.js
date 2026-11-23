/*
 * qa-stil.js – jämför utseendet före och efter en ändring i stilmallarna, utan bilder.
 * För varje sida och bredd läses den uträknade stilen (färg, typsnitt, mått, läge) för varje element i
 * sidhuvudet, sidfoten, kontaktfältet och artikelhuvudet. Listan sparas i webbläsarens localStorage.
 *   window.__stil('fore')   -> sparar läget före ändringen, svarar med antal element per sida
 *   window.__stil('efter')  -> räknar om och jämför mot det sparade: antal element som skiljer, och de fem första
 * Körs i sajtens flik via Chrome-bryggan (call{js}). Sidorna laddas i en iframe med ?qs=<tid> så att inget tas ur cache.
 */
window.__stil = async function (lage, sidor, bredder) {
  sidor = sidor || ['/', '/kontakt/', '/insikter/', '/asbest-2025-nya-gransvardet/', '/provtagning/asbest/'];
  bredder = bredder || [1280, 390];
  const P = ['display', 'position', 'color', 'backgroundColor', 'fontFamily', 'fontSize', 'fontWeight', 'lineHeight', 'textDecorationLine',
    'marginTop', 'marginBottom', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'borderTopWidth', 'borderBottomWidth',
    'borderRadius', 'opacity', 'zIndex', 'gap', 'justifyContent', 'alignItems', 'textAlign', 'textTransform', 'letterSpacing', 'boxShadow'];
  const sleep = ms => new Promise(r => setTimeout(r, ms));
  const nu = {};
  for (const u of sidor) {
    for (const W of bredder) {
      const f = document.createElement('iframe');
      f.style.cssText = 'position:fixed;left:0;top:0;width:' + W + 'px;height:900px;border:0;opacity:0;pointer-events:none';
      f.src = u + '?qs=' + Date.now();
      document.body.appendChild(f);
      await new Promise(r => { f.onload = r; setTimeout(r, 9000); });
      await sleep(900);
      const d = f.contentDocument, w = f.contentWindow;
      const st = d.createElement('style'); st.textContent = 'html{scrollbar-width:none}'; d.head.appendChild(st);
      await sleep(150);
      const rows = [];
      const els = d.querySelectorAll('.fs-akut-strip, .fs-akut-strip *, .fs-header, .fs-header *, .fs-footer, .fs-footer *, .fs-sticky-bar, .fs-sticky-bar *, [class*="fs-article"], [class*="fs-article"] *, [class*="fs-insikter"], [class*="fs-insikter"] *');
      let i = 0;
      for (const e of els) {
        if (e.tagName === 'STYLE' || e.tagName === 'SCRIPT' || e.closest('svg') && e.tagName !== 'svg') continue;
        const cs = w.getComputedStyle(e), r = e.getBoundingClientRect();
        rows.push((i++) + ' ' + e.tagName.toLowerCase() + '.' + (typeof e.className === 'string' ? e.className.split(' ')[0] : '') + '|' +
          P.map(p => cs[p]).join(';') + '|' + [r.left, r.top + w.scrollY, r.width, r.height].map(Math.round).join(','));
      }
      nu[u + '@' + W] = rows;
      f.remove();
    }
  }
  if (lage === 'fore') {
    localStorage.setItem('qa_stil_fore', JSON.stringify(nu));
    return JSON.stringify({ sparat: Object.fromEntries(Object.entries(nu).map(([k, v]) => [k, v.length])) });
  }
  const fore = JSON.parse(localStorage.getItem('qa_stil_fore') || '{}');
  const out = { element: 0, skiljer: 0, per_sida: {}, exempel: [] };
  for (const k of Object.keys(nu)) {
    const a = fore[k] || [], b = nu[k];
    let n = Math.abs(a.length - b.length);
    for (let j = 0; j < Math.min(a.length, b.length); j++) {
      if (a[j] !== b[j]) {
        n++;
        if (out.exempel.length < 5) {
          const [ia, sa, ra] = a[j].split('|'), [ib, sb, rb] = b[j].split('|');
          const pa = sa.split(';'), pb = sb.split(';');
          const diff = P.map((p, x) => pa[x] !== pb[x] ? p + ': ' + pa[x] + ' -> ' + pb[x] : null).filter(Boolean).slice(0, 3);
          if (ra !== rb) diff.push('ruta: ' + ra + ' -> ' + rb);
          out.exempel.push(k + ' ' + ia + (ia !== ib ? ' (nu ' + ib + ')' : '') + ' :: ' + diff.join(' ; '));
        }
      }
    }
    out.element += b.length;
    out.skiljer += n;
    if (n) out.per_sida[k] = n + ' av ' + b.length + (a.length !== b.length ? ' (antal ' + a.length + ' -> ' + b.length + ')' : '');
  }
  return JSON.stringify(out);
};
