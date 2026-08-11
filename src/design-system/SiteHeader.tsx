import { useEffect, useId, useState } from 'react';
import { Link, NavLink, useLocation } from 'react-router-dom';
import gslLogo from '../assets/logos/gsl-logo.svg';
import noaaLogo from '../assets/logos/noaa-logo.svg';
import { Button } from './Button';
import { Icon } from './Icon';
import type { Theme } from '../components/ThemeProvider';
import './SiteHeader.css';

export interface NavItem {
  label: string;
  /** In-app route. */
  to?: string;
  /** External destination (e.g. the Google Form). */
  href?: string;
}

export interface SiteHeaderProps {
  siteName: string;
  links: NavItem[];
  theme: Theme;
  onThemeChange: (theme: Theme) => void;
  submitLabel: string;
  submitUrl: string;
}

/**
 * Primary site navigation: NOAA/GSL identity lockup, wordmark, nav links, theme
 * toggle and the "Submit a Finding" CTA.
 *
 * Two things were added on top of the design system component, both required by the
 * brief (§9.2): a mobile disclosure menu — the source was a single flex row that
 * overflows below ~900px, and the site has to work on a phone at AMS — and an
 * `aria-current` active state instead of an `active` string prop, so the current
 * page is announced rather than only shown as an ember underline.
 */
export function SiteHeader({
  siteName,
  links,
  theme,
  onThemeChange,
  submitLabel,
  submitUrl,
}: SiteHeaderProps) {
  const [menuOpen, setMenuOpen] = useState(false);
  const menuId = useId();
  const { pathname } = useLocation();

  // Close the mobile menu whenever the route changes.
  useEffect(() => setMenuOpen(false), [pathname]);

  useEffect(() => {
    if (!menuOpen) return;
    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') setMenuOpen(false);
    }
    document.addEventListener('keydown', onKeyDown);
    return () => document.removeEventListener('keydown', onKeyDown);
  }, [menuOpen]);

  function navLinkFor(link: NavItem) {
    if (link.href) {
      return (
        <a
          key={link.label}
          className="fh-header__link"
          href={link.href}
          target="_blank"
          rel="noopener noreferrer"
        >
          {link.label}
        </a>
      );
    }

    const to = link.to ?? '/';

    // Links to an anchor within a page ("/#topic-areas") share that page's pathname,
    // so NavLink would mark them active whenever the page is open — three highlighted
    // nav items at once. They get a plain Link and no active state.
    if (to.includes('#')) {
      return (
        <Link key={link.label} className="fh-header__link" to={to}>
          {link.label}
        </Link>
      );
    }

    return (
      <NavLink
        key={link.label}
        to={to}
        end={to === '/'}
        className={({ isActive }) => `fh-header__link${isActive ? ' fh-header__link--active' : ''}`}
      >
        {link.label}
      </NavLink>
    );
  }

  return (
    <header className="fh-header">
      <div className="fh-header__bar fh-container">
        {/* aria-label rather than extra hidden text: the logos are decorative and the
            wordmark is already visible, so a hidden span would just make the link
            announce its own name twice. */}
        <Link
          to="/"
          className="fh-header__lockup"
          aria-label={`${siteName}, NOAA Global Systems Laboratory — home`}
        >
          <img src={noaaLogo} alt="" className="fh-header__logo-noaa" />
          <img src={gslLogo} alt="" className="fh-header__logo-gsl" />
          <span className="fh-header__divider" aria-hidden="true" />
          <span className="fh-header__wordmark">{siteName}</span>
        </Link>

        <nav className="fh-header__nav" aria-label="Primary">
          {links.map((link) => navLinkFor(link))}
        </nav>

        <div className="fh-header__actions">
          <button
            type="button"
            className="fh-header__icon-btn"
            aria-pressed={theme === 'dark'}
            onClick={() => onThemeChange(theme === 'light' ? 'dark' : 'light')}
          >
            <Icon name={theme === 'light' ? 'moon' : 'sun'} size={16} />
            <span className="fh-visually-hidden">
              {theme === 'light' ? 'Switch to dark theme' : 'Switch to light theme'}
            </span>
          </button>

          <span className="fh-header__cta">
            <Button variant="accent" size="sm" iconLeft="plus" href={submitUrl}>
              {submitLabel}
            </Button>
          </span>

          <button
            type="button"
            className="fh-header__icon-btn fh-header__menu-btn"
            aria-expanded={menuOpen}
            aria-controls={menuId}
            onClick={() => setMenuOpen((v) => !v)}
          >
            <Icon name={menuOpen ? 'x' : 'menu'} size={18} />
            <span className="fh-visually-hidden">{menuOpen ? 'Close menu' : 'Open menu'}</span>
          </button>
        </div>
      </div>

      {menuOpen && (
        <div id={menuId} className="fh-header__drawer">
          <nav className="fh-container fh-header__drawer-nav" aria-label="Primary, mobile">
            {/* The submit CTA below already covers the "Submit a Finding" nav item, and
                stacked vertically the two sit next to each other — so it's dropped here.
                On desktop they're far apart and both stay. */}
            {links
              .filter((link) => link.href !== submitUrl)
              .map((link) => navLinkFor(link))}
            <Button variant="accent" size="md" iconLeft="plus" href={submitUrl}>
              {submitLabel}
            </Button>
          </nav>
        </div>
      )}
    </header>
  );
}
