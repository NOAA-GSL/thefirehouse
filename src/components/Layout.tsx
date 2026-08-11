import type { ReactNode } from 'react';
import { GovBanner, SiteFooter, SiteHeader } from '../design-system';
import { useContent } from '../content/ContentProvider';
import { useTheme } from './ThemeProvider';

/**
 * Site shell: federal banner, header, main landmark, footer.
 *
 * The skip link is the first focusable thing on the page — a Section 508 baseline,
 * and a practical one here, because the header carries nine links before the content
 * starts.
 */
export function Layout({ children }: { children: ReactNode }) {
  const { settings } = useContent();
  const { theme, setTheme } = useTheme();

  return (
    <>
      <a className="fh-skip-link" href="#main">
        Skip to main content
      </a>
      <GovBanner />
      <SiteHeader
        siteName={settings.siteName}
        links={settings.nav}
        theme={theme}
        onThemeChange={setTheme}
        submitLabel={settings.submitLabel}
        submitUrl={settings.submitFormUrl}
      />
      <main id="main" tabIndex={-1}>
        {children}
      </main>
      <SiteFooter
        siteName={settings.siteName}
        blurb={settings.footerBlurb}
        groups={settings.footerGroups}
        agencyLine={settings.agencyLine}
        usaGovUrl={settings.usaGovUrl}
      />
    </>
  );
}
