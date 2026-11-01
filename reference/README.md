# Reference: the React site, as the WordPress plugin must match it

`aria/` holds an accessibility snapshot of every route of the React site, at desktop
(1280 px) and phone (390 px) width: the headings, landmarks, links, buttons and text a
screen reader meets, in order. The plugin's pages are checked against them, so a
difference shows up as a diff rather than a surprise in a screen-reader audit.

Screenshots of every route, in light and dark, go to `screenshots/`. They aren't
committed because they're large; rerun the capture on the same commit to get them.

```sh
npm run build
npm run reference
```

Build without `VITE_BASE_PATH`: the capture serves `dist/` at the root, so a build for
`/firehouse/` would record blank pages. Besides the two captured widths, every page is
measured at 320px, where WCAG's reflow criterion sets the bar; a page wider than its
window at any of them fails the run.

`scripts/capture-reference.mjs` explains what it does. It uses Edge by default; set
`REFERENCE_BROWSER=chrome` to use Chrome.

The snapshots here include the AMS bridge changes: the gov banner and footer back on,
the citation generator off, Submit a Finding pointed at the Google Form, and the topic
pages fitting a phone screen. They are retaken once more when the rerun synthesis
replaces the draft on the topic pages; that set is the reference the plugin is built
against.

The capture also checks that every page fits the window. A page wider than the window
scrolls sideways on a phone, so the script reports it and exits with an error.
