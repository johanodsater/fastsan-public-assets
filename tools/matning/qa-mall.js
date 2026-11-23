/*
 * qa-mall.js – kontrollerar mallfelen i QA-planen punkt 7–9 pa alla sidor i wp-sitemap.xml.
 * Kors i sajtens flik (Chrome-bryggan, call{js}):
 *   window.__minit(1280) -> antal sidor;  window.__m.run(0,6) ... -> antal matta;  window.__mresult() -> JSON
 * Per sida: svarskod, header i header / footer i footer, meta charset i body, antal h1, hopp i rubriknivaerna
 * i main, bilder i main utan width/height eller utan srcset, antal ikonlankar, color-scheme, elementor i
 * body-klassen, vagrat rullning. Resultatet tar bara med sidor som har nagon anmarkning.
 */
window.__minit = async function (W) {
  const sleep = ms => new Promise(r => setTimeout(r, ms));
  const xml = async u => { const t = await (await fetch(u)).text(); return [...t.matchAll(/<loc>([^<]+)<\/loc>/g)].map(m => m[1]); };
  let urls = [];
  for (const s of await xml('/wp-sitemap.xml')) urls = urls.concat(await xml(s));
  urls = [...new Set(urls)];
  window.__m = {
    W, urls, out: [],
    run: async (a, b) => {
      for (const u of urls.slice(a, b)) {
        const rec = { u: new URL(u).pathname };
        try { rec.st = (await fetch(u, { cache: 'no-store' })).status; } catch (e) { rec.st = 0; }
        const f = document.createElement('iframe');
        f.style.cssText = 'position:fixed;left:0;top:0;width:' + W + 'px;height:800px;border:0;opacity:0;pointer-events:none';
        f.src = u;
        document.body.appendChild(f);
        await new Promise(r => { f.onload = r; setTimeout(r, 9000); });
        await sleep(400);
        try {
          const d = f.contentDocument, main = d.querySelector('main') || d.body;
          rec.nast = d.querySelectorAll('header header, footer footer').length;
          rec.charset_body = d.body.querySelectorAll('meta[charset]').length;
          rec.h1 = [...d.querySelectorAll('h1')].filter(h => !h.closest('[class*="cky-"]')).length;
          let prev = 0, hopp = 0;
          for (const h of main.querySelectorAll('h1,h2,h3,h4,h5,h6')) { const n = +h.tagName[1]; if (prev && n > prev + 1) hopp++; prev = n; }
          rec.hopp = hopp;
          const imgs = [...main.querySelectorAll('img')].filter(i => !/^data:|\.svg(\?|$)/.test(i.currentSrc || i.src));
          rec.img_utan_matt = imgs.filter(i => !i.getAttribute('width') || !i.getAttribute('height')).map(i => (i.currentSrc || i.src).split('/').pop().slice(0, 40));
          rec.img_utan_srcset = imgs.filter(i => !i.getAttribute('srcset')).map(i => (i.currentSrc || i.src).split('/').pop().slice(0, 40));
          rec.ikoner = d.head.querySelectorAll('link[rel="icon"]').length;
          rec.color_scheme = d.head.querySelectorAll('meta[name="color-scheme"]').length;
          rec.elementor = /elementor/.test(d.body.className) ? 1 : 0;
          rec.bred = d.documentElement.scrollWidth > d.documentElement.clientWidth ? 1 : 0;
        } catch (e) { rec.err = String(e); }
        f.remove();
        window.__m.out.push(rec);
      }
      return window.__m.out.length;
    }
  };
  return urls.length;
};
window.__mresult = function () {
  const o = window.__m.out;
  const anm = r => r.err || r.st !== 200 || r.nast || r.charset_body || r.h1 !== 1 || r.hopp || r.img_utan_matt.length || r.img_utan_srcset.length || r.ikoner !== 3 || r.color_scheme !== 1 || r.elementor || r.bred;
  const sum = k => o.reduce((s, r) => s + (Array.isArray(r[k]) ? r[k].length : (r[k] || 0)), 0);
  return JSON.stringify({
    sidor: o.length, svar_200: o.filter(r => r.st === 200).length,
    nast: sum('nast'), charset_body: sum('charset_body'), sidor_med_ett_h1: o.filter(r => r.h1 === 1).length,
    rubrikhopp: sum('hopp'), img_utan_matt: sum('img_utan_matt'), img_utan_srcset: sum('img_utan_srcset'),
    tre_ikoner: o.filter(r => r.ikoner === 3).length, color_scheme: sum('color_scheme'), elementor: sum('elementor'), bred: sum('bred'),
    rader: o.filter(anm).map(r => { const x = { u: r.u }; for (const k in r) { const v = r[k]; if (k !== 'u' && (Array.isArray(v) ? v.length : (k === 'st' ? v !== 200 : (k === 'h1' ? v !== 1 : (k === 'ikoner' ? v !== 3 : (k === 'color_scheme' ? v !== 1 : v)))))) x[k] = v; } return x; })
  });
};
