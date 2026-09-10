import { useEffect, useMemo, type ReactNode } from 'react';
import { Link, useParams } from 'react-router-dom';
import { Button, CitationBlock, Icon, TopicTag, TopicTagRow } from '../design-system';
import { FIRE_PHASES, PUBLICATION_STATUSES, REGIONS } from '../design-system/taxonomy';
import { useContent } from '../content/ContentProvider';
import { byRecency, publishedProjects } from '../content/derive';
import { CITATION_FORMATS, buildCitation, type CitationFormat } from '../content/citation';
import { formatByline } from '../content/format';
import type { Project } from '../content/types';
import { NotFoundPage } from './NotFoundPage';
import './ProjectPage.css';

/**
 * The "X-ray screen" — one completed research project, at its own URL.
 *
 * Replaces the modal that used to serve this content. A modal cannot be linked,
 * cited, bookmarked, opened in a new tab, or shared into a conference slide, and
 * every one of those is something this page is *for* (walkthrough: "each project
 * opens on its own unique URL page for citability").
 *
 * ---------------------------------------------------------------------------
 * The rule that governs this page
 * ---------------------------------------------------------------------------
 * Everything the researcher wrote is reproduced VERBATIM. The abstract, takeaways,
 * needs and recommendations are printed exactly as submitted — not summarised, not
 * tightened, not merged.
 *
 * The AI-synthesized phrasing belongs to the landing page and stops there. This is
 * a product rule from the walkthrough, not a styling preference, and it is the kind
 * of rule a future contributor would break while trying to be helpful ("these
 * bullets are repetitive, let me condense them"). Please don't.
 */

/** One titled block of the main column. */
function Section({
  title,
  count,
  children,
}: {
  title: string;
  count?: number;
  children: ReactNode;
}) {
  return (
    <section className="fh-project__section">
      <h2 className="fh-project__section-title">
        {title}
        {count !== undefined && count > 0 && (
          <span className="fh-project__section-count" aria-hidden="true">
            {count}
          </span>
        )}
      </h2>
      {children}
    </section>
  );
}

function VerbatimList({ items }: { items: string[] }) {
  return (
    <ul className="fh-project__list">
      {items.map((item, i) => (
        <li key={i}>{item}</li>
      ))}
    </ul>
  );
}

/** A labelled row in the "At a glance" panel. */
function Fact({
  icon,
  label,
  children,
}: {
  icon: 'calendar' | 'flame' | 'file-text' | 'map-pin' | 'users' | 'layers' | 'building';
  label: string;
  children: ReactNode;
}) {
  return (
    <div className="fh-project__fact">
      <dt className="fh-project__fact-label">
        <Icon name={icon} size={14} />
        {label}
      </dt>
      <dd className="fh-project__fact-value">{children}</dd>
    </div>
  );
}

