import type { CSSProperties, MouseEventHandler, ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { Icon, type IconName } from './Icon';
import './Button.css';

export type ButtonVariant = 'primary' | 'secondary' | 'accent' | 'ghost' | 'link';
export type ButtonSize = 'sm' | 'md' | 'lg';

const ICON_SIZE: Record<ButtonSize, number> = { sm: 14, md: 16, lg: 18 };

interface BaseProps {
  children?: ReactNode;
  variant?: ButtonVariant;
  size?: ButtonSize;
  iconLeft?: IconName;
  iconRight?: IconName;
  disabled?: boolean;
  className?: string;
  style?: CSSProperties;
  /** Applies the light-on-photography treatment used by the hero's secondary CTA. */
  onDark?: boolean;
  /** Full width below 480px — used for stacked CTA pairs. */
  blockOnMobile?: boolean;
}

interface ButtonAsButton extends BaseProps {
  href?: undefined;
  to?: undefined;
  type?: 'button' | 'submit' | 'reset';
  onClick?: MouseEventHandler<HTMLButtonElement>;
}

/** External / absolute destinations, e.g. the Google Form submission link. */
interface ButtonAsAnchor extends BaseProps {
  href: string;
  to?: undefined;
  /** Set for links that leave the site; adds rel and a visible cue is expected in copy. */
  external?: boolean;
}

/** In-app routes, rendered through React Router so navigation stays client-side. */
interface ButtonAsLink extends BaseProps {
  to: string;
  href?: undefined;
}

export type ButtonProps = ButtonAsButton | ButtonAsAnchor | ButtonAsLink;

function classes({
  variant = 'primary',
  size = 'md',
  onDark,
  blockOnMobile,
  className,
}: BaseProps & { variant?: ButtonVariant; size?: ButtonSize }) {
  return [
    'fh-btn',
    `fh-btn--${variant}`,
    `fh-btn--${size}`,
    onDark ? 'fh-btn--on-dark' : '',
    blockOnMobile ? 'fh-btn--block-mobile' : '',
    className ?? '',
  ]
    .filter(Boolean)
    .join(' ');
}

/**
 * Primary, secondary, ghost, link and accent (CTA) button styles with icon slots.
 *
 * Renders a `<button>`, a router `<Link>` (`to`), or an `<a>` (`href`) depending on
 * what it's for. That matters here: "Submit a Finding" points at an off-site Google
 * Form and must be a real link — keyboard users, screen readers and middle-click all
 * expect link semantics, and a `<button>` with an onClick would break all three.
 */
export function Button(props: ButtonProps) {
  const { children, variant = 'primary', size = 'md', iconLeft, iconRight, disabled, style } = props;

  const content = (
    <>
      {iconLeft && <Icon name={iconLeft} size={ICON_SIZE[size]} />}
      {children}
      {iconRight && <Icon name={iconRight} size={ICON_SIZE[size]} />}
    </>
  );

  const className = classes({ ...props, variant, size });

  if ('to' in props && props.to !== undefined) {
    return (
      <Link to={props.to} className={className} style={style} aria-disabled={disabled || undefined}>
        {content}
      </Link>
    );
  }

  if ('href' in props && props.href !== undefined) {
    const external = props.external ?? /^https?:\/\//.test(props.href);
    return (
      <a
        href={props.href}
        className={className}
        style={style}
        aria-disabled={disabled || undefined}
        {...(external ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
      >
        {content}
      </a>
    );
  }

  const { type = 'button', onClick } = props as ButtonAsButton;
  return (
    <button type={type} className={className} style={style} disabled={disabled} onClick={onClick}>
      {content}
    </button>
  );
}
