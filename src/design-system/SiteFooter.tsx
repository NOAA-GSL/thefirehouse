import { Link } from 'react-router-dom';
import gslLogo from '../assets/logos/gsl-logo.svg';
import noaaLogo from '../assets/logos/noaa-logo.svg';
import { Icon } from './Icon';
import type { NavItem } from './SiteHeader';
import './SiteFooter.css';

export interface FooterLinkGroup {
  heading: string;
  links: NavItem[];
}

export interface SiteFooterProps {
  siteName: string;
  blurb: string;
  groups: FooterLinkGroup[];
  agencyLine: string;
  usaGovUrl: string;
}

function FooterLink({ item }: { item: NavItem }) {
  if (item.href) {
    const external = /^https?:\/\//.test(item.href);
    return (
      <a
        className="fh-footer__link"
        href={item.href}
        {...(external ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
      >
        {item.label}
      </a>
    );
  }
  return (
    <Link className="fh-footer__link" to={item.to ?? '/'}>
      {item.label}
    </Link>
  );
}

/**
 * Required NOAA/DOC footer: agency attribution, standard federal links, and
 * Firehouse-specific link groups.
 *
 * Link groups come from the content layer rather than being hard-coded, because
 * the federal-resources list is exactly the kind of thing that gets revised
 * without a developer (brief §3: maintainable by Stephanie and Emily).
 */
export function SiteFooter({ siteName, blurb, groups, agencyLine, usaGovUrl }: SiteFooterProps) {
  return (
    <footer className="fh-footer">
      <div className="fh-footer__grid fh-container">
        <div className="fh-footer__identity">
          <div className="fh-footer__lockup">
            <img src={noaaLogo} alt="NOAA" className="fh-footer__logo-noaa" />
            <img src={gslLogo} alt="GSL" className="fh-footer__logo-gsl" />
            <span className="fh-footer__wordmark">{siteName}</span>
          </div>
          <p className="fh-footer__blurb">{blurb}</p>
        </div>

        {groups.map((group) => (
          <nav key={group.heading} className="fh-footer__group" aria-label={group.heading}>
            <h2 className="fh-footer__heading">{group.heading}</h2>
            {group.links.map((link) => (
              <FooterLink key={link.label} item={link} />
            ))}
          </nav>
        ))}
      </div>

      <div className="fh-footer__baseline-wrap">
        <div className="fh-footer__baseline fh-container">
          <span>{agencyLine}</span>
          <a className="fh-footer__usa" href={usaGovUrl} target="_blank" rel="noopener noreferrer">
            usa.gov <Icon name="external-link" size={12} />
          </a>
        </div>
      </div>
    </footer>
  );
}
