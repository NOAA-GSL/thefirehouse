import { useEffect } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { Button, Icon, TopicTag } from '../design-system';
import { useContent } from '../content/ContentProvider';
import './NotFoundPage.css';

/**
 * How much of the requested path to echo back.
 *
 * Echoing it helps a visitor spot their own typo and gives Stephanie and Emily
 * something quotable when someone reports a dead link. React escapes the value, so
 * there's no injection risk, but it is still attacker-chosen text on our page — so
 * it's truncated and rendered as a monospace chip, clearly a quoted address rather
 * than site copy.
 */
const MAX_PATH = 64;

/**
 * Custom 404.
 *
 * Reached two ways: the catch-all route, and `TopicPage` when the URL carries a topic
 * key that isn't one of the four (the keys are structural, so that can only come from
 * a mistyped or stale link, never from a CMS edit).
 *
 * A 404 on this site is a navigation failure, not a dead end — the hub is four topic
 * areas and an explorer, and all of it fits on one screen. So the page offers the
 * actual structure rather than just an apology and a link home.
 */
export function NotFoundPage() {
  const { settings, topics } = useContent();
  const { pathname } = useLocation();

  const requested = pathname.length > MAX_PATH ? `${pathname.slice(0, MAX_PATH)}…` : pathname;
  // A bad /topics/… URL is worth naming precisely: the reader was looking for a
  // specific area, and the four real ones are listed further down the page.
  const isTopicUrl = pathname.startsWith('/topics/');

  useEffect(() => {
    document.title = `Page not found — ${settings.siteName}`;
  }, [settings.siteName]);

  useEffect(() => {
    // Two of the three SPA fallbacks (Apache, _redirects) answer an unknown URL with
    // HTTP 200 and this page, which is a soft 404 — search engines would happily index
    // one copy per bad link. The bucket-host `404.html` path sends a real 404 and
    // doesn't need this, but the tag is harmless there. See README → Hosting.
    const meta = document.createElement('meta');
    meta.name = 'robots';
    meta.content = 'noindex';
    document.head.appendChild(meta);
    return () => meta.remove();
  }, []);

  return (
    <div className="fh-not-found fh-container">
      <div className="fh-not-found__inner">
        <span className="fh-eyebrow">Error 404</span>
        <h1 className="fh-not-found__heading">
          {isTopicUrl ? 'Topic area not found' : 'Page not found'}
        </h1>
        <p className="fh-not-found__body">
          {isTopicUrl
            ? 'The Firehouse organizes its findings into four topic areas, and that isn’t one of them. The address may be mistyped, or the link may predate a change to the site.'
            : 'That page doesn’t exist, or it may have moved. Everything on the hub is reachable from here.'}
        </p>

        <p className="fh-not-found__requested">
          Requested address <code className="fh-not-found__path">{requested}</code>
        </p>

        <div className="fh-not-found__actions">
          <Button variant="primary" to="/" iconRight="arrow-right">
            Back to The Firehouse
          </Button>
          <Button variant="secondary" to="/projects">
            Explore projects
          </Button>
        </div>

        <nav className="fh-not-found__topics" aria-labelledby="not-found-topics">
          <h2 id="not-found-topics" className="fh-not-found__subheading">
            The four topic areas
          </h2>
          <ul className="fh-not-found__topic-list">
            {topics.map((topic) => (
              <li key={topic.key}>
                <Link className="fh-not-found__topic-link" to={`/topics/${topic.key}`}>
                  <TopicTag topic={topic.key} label={topic.short} size="sm" />
                  <span className="fh-not-found__topic-label">{topic.label}</span>
                  <Icon name="arrow-right" size={16} />
                </Link>
              </li>
            ))}
          </ul>
        </nav>
      </div>
    </div>
  );
}
