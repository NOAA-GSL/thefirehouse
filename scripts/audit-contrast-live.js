/**
 * Live contrast audit — paste into the browser console on any page of the site.
 *
 *   fhAudit()                  audit the whole page in the current theme
 *   fhAudit('.fh-modal')       audit one region
 *   await fhAuditBothThemes()  audit light and dark in one pass
 *
 * ---------------------------------------------------------------------------
 * Why this exists alongside scripts/check-contrast.mjs
 * ---------------------------------------------------------------------------
 * The .mjs script checks the *token system* — it proves the palette is sound
 * against a modelled worst-case backdrop, and it runs in CI without a browser.
 * This one checks the *delivered page*: it reads computed styles off real DOM
 * nodes, so it catches the failures a token audit cannot — a cascade bug, a
 * hardcoded colour in a component, a translucent ancestor nobody accounted for.
 *
 * A real example: the token audit passed while dark-mode caption text was being
 * painted #616A7A at 2.65:1 across the entire site, because a light-mode override
 * was leaking through the cascade. Only the live walk found it.
 *
 * ---------------------------------------------------------------------------
 * Known limitation — read this before trusting a failure
 * ---------------------------------------------------------------------------
 * Backdrops are resolved by walking ANCESTOR background colours. Where a backdrop
 * is painted by a positioned *sibling* — the hero's gradient layer is the one case
 * on this site — the walker cannot see it and will report a false failure. To check
 * such a region, pin it to its worst-case colour first:
 *
 *   document.querySelector('.fh-hero').style.background = '#5a301b';
 *   document.querySelector('.fh-hero__media').style.display = 'none';
 *   fhAudit('.fh-hero');
 *
 * Thresholds are WCAG 2.1 AA: 4.5:1 normal text, 3:1 large (>=24px, or >=18.66px
 * bold). Non-text/UI contrast (1.4.11) is not covered here — see the .mjs script.
 */

(() => {
  const parse = (c) => {
    const m = (c.match(/[\d.]+/g) || [0, 0, 0]).map(Number);
    return m.length === 4 ? m : [...m, 1];
  };
  const ch = (c) => {
    const s = c / 255;
    return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
  };
  const lum = ([r, g, b]) => 0.2126 * ch(r) + 0.7152 * ch(g) + 0.0722 * ch(b);
  const over = (fg, bg) => {
    const a = fg[3];
    return [0, 1, 2].map((i) => fg[i] * a + bg[i] * (1 - a));
  };

  function backdropOf(el) {
    const root = parse(getComputedStyle(document.body).backgroundColor).slice(0, 3).concat(1);
    const stack = [];
    for (let n = el; n && n !== document.documentElement; n = n.parentElement) {
      const c = parse(getComputedStyle(n).backgroundColor);
      if (c[3] > 0) stack.push(c);
    }
    stack.push(root);
    let out = stack[stack.length - 1];
    for (let i = stack.length - 2; i >= 0; i--) out = [...over(stack[i], out), 1];
    return out;
  }

  window.fhAudit = function fhAudit(scope = 'body') {
    const failures = [];
    let checked = 0;

    document.querySelectorAll(`${scope} *`).forEach((el) => {
      const text = [...el.childNodes]
        .filter((n) => n.nodeType === 3 && n.textContent.trim())
        .map((n) => n.textContent.trim())
        .join(' ');
      if (!text) return;

      const cs = getComputedStyle(el);
      if (cs.visibility === 'hidden' || cs.display === 'none' || +cs.opacity === 0) return;
      const box = el.getBoundingClientRect();
      if (box.width < 1 || box.height < 1) return;

      checked += 1;
      const fg = over(parse(cs.color), backdropOf(el.parentElement || document.body));
      const bg = backdropOf(el);
      const [hi, lo] = [lum(fg), lum(bg)].sort((a, b) => b - a);
      const ratio = (hi + 0.05) / (lo + 0.05);

      const px = parseFloat(cs.fontSize);
      const weight = +cs.fontWeight || 400;
      const isLarge = px >= 24 || (px >= 18.66 && weight >= 700);
      const need = isLarge ? 3 : 4.5;

      if (ratio < need) {
        failures.push({
          text: text.slice(0, 48),
          ratio: +ratio.toFixed(2),
          need,
          size: `${px}px/${weight}`,
          selector: el.tagName.toLowerCase() + (el.className ? `.${el.className.toString().split(' ')[0]}` : ''),
        });
      }
    });

    const theme = document.documentElement.dataset.theme || 'system';
    if (failures.length) {
      console.warn(`[fhAudit] ${theme}: ${failures.length} of ${checked} text nodes below AA`);
      console.table(failures);
    } else {
      console.log(`%c[fhAudit] ${theme}: all ${checked} text nodes pass AA`, 'color:#15803d;font-weight:600');
    }
    return failures;
  };

  window.fhAuditBothThemes = async function fhAuditBothThemes(scope = 'body') {
    const original = document.documentElement.dataset.theme;
    const out = {};
    for (const theme of ['light', 'dark']) {
      document.documentElement.dataset.theme = theme;

      // Let the token swap actually land before reading computed styles back.
      //
      // This is not belt-and-braces — auditing in the same tick as the swap reads
      // stale custom properties and reports numbers that are simply wrong, in
      // *either* direction. A false pass is the dangerous one: it is how a
      // contrast bug gets signed off. Plain `setTimeout` rather than
      // `requestAnimationFrame`, because rAF never fires in a background or
      // hidden tab, which would hang an audit run headlessly.
      await new Promise((r) => setTimeout(r, 300));

      out[theme] = fhAudit(scope);
    }
    if (original) document.documentElement.dataset.theme = original;
    return out;
  };

  console.log('%c[fhAudit] ready — run fhAudit() or await fhAuditBothThemes()', 'color:#c21500;font-weight:600');
})();
