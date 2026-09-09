import { useEffect, useRef, type ReactNode } from 'react';
import { Route, Routes, useLocation } from 'react-router-dom';
import { Layout } from './components/Layout';
import { ContentProvider, useContentState } from './content/ContentProvider';
import { ThemeProvider } from './components/ThemeProvider';
import { LandingPage } from './pages/LandingPage';
import { NotFoundPage } from './pages/NotFoundPage';
import { ProjectsPage } from './pages/ProjectsPage';
import { TopicPage } from './pages/TopicPage';

/**
 * Holds rendering until content resolves, so pages can treat it as present.
 *
 * With the local adapter this settles in the same tick and nothing flashes. It earns
 * its keep once a CMS is behind the adapter and the load is a real request.
 */
function ContentGate({ children }: { children: ReactNode }) {
  const state = useContentState();

  if (state.status === 'loading') {
    return (
      <div className="fh-container" style={{ paddingBlock: 'var(--space-4xl)' }} role="status">
        <span className="fh-visually-hidden">Loading content</span>
      </div>
    );
  }

  if (state.status === 'error') {
    return (
      <div className="fh-container" style={{ paddingBlock: 'var(--space-4xl)', maxWidth: 640 }}>
        <h1 style={{ font: 'var(--text-h2)', margin: 0 }}>Content could not be loaded</h1>
        <p style={{ font: 'var(--text-body)', color: 'var(--color-text-secondary)' }}>
          {state.error.message}
        </p>
      </div>
    );
  }

  return <>{children}</>;
}

/**
 * Moves focus to the main landmark on navigation.
 *
 * Client-side routing swaps the DOM without moving focus, which leaves screen reader
 * and keyboard users stranded at the old position. Required for the WCAG 2.1 AA
 * commitment in the brief (§9.2).
 *
 * It deliberately does *not* fire on the initial page load. A cold load already starts
 * focus at the top of the document; pulling it into `<main>` there would leave the skip
 * link, the gov banner toggle and the whole header behind the focus position, so the
 * visitor's first Tab would land inside the content and the skip link — which exists
 * for exactly this moment — could never be reached at all.
 */
function RouteFocusManager() {
  const { pathname, hash } = useLocation();

  // The route this last acted on, seeded with the one the app loaded at. Comparing
  // against it (rather than flipping a "first run" flag) is what makes the initial-load
  // case correct in development too: StrictMode invokes mount effects twice, and a flag
  // would already be spent on the second pass.
  const handledRoute = useRef(pathname + hash);

  useEffect(() => {
    const route = pathname + hash;
    const isNavigation = route !== handledRoute.current;
    handledRoute.current = route;

    // In-page anchors ("/#topic-areas"). Client-side routing doesn't scroll to a `#hash`
    // the way a full page load does — and on a cold load the browser can't either,
    // because the target doesn't exist yet when the document is parsed.
    if (hash) {
      const target = document.querySelector(hash);
      if (target) {
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        return;
      }
    }

    if (!isNavigation) return;

    const main = document.getElementById('main');
    if (main) main.focus({ preventScroll: true });
    window.scrollTo(0, 0);
  }, [pathname, hash]);

  return null;
}

export function App() {
  return (
    <ThemeProvider>
      <ContentProvider>
        <ContentGate>
          <RouteFocusManager />
          <Layout>
            <Routes>
              <Route path="/" element={<LandingPage />} />
              <Route path="/projects" element={<ProjectsPage />} />
              <Route path="/topics/:topicKey" element={<TopicPage />} />
              <Route path="*" element={<NotFoundPage />} />
            </Routes>
          </Layout>
        </ContentGate>
      </ContentProvider>
    </ThemeProvider>
  );
}
