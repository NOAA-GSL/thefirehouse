#!/usr/bin/env node
/**
 * WCAG 2.1 contrast audit for the glass surfaces.
 *
 *   node scripts/check-contrast.mjs
 *
 * Translucent UI is where accessible colour usually goes wrong: a designer checks
 * text against the *panel* colour, but what the eye receives is the panel composited
 * over whatever is behind it. If the backdrop can be any colour, the delivered
 * contrast is unknowable — which is why this project bounds the backdrop (a fixed
 * atmospheric gradient with known extremes) and then checks text against the
 * WORST-CASE composite rather than against the panel token.
 *
 * Every ratio printed here is computed, not asserted. AA is 4.5:1 for normal text,
 * 3:1 for large text (>=18.66px bold / >=24px) and for UI component boundaries
 * (WCAG 1.4.11 Non-text Contrast).
 */

const hex = (h) => {
  const v = h.replace('#', '');
  const n = v.length === 3 ? v.split('').map((c) => c + c).join('') : v;
  return [0, 2, 4].map((i) => parseInt(n.slice(i, i + 2), 16));
};

/** Composite `over` (with alpha) onto opaque `base`. */
const blend = (base, over, alpha) => base.map((c, i) => Math.round(c * (1 - alpha) + over[i] * alpha));

const channel = (c) => {
  const s = c / 255;
  return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
};

const luminance = ([r, g, b]) => 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);

const ratio = (a, b) => {
  const [l1, l2] = [luminance(a), luminance(b)].sort((x, y) => y - x);
  return (l1 + 0.05) / (l2 + 0.05);
};

const toHex = (rgb) => `#${rgb.map((c) => c.toString(16).padStart(2, '0')).join('')}`;

/* ---------------------------------------------------------------------------
   The bounded backdrop.
   Each theme's page background is a base colour plus fixed radial glows. The
   "hot" variant is the most-saturated point of that gradient — the worst case a
   glass panel can be asked to sit on.
--------------------------------------------------------------------------- */

const BACKDROP = {
  light: {
    base: hex('#f7f8fa'),
    glows: [
      [hex('#ffc500'), 0.10], // amber, top-right
      [hex('#f2762e'), 0.08], // ember, lower-left
    ],
  },
  dark: {
    base: hex('#0a1020'),
    glows: [
      [hex('#c21500'), 0.18],
      [hex('#ff9000'), 0.08],
    ],
  },
};

function hottest(theme) {
  return BACKDROP[theme].glows.reduce((acc, [colour, alpha]) => blend(acc, colour, alpha), BACKDROP[theme].base);
}

/* Glass panel definitions: [overlay colour, alpha]. */
const GLASS = {
  light: {
    panel: [hex('#ffffff'), 0.78],
    raised: [hex('#ffffff'), 0.9],
    sunken: [hex('#ffffff'), 0.55],
  },
  dark: {
    panel: [hex('#1b2e56'), 0.72],
    // navy-800 rather than navy-700: at navy-700 the composite lifted far enough
    // that tertiary text landed at 4.31:1. Darkening the panel was the right fix —
    // lightening the text would have flattened the three-step text hierarchy.
    raised: [hex('#1b2e56'), 0.82],
    sunken: [hex('#0a1020'), 0.55],
  },
};

const TEXT = {
  light: {
    primary: hex('#0a1020'),
    secondary: hex('#545c6c'),
    tertiary: hex('#616a7a'),
    link: hex('#0a4595'),
  },
  dark: {
    primary: hex('#f5f7fb'),
    secondary: hex('#c0c6d0'),
    tertiary: hex('#98a0af'),
    link: hex('#b3e1ff'),
  },
};

/**
 * Two kinds of edge, held to two different bars — the distinction WCAG 1.4.11
 * actually draws, and the one most "glass" implementations miss.
 *
 * `decorative` is the sheen along the top of a panel. It is not required to
 * identify anything: the panel is already distinguished from the page by its own
 * background shift, so this edge carries no contrast obligation and is free to be
 * the near-invisible highlight that makes glass read as glass.
 *
 * `interactive` is the boundary of a *control* — a chip, a button, a field. There
 * the edge is what tells you the control exists and where it ends, so it owes the
 * full 3:1 against whatever it sits on.
 */
const BORDERS = {
  light: { interactive: hex('#717a8a'), focus: hex('#0284c7') },
  dark: { interactive: hex('#6e85af'), focus: hex('#38bdf8') },
};

let failures = 0;

