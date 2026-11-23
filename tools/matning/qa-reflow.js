/*
 * qa-reflow.js – mater om sajtens sidor ryms pa en given bredd, med och utan okat textavstand (WCAG 1.4.10, 1.4.12).
 * Kors i sajtens egen flik (Chrome-bryggan, call{js}): varje sida i wp-sitemap.xml laddas i en iframe med bredden W.
 * Anvandning, tre slags steg:
 *   1. hamta filen och kor den:  window.__qinit(320, '')          -> antal sidor   (andra argumentet: prov-CSS som laggs in i varje sida)
 *   2. mat fem sidor at gangen:  window.__q.run(0,5) ... (25,30)   -> antal matta   (ett steg far ta hogst 30 s)
 *   3. hamta resultatet:         window.__qresult()                -> JSON
 * Raknas inte som fel: CookieYes egna element (.cky-*), element som ar eller ligger i position:fixed (mobilmenyn
 * utanfor skarmen), skarmlasartext (1 px) och det som ligger i en behallare som sjalv ryms och har egen rullning
 * eller klippning. Rullningslisten doljs i iframen, sa bredden W ar hela layoutbredden, som pa en telefon.
 * Resultatet tar bara med sidor som ar for breda (sw = sidans bredd utan, sw2 = med okat textavstand).
 */
window.__qinit = async function (W, EXTRA) {
  const sleep = ms => new Promise(r => setTimeout(r, ms));
  const xml = async u => { const t = await (await fetch(u)).text(); return [...t.matchAll(/<loc>([^<]+)<\/loc>/g)].map(m => m[1]); };
  let urls = [];
  for (const s of await xml('/wp-sitemap.xml')) urls = urls.concat(await xml(s));
  urls = [...new Set(urls)];
  const clipped = (el, doc) => {
    let p = el.parentElement;
    while (p && p !== doc.documentElement && p !== doc.body) {
      if (doc.defaultView.getComputedStyle(p).overflowX !== 'visible') {
        const r = p.getBoundingClientRect();
        if (r.right <= W + 0.5 && r.left >= -0.5) return true;
      }
      p = p.parentElement;
    }
    return false;
  };
  const infixed = (el, doc) => {
    for (let p = el; p && p !== doc.documentElement; p = p.parentElement) {
      if (doc.defaultView.getComputedStyle(p).position === 'fixed') return true;
    }
    return false;
  };
  const name = el => el.tagName.toLowerCase() + (typeof el.className === 'string' && el.className ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : '');
  const scan = doc => {
    const bad = [];
    for (const el of doc.body.querySelectorAll('*')) {
      if (el.closest('[class*="cky-"]')) continue;
      const cs = doc.defaultView.getComputedStyle(el);
      if (cs.display === 'none' || cs.visibility === 'hidden') continue;
      const r = el.getBoundingClientRect();
      if (r.width <= 1 || r.height <= 1) continue;
      if (infixed(el, doc)) continue;
      if ((r.right > W + 0.5 || r.left < -0.5) && !clipped(el, doc)) bad.push(el);
    }
    const leaf = bad.filter(el => ![...el.children].some(c => bad.includes(c)));
    const rows = leaf.slice(0, 3).map(el => {
      const r = el.getBoundingClientRect();
      return (el.parentElement ? name(el.parentElement) + '>' : '') + name(el) + ' r=' + Math.round(r.right) + ' w=' + Math.round(r.width) +
        ' "' + (el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 30) + '"';
    });
    // Text som sticker ut ur sin ruta (ett langt ord som inte far brytas): rutan ryms, men texten gor det inte.
    const tw = doc.createTreeWalker(doc.body, NodeFilter.SHOW_TEXT);
    const seen = new Set();
    for (let n = tw.nextNode(); n; n = tw.nextNode()) {
      if (!n.nodeValue.trim()) continue;
      const el = n.parentElement;
      if (!el || seen.has(el) || el.closest('[class*="cky-"]')) continue;
      const cs = doc.defaultView.getComputedStyle(el);
      if (cs.display === 'none' || cs.visibility === 'hidden') continue;
      const rg = doc.createRange();
      rg.selectNodeContents(n);
      const r = rg.getBoundingClientRect();
      if (r.width <= 1 || r.right <= W + 0.5) continue;
      if (infixed(el, doc) || clipped(el, doc) || cs.overflowX !== 'visible') continue;
      seen.add(el);
      const ord = n.nodeValue.trim().split(/\s+/).sort((x, y) => y.length - x.length)[0];
      if (rows.length < 6) rows.push('TEXT ' + name(el) + ' r=' + Math.round(r.right) + ' fs=' + parseFloat(cs.fontSize) + ' "' + ord.slice(0, 34) + '"');
    }
    return { n: bad.length + seen.size, leaf: rows };
  };
  window.__q = {
    W, urls, out: [],
    run: async (a, b) => {
      for (const u of urls.slice(a, b)) {
        const f = document.createElement('iframe');
        f.style.cssText = 'position:fixed;left:0;top:0;width:' + W + 'px;height:700px;border:0;opacity:0;pointer-events:none';
        f.src = u;
        document.body.appendChild(f);
        await new Promise(r => { f.onload = r; setTimeout(r, 9000); });
        await sleep(500);
        const rec = { u: new URL(u).pathname };
        try {
          const doc = f.contentDocument;
          const e = doc.createElement('style');
          e.textContent = 'html{scrollbar-width:none}' + (EXTRA || '');
          doc.head.appendChild(e);
          await sleep(200);
          rec.sw = doc.documentElement.scrollWidth;
          const a1 = scan(doc);
          const st = doc.createElement('style');
          st.textContent = '*{line-height:1.5!important;letter-spacing:.12em!important;word-spacing:.16em!important}p{margin-bottom:2em!important}';
          doc.head.appendChild(st);
          await sleep(200);
          rec.sw2 = doc.documentElement.scrollWidth;
          const b1 = scan(doc);
          if (rec.sw > W) rec.a = a1.leaf;
          if (rec.sw2 > W) rec.b = b1.leaf.filter(x => !a1.leaf.includes(x));
        } catch (e) { rec.err = String(e); }
        f.remove();
        window.__q.out.push(rec);
      }
      return window.__q.out.length;
    }
  };
  return urls.length;
};
window.__qresult = function () {
  const o = window.__q.out, W = window.__q.W;
  return JSON.stringify({
    bredd: W, sidor: o.length,
    for_breda: o.filter(r => r.sw > W).length,
    for_breda_textavstand: o.filter(r => r.sw2 > W).length,
    rader: o.filter(r => r.sw > W || r.sw2 > W || r.err),
    ryms: o.filter(r => r.sw <= W && r.sw2 <= W && !r.err).length
  });
};
