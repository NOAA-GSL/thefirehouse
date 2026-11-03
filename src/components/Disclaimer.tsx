import './Disclaimer.css';

/**
 * The note that the research on the site is its researchers' own, not NOAA's.
 *
 * Researchers outside NOAA submit too, so a federal site showing their findings says
 * whose they are. One sentence in the site settings ends every page; without it, the
 * page shows nothing here.
 */
export function Disclaimer({ text }: { text?: string }) {
  // A CMS can hand back null or rich text here; neither may blank the page.
  if (typeof text !== 'string' || !text.trim()) return null;
  return (
    <div className="fh-disclaimer fh-container">
      <p className="fh-disclaimer__text">{text}</p>
    </div>
  );
}