export function ProjectPage() {
  const content = useContent();
  const { slug } = useParams();

  // Only published projects are reachable. An unpublished record has a valid slug
  // in the CMS, so without this an editor could hand out a working URL to research
  // that is not meant to be public yet.
  const projects = useMemo(() => publishedProjects(content).sort(byRecency), [content]);
  const index = projects.findIndex((p) => p.slug === slug);
  const project: Project | undefined = index >= 0 ? projects[index] : undefined;

  useEffect(() => {
    if (!project) return;
    document.title = `${project.title} — ${content.settings.siteName}`;

    // A citable page needs a real description for link previews and search results;
    // the site-level one in index.html describes the hub, not this project.
    const meta = document.querySelector('meta[name="description"]');
    const previous = meta?.getAttribute('content') ?? null;
    meta?.setAttribute('content', project.abstract ?? project.summary);
    return () => {
      if (meta && previous !== null) meta.setAttribute('content', previous);
    };
  }, [project, content.settings.siteName]);

  // An unknown slug is a bad URL, not a content error.
  if (!project) return <NotFoundPage />;

  const status = PUBLICATION_STATUSES[project.publicationStatus];
  const regions = project.geo?.regions ?? [];
  const previous = index > 0 ? projects[index - 1] : null;
  const next = index < projects.length - 1 ? projects[index + 1] : null;

  const citation = (format: string) =>
    buildCitation(format as CitationFormat, {
      project,
      // The live address is always correct, including on a preview deploy or a
      // sub-path build — better than reconstructing it from an env var that may
      // not be set yet (hosting is still open; creative brief §8).
      url: typeof window === 'undefined' ? '' : window.location.href,
    });

  return (
    <article className="fh-project">
      {/* ---- Header ---- */}
      <header className="fh-project__head">
        <div className="fh-project__head-inner fh-container">
          <nav className="fh-project__crumbs" aria-label="Breadcrumb">
            <Link to="/projects">
              <Icon name="arrow-left" size={14} />
              All projects
            </Link>
          </nav>

          <TopicTagRow topics={project.topics} size="md" max={0} />

          <h1 className="fh-project__title">{project.title}</h1>

          <p className="fh-project__byline">
            {formatByline(project.authors, 0)}
            {project.org && <span className="fh-project__org">{project.org}</span>}
          </p>

          <div className="fh-project__meta">
            <span className="fh-meta-pill">
              <Icon name="calendar" size={12} />
              Completed {project.completionYear}
            </span>
            {project.firePhases.map((phase) => (
              <span key={phase} className="fh-meta-pill">
                <Icon name="flame" size={12} />
                {FIRE_PHASES[phase].label}
              </span>
            ))}
            <span className={`fh-meta-pill fh-meta-pill--${status.tone}`}>{status.label}</span>
          </div>
        </div>
        <span className="fh-project__head-rule" aria-hidden="true" />
      </header>

      <div className="fh-project__layout fh-container">
        {/* ---- Main column ---- */}
        <div className="fh-project__main">
          {project.abstract && (
            <Section title="Abstract">
              <p className="fh-project__prose">{project.abstract}</p>
            </Section>
          )}

          {project.takeaways.length > 0 && (
            <Section title="Major takeaways" count={project.takeaways.length}>
              <VerbatimList items={project.takeaways} />
            </Section>
          )}

          {project.needs.length > 0 && (
            <Section title="End-user needs" count={project.needs.length}>
              <VerbatimList items={project.needs} />
            </Section>
          )}

          {project.recommendations.length > 0 && (
            <Section title="Recommendations" count={project.recommendations.length}>
              <VerbatimList items={project.recommendations} />
            </Section>
          )}

          {project.papers.length > 0 && (
            <Section title="Related papers" count={project.papers.length}>
              <ul className="fh-project__papers">
                {project.papers.map((paper, i) => {
                  // A DOI outlives a journal URL, so it wins when both are present.
                  const href = paper.doi ? `https://doi.org/${paper.doi}` : paper.url;
                  return (
                    <li key={i}>
                      {href ? (
                        <a
                          className="fh-project__paper"
                          href={href}
                          target="_blank"
                          rel="noopener noreferrer"
                        >
                          <Icon name="file-text" size={16} />
                          <span>
                            {paper.title}
                            {paper.doi && (
                              <span className="fh-project__doi">doi:{paper.doi}</span>
                            )}
                          </span>
                          <Icon name="external-link" size={14} />
                        </a>
                      ) : (
                        <span className="fh-project__paper fh-project__paper--static">
                          <Icon name="file-text" size={16} />
                          <span>{paper.title}</span>
                        </span>
                      )}
                    </li>
                  );
                })}
              </ul>
            </Section>
          )}

          <CitationBlock formats={CITATION_FORMATS} render={citation} defaultFormat="apa" />
        </div>

        {/* ---- Sidebar ---- */}
        <aside className="fh-project__aside" aria-label="Project details">
          <div className="fh-project__panel fh-glass fh-glass--raised">
            <h2 className="fh-project__panel-title">At a glance</h2>
            <dl className="fh-project__facts">
              <Fact icon="calendar" label="Completion year">
                {project.completionYear}
              </Fact>

              <Fact icon="flame" label="Fire cycle phase">
                {project.firePhases.length > 0
                  ? project.firePhases.map((phase) => FIRE_PHASES[phase].label).join(', ')
                  : 'Not specified'}
              </Fact>

              <Fact icon="file-text" label="Publication status">
                {status.label}
              </Fact>

              <Fact icon="layers" label="Topic areas">
                <span className="fh-project__fact-tags">
                  {project.topics.map((topic) => (
                    <Link key={topic} to={`/topics/${topic}`} className="fh-project__fact-tag">
                      <TopicTag topic={topic} size="sm" />
                    </Link>
                  ))}
                </span>
              </Fact>

              {regions.length > 0 && (
                <Fact icon="map-pin" label="Geographic areas">
                  <span className="fh-project__regions">
                    {regions.map((key) => (
                      <span key={key} className="fh-project__region">
                        {REGIONS[key].short}
                        <abbr title={REGIONS[key].label}>{key}</abbr>
                      </span>
                    ))}
                  </span>
                  {project.geo?.note && (
                    <span className="fh-project__region-note">{project.geo.note}</span>
                  )}
                </Fact>
              )}

              {project.org && (
                <Fact icon="building" label="Organization">
                  {project.org}
                </Fact>
              )}
            </dl>

            {project.fullRecordUrl && (
              <Button
                variant="secondary"
                size="sm"
                iconLeft="external-link"
                href={project.fullRecordUrl}
              >
                View full record
              </Button>
            )}
          </div>

          {/* Says out loud what the page does, so a reader knows the bullets above
              are the researcher's own words and not an editorial summary. */}
          <p className="fh-project__verbatim">
            <Icon name="quote" size={14} />
            <span>
              Takeaways, needs and recommendations on this page are reproduced as the
              researcher submitted them. Synthesized summaries appear only on the{' '}
              <Link to="/#topic-areas">topic areas</Link> of the home page.
            </span>
          </p>
        </aside>
      </div>

      {/* ---- Prev / next ---- */}
      {(previous || next) && (
        <nav className="fh-project__pager fh-container" aria-label="Other projects">
          {previous ? (
            <Link className="fh-project__pager-link" to={`/projects/${previous.slug}`}>
              <span className="fh-project__pager-dir">
                <Icon name="chevron-left" size={14} />
                Previous
              </span>
              <span className="fh-project__pager-title">{previous.title}</span>
            </Link>
          ) : (
            <span />
          )}
          {next && (
            <Link
              className="fh-project__pager-link fh-project__pager-link--next"
              to={`/projects/${next.slug}`}
            >
              <span className="fh-project__pager-dir">
                Next
                <Icon name="chevron-right" size={14} />
              </span>
              <span className="fh-project__pager-title">{next.title}</span>
            </Link>
          )}
        </nav>
      )}
    </article>
  );
}
