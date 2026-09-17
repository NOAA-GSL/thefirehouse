import markOnDark from '../assets/logos/firehouse-mark-on-dark.png';
import mark from '../assets/logos/firehouse-mark.png';
import wordmarkOnDark from '../assets/logos/firehouse-wordmark-on-dark.png';
import wordmark from '../assets/logos/firehouse-wordmark.png';
import './BrandMark.css';

export interface BrandMarkProps {
  /**
   * `mark` is the isolated house-and-flame; `wordmark` is the full combination mark
   * with "FireHouse / Fire Weather Research Hub".
   */
  variant?: 'mark' | 'wordmark';
  /** Rendered height in CSS pixels. Width follows the artwork. */
  height: number;
  /**
   * `auto` follows the page theme. `dark` forces the light-stroked artwork for a
   * surface that is dark in both themes, such as the landing hero.
   */
  surface?: 'auto' | 'dark';
  /** Empty (the default) marks it decorative — right wherever the name is also text. */
  alt?: string;
  className?: string;
}

/**
 * The FireHouse logo.
 *
 * The artwork's outline and "House" lettering are dark slate, which disappears on
 * the dark theme. Each variant therefore ships twice — as supplied, and with the
 * slate recoloured light (the `-on-dark` files) — and CSS picks one from
 * `[data-theme]`. Switching in CSS rather than from React state means the right
 * image is on screen from first paint, with no flash on a dark page.
 */
export function BrandMark({
  variant = 'mark',
  height,
  surface = 'auto',
  alt = '',
  className = '',
}: BrandMarkProps) {
  const light = variant === 'mark' ? mark : wordmark;
  const dark = variant === 'mark' ? markOnDark : wordmarkOnDark;
  const classes = `fh-brand fh-brand--${surface} ${className}`.trim();

  if (surface === 'dark') {
    return <img className={classes} src={dark} alt={alt} style={{ height }} />;
  }

  return (
    <span className={classes} style={{ height }}>
      <img className="fh-brand__img fh-brand__img--light" src={light} alt={alt} />
      {/* Only one of the pair is ever displayed; the hidden one is also hidden from
          assistive tech so the name isn't announced twice. */}
      <img className="fh-brand__img fh-brand__img--dark" src={dark} alt={alt} aria-hidden="true" />
    </span>
  );
}
