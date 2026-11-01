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

`scripts/capture-reference.mjs` explains what it does. It uses Edge by default; set
`REFERENCE_BROWSER=chrome` to use Chrome.

The snapshots here were taken before the AMS bridge changes (the gov banner and footer
back on, the citation generator off, Submit a Finding pointed at the Google Form), and
are retaken with them. The retaken set is the reference the plugin is built against.
