# Hero imagery

## `fire-weather-hero.png` — not in this repo yet

The landing page hero expects the fire weather photo from the design system:

```
_ds/the-firehouse-design-system-a05f43c6-bfb5-47a4-b052-1f16fdeac42a/assets/imagery/fire-weather-hero.png
```

It is a binary asset in the Claude Design project and could not be transferred over
the design-sync API, which returns text. Export it from the design project (or from
the source `FWT.fig` file it came from) and drop it in **this directory** — no code
change is needed. `LandingPage.tsx` discovers it with `import.meta.glob`, so any of
`fire-weather-hero.png`, `.jpg`, `.webp` or `.avif` is picked up automatically and
hashed into the build.

Until then the hero renders a layered ember/smoke gradient standing in for the photo,
under the same dark scrim the design specifies. It is intentionally a credible-looking
fallback rather than a grey box, so the page can be shown to stakeholders as-is — but
it is not the approved design and should not ship to AMS.

## Notes for whoever adds it

- The scrim (`linear-gradient(180deg, rgba(10,16,32,0.15) → rgba(10,16,32,0.92))`) is
  applied by `LandingPage.css`. Do not bake a darkening pass into the file itself, or
  the text contrast will overshoot.
- The image is decorative: `landing.json` sets `hero.imageAlt` to an empty string on
  purpose, because the heading and body already carry everything it conveys. Give it
  real alt text only if the photo starts carrying information of its own.
- Export at 2400px wide or more — the hero is full-bleed up to a 1440px viewport and
  is `object-fit: cover`.
- A CMS-hosted image can override the packaged one at any time via `hero.imageUrl`.
