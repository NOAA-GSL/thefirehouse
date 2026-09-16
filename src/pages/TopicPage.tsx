import { useEffect, useMemo, useState, type CSSProperties } from 'react';
import { Link, useParams } from 'react-router-dom';
import { Button, Icon, ProjectDetailModal, ProjectTile, TopicTag } from '../design-system';
import { TOPICS, isTopicKey } from '../design-system/topics';
import { useContent } from '../content/ContentProvider';
import { findTopic, findTopicSummary, projectsInTopic } from '../content/derive';
import { formatByline, formatReviewDate } from '../content/format';
import { FIRE_PHASES, PUBLICATION_STATUSES } from '../design-system/taxonomy';
import { NotFoundPage } from './NotFoundPage';
import './TopicPage.css';

/**
 * One topic area in full: current top needs, background on what the area is,
 * and every project filed under it.
 *
 * This is the destination the four landing-page cards have always implied. The
 * landing page can only show two needs per card with no room to say what the area
 * is; the explorer can filter to a topic but opens on a bare grid with no framing.
 * The page leads with the needs — the synthesis this site exists to provide —
 * then gives the background for anyone who wants the framing, then the evidence.
 *
 * The route key is the `TopicKey` itself (`/topics/observe`) rather than a CMS
 * slug — the four keys are structural (see `design-system/topics.ts`), so they are
 * the one identifier an editor cannot break a published URL by renaming.
 */
