import { useEffect, useId, useRef, type ReactNode } from 'react';
import { Button } from './Button';
import { Icon } from './Icon';
import { TopicTag } from './TopicTag';
import type { TopicKey } from './topics';
import './ProjectDetailModal.css';

export interface ProjectPaper {
  title: string;
  url?: string;
}

export interface ProjectDetailModalProps {
  topic: TopicKey;
  title: string;
  author: string;
  year: number | string;
  org?: string;
  takeaways?: string[];
  needs?: string[];
  recommendations?: string[];
  papers?: ProjectPaper[];
  fullRecordUrl?: string;
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
 * Full project detail surface: takeaways, needs, recommendations, papers, metadata.
 *
 * The design system shipped this as a presentational panel. Here it's a real dialog:
 * `aria-modal`, focus moved in on open and restored on close, Escape to dismiss, and
 * a focus loop so keyboard users can't tab out into the inert page behind it — all
 * required for the WCAG 2.1 AA / Section 508 commitment in the brief (§9.2).
 */
export function ProjectDetailModal({
  topic,
  title,
  author,
  year,
  org,
  takeaways = [],
  needs = [],
  recommendations = [],
  papers = [],
  fullRecordUrl,
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
            <TopicTag topic={topic} size="sm" />
            <h2 id={titleId} className="fh-modal__title">
              {title}
            </h2>
            <span className="fh-modal__byline">
              {author}
              {org ? `, ${org}` : ''} · {year}
            </span>
          </div>
          <button ref={closeRef} type="button" className="fh-modal__close" onClick={onClose}>
            <Icon name="x" size={20} />
            <span className="fh-visually-hidden">Close project details</span>
          </button>
        </header>

        {takeaways.length > 0 && (
          <Section title="Key takeaways">
            <BulletList items={takeaways} />
          </Section>
        )}
        {needs.length > 0 && (
          <Section title="End-user needs">
            <BulletList items={needs} />
          </Section>
        )}
        {recommendations.length > 0 && (
          <Section title="Recommendations">
            <BulletList items={recommendations} />
          </Section>
        )}
        {papers.length > 0 && (
          <Section title="Papers and sources">
            <div className="fh-modal__papers">
              {papers.map((paper, i) =>
                paper.url ? (
                  <a
                    key={i}
                    className="fh-modal__paper"
                    href={paper.url}
                    target="_blank"
                    rel="noopener noreferrer"
                  >
                    <Icon name="file-text" size={15} />
                    <span>{paper.title}</span>
                  </a>
                ) : (
                  <span key={i} className="fh-modal__paper fh-modal__paper--static">
                    <Icon name="file-text" size={15} />
                    <span>{paper.title}</span>
                  </span>
                ),
              )}
            </div>
          </Section>
        )}

        {fullRecordUrl && (
          <footer className="fh-modal__footer">
            <Button variant="secondary" size="sm" iconLeft="external-link" href={fullRecordUrl}>
              View full record
            </Button>
          </footer>
        )}
      </div>
    </div>
  );
}
