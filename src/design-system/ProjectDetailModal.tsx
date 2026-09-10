import { useEffect, useId, useRef, type ReactNode } from 'react';
import { Button } from './Button';
import { Icon } from './Icon';
import { TopicTagRow } from './TopicTagRow';
import type { TopicKey } from './topics';
import './ProjectDetailModal.css';

export interface ProjectPaper {
  title: string;
  url?: string;
  /** Bare DOI; the `https://doi.org/` prefix is added here. */
  doi?: string;
}

export interface ProjectDetailModalProps {
  topics: TopicKey[];
  title: string;
  /** Pre-formatted byline. */
  byline: string;
  year: number | string;
  org?: string;
  /** Fire-cycle-phase labels and publication status, shown as a metadata row. */
  phases?: string[];
  statusLabel?: string;
  abstract?: string;
  takeaways?: string[];
  needs?: string[];
  recommendations?: string[];
  papers?: ProjectPaper[];
  /** In-app route to the full X-ray page. This is the dialog's primary action. */
  pageUrl: string;
  onClose: () => void;
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="fh-modal__section">
      <h3 className="fh-modal__section-title">{title}</h3>
      {children}
    </section>
  );
}

function BulletList({ items }: { items: string[] }) {
  return (
    <ul className="fh-modal__list">
      {items.map((item, i) => (
        <li key={i}>{item}</li>
      ))}
    </ul>
  );
}

/**
 * Quick look — a preview of one project, opened from the explorer grid.
 *
 * This used to be the project's only detail surface. It isn't any more: projects
 * have real pages at `/projects/:slug`, and that is where someone reads, links or
 * cites one. So this dialog was cut back to the job it is actually good at —
 * answering "is this the project I want?" without losing your place in the grid.
 *
 * It shows the abstract and the end-user needs, because those are what people
 * scan for, and then *counts* the rest rather than reprinting it. A preview that
 * duplicates the whole page gives nobody a reason to open the page, and buries the
 * one thing the page has that a dialog structurally cannot: a citable URL.
 *
 * It is a real dialog: `aria-modal`, focus moved in on open and restored on close,
 * Escape to dismiss, and a focus loop so keyboard users can't tab out into the
 * inert page behind it — all required by the brief's WCAG 2.1 AA commitment (§9.2).
 */
export function ProjectDetailModal({
  topics,
  title,
  byline,
  year,
  org,
  phases = [],
  statusLabel,
  abstract,
  takeaways = [],
  needs = [],
  recommendations = [],
  papers = [],
  pageUrl,
  onClose,
}: ProjectDetailModalProps) {
  const panelRef = useRef<HTMLDivElement>(null);
  const closeRef = useRef<HTMLButtonElement>(null);
  const titleId = useId();

  useEffect(() => {
    const previouslyFocused = document.activeElement as HTMLElement | null;
    closeRef.current?.focus();

    const { overflow } = document.body.style;
    document.body.style.overflow = 'hidden';

    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        event.stopPropagation();
        onClose();
        return;
      }
      if (event.key !== 'Tab' || !panelRef.current) return;

      const focusable = panelRef.current.querySelectorAll<HTMLElement>(
        'a[href], button:not(:disabled), textarea, input, select, [tabindex]:not([tabindex="-1"])',
      );
      if (focusable.length === 0) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];

      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }

    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.removeEventListener('keydown', onKeyDown);
      document.body.style.overflow = overflow;
      previouslyFocused?.focus();
    };
  }, [onClose]);

  // Counts, not content — see the component comment. Ordered the way someone
  // scanning for relevance would weigh them.
  const rest = [
    takeaways.length > 0 && `${takeaways.length} takeaway${takeaways.length === 1 ? '' : 's'}`,
    recommendations.length > 0 &&
      `${recommendations.length} recommendation${recommendations.length === 1 ? '' : 's'}`,
    papers.length > 0 && `${papers.length} related paper${papers.length === 1 ? '' : 's'}`,
  ].filter(Boolean) as string[];

  return (
    <div
      className="fh-modal__overlay"
      onMouseDown={(e) => {
        if (e.target === e.currentTarget) onClose();
      }}
    >
      <div
        ref={panelRef}
        className="fh-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
      >
        <header className="fh-modal__header">
          <div className="fh-modal__identity">
            <TopicTagRow topics={topics} size="sm" max={0} />
            <h2 id={titleId} className="fh-modal__title">
              {title}
            </h2>
            <span className="fh-modal__byline">
              {byline}
              {org ? `, ${org}` : ''} · {year}
            </span>
            {(phases.length > 0 || statusLabel) && (
              <span className="fh-modal__meta">
                {phases.map((phase) => (
                  <span key={phase} className="fh-modal__meta-item">
                    {phase}
                  </span>
                ))}
                {statusLabel && (
                  <span className="fh-modal__meta-item fh-modal__meta-item--status">
                    {statusLabel}
                  </span>
                )}
              </span>
            )}
          </div>
          <button ref={closeRef} type="button" className="fh-modal__close" onClick={onClose}>
            <Icon name="x" size={20} />
            <span className="fh-visually-hidden">Close project details</span>
          </button>
        </header>

        {abstract && (
          <Section title="Abstract">
            {/* Verbatim, always. The researcher's own words are the point of this
                surface — the AI-synthesized phrasing lives on the landing page and
                must not leak down here. */}
            <p className="fh-modal__prose fh-modal__prose--clamped">{abstract}</p>
          </Section>
        )}

        {needs.length > 0 && (
          <Section title="End-user needs">
            <BulletList items={needs.slice(0, 3)} />
            {needs.length > 3 && (
              <p className="fh-modal__more">
                and {needs.length - 3} more on the project page
              </p>
            )}
          </Section>
        )}

        {/* What the page has that this preview doesn't. Naming the contents is what
            makes "open the page" a decision rather than a leap of faith. */}
        {rest.length > 0 && (
          <p className="fh-modal__rest">
            Also on the full page: {rest.join(', ')}.
          </p>
        )}

        <footer className="fh-modal__footer">
          <Button variant="accent" size="md" iconRight="arrow-right" to={pageUrl}>
            Open project page
          </Button>
          <span className="fh-modal__footer-note">
            Full takeaways, recommendations, papers and a citation
          </span>
        </footer>
      </div>
    </div>
  );
}
