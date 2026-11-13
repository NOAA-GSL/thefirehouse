# Team portraits

One portrait per team member, named after the `photo` field in
`src/content/data/about.json`:

| `photo` value        | file                                       | who                        |
| -------------------- | ------------------------------------------ | -------------------------- |
| `stephanie-hoekstra` | `stephanie-hoekstra.webp`                  | Stephanie, with her child  |
| `emily-wells`        | `emily-wells.webp`                         | Emily, outdoors            |

Both are in place. `AboutPage.tsx` discovers them with `import.meta.glob`, so a
replacement only has to keep the basename — `.webp`, `.jpg`, `.jpeg`, `.png` or
`.avif` is picked up automatically and hashed into the build. If a file goes
missing the card falls back to an initials monogram rather than a broken image.

## How the current files were prepared

From the originals supplied by Stephanie and Emily: centre-cropped from ~3:4 to the
frame's 4:5 (about 3% off the top and bottom, nothing meaningful in either
composition), resized to 640 × 800, and encoded as WebP at quality 82. On 13 November
those 640 × 800 files were resized again, to 336 × 420 at the same quality, the size a
2× screen shows the 168 × 210 frame at: 57 KB for the two instead of 186 KB. For a
replacement, start from the originals rather than these, which have been encoded
twice. Camera metadata was
dropped in the process — both files carry a single `VP8` chunk and no EXIF, XMP, ICC
or GPS. **Keep it that way for any replacement**: these came off a phone, and phone
EXIF can carry the location a photo was taken.

## Notes for whoever replaces them

- **Check the pairing after any swap.** The files are matched to people by filename
  alone, so exchanging them exchanges the photographs on the page with no other
  symptom. Open `/about` and confirm each face sits under the right name.
- Frames are `aspect-ratio: 4 / 5` with `object-fit: cover` and
  `object-position: 50% 30%` — portrait-orientation originals crop with almost no
  loss, and the anchor favours the top of the frame where a face usually sits. If a
  particular photo crops badly, adjust `object-position` on `.fh-person__photo` in
  `AboutPage.css` rather than re-cropping the source.
- Export at 336 × 420. The frame renders at 168 CSS pixels, so that covers a 2×
  display; a 3× phone or browser zoom shows it a little softer, which at this size is
  hard to see. Anything larger is bytes most visitors don't need. On WordPress, upload
  these files as they are: WordPress makes its own smaller copy (its medium size, 240
  wide at the default settings), and the page offers the browser both.
- The photos are decorative in the accessibility sense: the name is rendered
  immediately beside each one, so `about.json` leaves `photoAlt` unset and the page
  emits `alt=""`. Set `photoAlt` only if a portrait starts carrying information the
  name and biography don't.
- A CMS-hosted portrait can override the packaged file at any time via `photoUrl`.

## Before publishing

These are photographs of real people, on a federal site. Confirm both have agreed to
their photo and biography appearing publicly here before the page goes live.
