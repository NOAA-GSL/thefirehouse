import { useEffect, useMemo, useState, type CSSProperties } from 'react';
import { Link, useParams } from 'react-router-dom';
import { Button, Icon, ProjectDetailModal, ProjectTile, TopicTag } from '../design-system';
import { TOPICS, isTopicKey } from '../design-system/topics';
import { useContent } from '../content/ContentProvider';
import { findTopic, findTopicSummary, projectsInTopic } from '../content/derive';
import { formatReviewDate } from '../content/format';
import { NotFoundPage } from './NotFoundPage';
import './TopicPage.css';

/**
 * One topic area in full: introduction, current top needs, and every project
 * filed under it.
 *
 * This is the destination the four landing-page cards have always implied. The
 * landing page can only show two needs per card with no room to say what the area
 * is; the explorer can filter to a topic but opens on a bare grid with no framing.
 * A reader arriving from a conference slide or an email link needs the framing
 * first and the evidence second, which is the order this page runs in.
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
        </div>
      </section>

      {/* ---- Description + current needs ---- */}
      <div className="fh-topic__body fh-container">
        <div className="fh-topic__prose">
          {topic.intro?.map((paragraph, i) => (
            <p key={i}>{paragraph}</p>
          ))}

          {topic.covers && topic.covers.length > 0 && (
            <>
              <h2 className="fh-topic__subheading">What this area covers</h2>
              <ul className="fh-topic__covers">
                {topic.covers.map((item, i) => (
                  <li key={i}>{item}</li>
                ))}
              </ul>
            </>
          )}
        </div>

        <aside className="fh-topic__needs" aria-labelledby="needs-heading">
          <h2 id="needs-heading" className="fh-topic__needs-heading">
            Top needs right now
          </h2>
          {needs.length > 0 ? (
            <ol className="fh-topic__needs-list">
              {needs.map((need, i) => (
                <li key={i}>{need}</li>
              ))}
            </ol>
          ) : (
            <p className="fh-topic__needs-empty">
              No needs have been published for this area yet.
            </p>
          )}
          <p className="fh-topic__needs-note">
            Synthesized from the projects below and reviewed before publication.
          </p>
          <Button
            variant="accent"
            size="sm"
            iconLeft="plus"
            href={content.settings.submitFormUrl}
          >
            {content.settings.submitLabel}
          </Button>
        </aside>
      </div>

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
                topic={project.topic}
                title={project.title}
                summary={project.summary}
                author={project.author}
                year={project.year}
                onClick={() => setOpenSlug(project.slug)}
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
          topic={openProject.topic}
          title={openProject.title}
          author={openProject.author}
          org={openProject.org}
          year={openProject.year}
          takeaways={openProject.takeaways}
          needs={openProject.needs}
          recommendations={openProject.recommendations}
          papers={openProject.papers}
          fullRecordUrl={openProject.fullRecordUrl}
          onClose={() => setOpenSlug(null)}
        />
      )}
    </div>
  );
}