export function TopicPage() {
  const content = useContent();
  const { topicKey } = useParams();
  const [openSlug, setOpenSlug] = useState<string | null>(null);

  const key = isTopicKey(topicKey) ? topicKey : null;
  const topic = key ? findTopic(content, key) : undefined;

  const projects = useMemo(() => (key ? projectsInTopic(content, key) : []), [content, key]);

  useEffect(() => {
    if (!topic) return;
    document.title = `${topic.label} — ${content.settings.siteName}`;
  }, [topic, content.settings.siteName]);

  // Reset when routing sideways between topic areas; the panel is keyed to a slug
  // from the previous topic and would otherwise stay open on an unrelated project.
  useEffect(() => setOpenSlug(null), [key]);

  // An unknown key is a bad URL, not a content error — the four keys are fixed in
  // code, so nothing an editor does can land a reader here.
  if (!key || !topic) return <NotFoundPage />;

  const definition = TOPICS[key];
  const summary = findTopicSummary(content, key);
  const needs = summary?.topNeeds ?? [];
  const others = content.topics.filter((t) => t.key !== key);
  const hasBackground = Boolean(topic.intro?.length || topic.covers?.length);
  const openProject = projects.find((p) => p.slug === openSlug) ?? null;

  const topicVars = {
    '--topic-text': definition.text,
    '--topic-tint': definition.tint,
    '--topic-border': definition.border,
    '--topic-fill': definition.fill,
  } as CSSProperties;

  return (
    <div className="fh-topic" style={topicVars}>
      {/* ---- Introduction ---- */}
      <section className="fh-topic__hero">
        <div className="fh-topic__hero-inner fh-container">
          <nav className="fh-crumbs" aria-label="Breadcrumb">
            <Link to="/">Home</Link>
            <Icon name="chevron-right" size={14} />
            <Link to="/#topic-areas">Topic areas</Link>
            <Icon name="chevron-right" size={14} />
            <span aria-current="page">{topic.short}</span>
          </nav>

          <TopicTag topic={key} label={topic.short} />
          <h1 className="fh-topic__heading">{topic.label}</h1>
          {topic.description && <p className="fh-topic__lead">{topic.description}</p>}

          <dl className="fh-topic__facts">
            <div className="fh-topic__fact">
              <dt>Projects in this area</dt>
              <dd>{projects.length}</dd>
            </div>
            <div className="fh-topic__fact">
              <dt>Needs currently tracked</dt>
              <dd>{needs.length}</dd>
            </div>
            {summary?.updatedAt && (
              <div className="fh-topic__fact">
                <dt>Last reviewed</dt>
                <dd>
                  <time dateTime={summary.updatedAt}>{formatReviewDate(summary.updatedAt)}</time>
                </dd>
              </div>
            )}
          </dl>

          {/* Tab-style wayfinding without hiding anything: every section stays on
              the page (and in a find-in-page search), this just gets you there. */}
          <nav className="fh-topic__jump" aria-label="On this page">
            <a href="#needs-heading">Top needs</a>
            {hasBackground && <a href="#background-heading">Background</a>}
            <a href="#projects-heading">Projects ({projects.length})</a>
          </nav>
        </div>
      </section>

      {/* ---- Current needs ----
          First and full width. The needs are the synthesis this site exists to
          provide (product principle 1), so they lead instead of sitting in a
          sidebar beside the background reading. */}
      <section className="fh-topic__needs fh-container" aria-labelledby="needs-heading">
        <div className="fh-topic__needs-head">
          <div>
            <h2 id="needs-heading" className="fh-topic__subheading">
              Top needs right now
            </h2>
            <p className="fh-topic__needs-note">
              {summary?.model
                ? `AI-synthesized from ${
                    summary.sourceCount ? `${summary.sourceCount} submissions` : 'submissions'
                  } and the projects below`
                : 'Synthesized from the projects below'}
              {summary?.reviewedBy ? `, reviewed by ${summary.reviewedBy}` : ', reviewed before publication'}
              {summary?.updatedAt && (
                <>
                  {' on '}
                  <time dateTime={summary.updatedAt}>{formatReviewDate(summary.updatedAt)}</time>
                </>
              )}
              .
            </p>
          </div>
          <Button
            variant="accent"
            size="sm"
            iconLeft="plus"
            href={content.settings.submitFormUrl}
          >
            {content.settings.submitLabel}
          </Button>
        </div>

        {needs.length > 0 ? (
          <ol className="fh-topic__needs-list">
            {needs.map((need, i) => (
              <li key={i} className="fh-topic__need">
                <span className="fh-topic__need-num" aria-hidden="true">
                  {String(i + 1).padStart(2, '0')}
                </span>
                <span className="fh-topic__need-text">{need}</span>
              </li>
            ))}
          </ol>
        ) : (
          <p className="fh-topic__needs-empty">
            No needs have been published for this area yet.
          </p>
        )}
      </section>

      {/* ---- Background ----
          The definition of the area lives here, under its own heading, rather than
          as unlabelled prose beside the needs — so a reader who wants the framing
          knows where it is, and one who doesn't can skip straight past it. */}
      {hasBackground && (
        <section className="fh-topic__background fh-container" aria-labelledby="background-heading">
          <h2 id="background-heading" className="fh-topic__subheading">
            About this topic area
          </h2>
          <div className="fh-topic__background-grid">
            {topic.intro && topic.intro.length > 0 && (
              <div className="fh-topic__prose">
                {topic.intro.map((paragraph, i) => (
                  <p key={i}>{paragraph}</p>
                ))}
              </div>
            )}

            {topic.covers && topic.covers.length > 0 && (
              <div className="fh-topic__covers-panel">
                <h3 className="fh-topic__covers-heading">What this area covers</h3>
                <ul className="fh-topic__covers">
                  {topic.covers.map((item, i) => (
                    <li key={i}>{item}</li>
                  ))}
                </ul>
              </div>
            )}
          </div>
        </section>
      )}

      {/* ---- Project grid ---- */}
      <section className="fh-topic__projects fh-container" aria-labelledby="projects-heading">
        <div className="fh-topic__projects-intro">
          <h2 id="projects-heading" className="fh-topic__subheading">
            Projects in this area
          </h2>
          <p className="fh-topic__projects-body">
            {projects.length > 0
              ? `${projects.length} completed ${projects.length === 1 ? 'project' : 'projects'} filed under ${topic.short.toLowerCase()}. Open one to read its takeaways, end-user needs, recommendations, and related papers.`
              : `No projects have been published under ${topic.short.toLowerCase()} yet.`}
          </p>
        </div>

        {projects.length > 0 ? (
          <div className="fh-topic__grid">
            {projects.map((project) => (
              <ProjectTile
                key={project.id}
                // Every project on this page carries the current topic, so leading
                // with it would repeat the same tag down the whole grid. Sinking it
                // to the end means the two-tag cap surfaces what a reader doesn't
                // already know: the *other* areas this project also speaks to.
                topics={[...project.topics.filter((t) => t !== key), key]}
                title={project.title}
                summary={project.summary}
                byline={formatByline(project.authors)}
                year={project.completionYear}
                phases={project.firePhases.map((phase) => FIRE_PHASES[phase].short)}
                to={`/projects/${project.slug}`}
                onPreview={() => setOpenSlug(project.slug)}
              />
            ))}
          </div>
        ) : (
          <p className="fh-topic__empty">
            Research on this area is still being analyzed. If you have a finding to
            contribute, it will be reviewed and folded in here.
          </p>
        )}
      </section>

      {/* ---- Sideways navigation ---- */}
      <nav className="fh-topic__more fh-container" aria-labelledby="more-heading">
        <h2 id="more-heading" className="fh-topic__subheading">
          Other topic areas
        </h2>
        <ul className="fh-topic__more-list">
          {others.map((other) => (
            <li key={other.key}>
              <Link className="fh-topic__more-link" to={`/topics/${other.key}`}>
                <TopicTag topic={other.key} label={other.short} size="sm" />
                <span className="fh-topic__more-row">
                  <span className="fh-topic__more-label">{other.label}</span>
                  <Icon name="arrow-right" size={16} />
                </span>
              </Link>
            </li>
          ))}
        </ul>
      </nav>

      {openProject && (
        <ProjectDetailModal
          topics={openProject.topics}
          title={openProject.title}
          byline={formatByline(openProject.authors, 0)}
          org={openProject.org}
          year={openProject.completionYear}
          phases={openProject.firePhases.map((phase) => FIRE_PHASES[phase].label)}
          statusLabel={PUBLICATION_STATUSES[openProject.publicationStatus].label}
          abstract={openProject.abstract}
          takeaways={openProject.takeaways}
          needs={openProject.needs}
          recommendations={openProject.recommendations}
          papers={openProject.papers}
          pageUrl={`/projects/${openProject.slug}`}
          onClose={() => setOpenSlug(null)}
        />
      )}
    </div>
  );
}