function check(label, fg, bg, min) {
  const r = ratio(fg, bg);
  const pass = r >= min;
  if (!pass) failures += 1;
  const mark = pass ? 'PASS' : 'FAIL';
  console.log(
    `  ${mark}  ${r.toFixed(2).padStart(6)}:1  (need ${min})  ${label}  ${toHex(fg)} on ${toHex(bg)}`,
  );
}

for (const theme of ['light', 'dark']) {
  console.log(`\n=== ${theme.toUpperCase()} — text on glass, over the hottest backdrop point ===`);
  const backdrop = hottest(theme);
  console.log(`  backdrop worst case: ${toHex(backdrop)}`);

  for (const [surface, [colour, alpha]] of Object.entries(GLASS[theme])) {
    const composite = blend(backdrop, colour, alpha);
    console.log(`\n  -- glass.${surface} => ${toHex(composite)}`);
    check(`${surface} / text-primary`, TEXT[theme].primary, composite, 4.5);
    check(`${surface} / text-secondary`, TEXT[theme].secondary, composite, 4.5);
    check(`${surface} / text-tertiary`, TEXT[theme].tertiary, composite, 4.5);
    check(`${surface} / link`, TEXT[theme].link, composite, 4.5);
    check(`${surface} / control border (1.4.11)`, BORDERS[theme].interactive, composite, 3);
    check(`${surface} / focus ring (1.4.11)`, BORDERS[theme].focus, composite, 3);
  }

  // Also check the plain page backdrop, which body text sits on directly.
  console.log(`\n  -- bare backdrop ${toHex(backdrop)}`);
  check('backdrop / text-primary', TEXT[theme].primary, backdrop, 4.5);
  check('backdrop / text-secondary', TEXT[theme].secondary, backdrop, 4.5);
  check('backdrop / text-tertiary', TEXT[theme].tertiary, backdrop, 4.5);
}

/* ---------------------------------------------------------------------------
   The hero.
   Its own bounded backdrop: an ember/amber gradient under a darkening scrim, with
   a dark glass panel holding the copy. Checked separately because the hero is the
   one place white text sits over saturated orange, which is exactly the
   combination that looks fine in a mockup and fails an audit.
--------------------------------------------------------------------------- */

console.log('\n=== HERO — copy panel over the hottest part of the fire glow ===');
{
  // Worst case: the brightest point of the amber bloom, under the weakest part of
  // the scrim that still overlaps the copy area.
  let bg = hex('#1b2e56');
  bg = blend(bg, hex('#c21500'), 0.55);
  bg = blend(bg, hex('#ff9000'), 0.5);
  console.log(`  fire glow at its brightest: ${toHex(bg)}`);

  const scrimmed = blend(bg, hex('#0a1020'), 0.55);
  console.log(`  after the scrim:            ${toHex(scrimmed)}`);

  const panel = blend(scrimmed, hex('#0a1020'), 0.55);
  console.log(`  under the glass copy panel: ${toHex(panel)}`);

  check('hero / white heading (large)', hex('#ffffff'), panel, 3);
  check('hero / white body', hex('#ffffff'), panel, 4.5);
  check('hero / body at 88% alpha', blend(panel, hex('#ffffff'), 0.88), panel, 4.5);
  check('hero / ember-200 eyebrow', hex('#ffc7a3'), panel, 4.5);
  // Without the glass panel — the fallback state when transparency is reduced.
  check('hero / white body, no panel', hex('#ffffff'), scrimmed, 4.5);
  check('hero / ember-200 eyebrow, no panel', hex('#ffc7a3'), scrimmed, 4.5);
}

/* ---------------------------------------------------------------------------
   Accent fills, both themes. The "Submit a Finding" button is the single most
   important control on the site and its label sits on ember.
--------------------------------------------------------------------------- */

console.log('\n=== ACCENT FILLS ===');
check('light / white on ember-500', hex('#ffffff'), hex('#c21500'), 4.5);
check('dark  / navy-950 on ember-400', hex('#0a1020'), hex('#f2762e'), 4.5);
check('light / ember-500 as text on glass panel', hex('#c21500'), hex('#fdfaf5'), 4.5);
check('dark  / ember-300 as text on glass panel', hex('#ff9e63'), hex('#242945'), 4.5);

console.log(
  failures === 0
    ? '\nAll checks pass.\n'
    : `\n${failures} check(s) FAILED — fix the token, not the test.\n`,
);
process.exit(failures === 0 ? 0 : 1);
