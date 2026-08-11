import { useEffect, useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { ProjectDetailModal, ProjectTile } from '../design-system';
import { isTopicKey, type TopicKey } from '../design-system/topics';
import { useContent } from '../content/ContentProvider';
import { publishedProjects } from '../content/derive';
import './ProjectsPage.css';

/**
 * Project explorer — the "brick and mortar" grid from creative brief §5.2.
 *
 * Included because the landing page's secondary CTA points here and the design
 * system ships `ProjectTile`/`ProjectDetailModal` for exactly this screen. It is a
 * working first pass, not the finished explorer: search, sorting and per-project
 * URLs are still open (see README "What's not built yet").
 *
 * The topic filter is held in the query string so a filtered view is linkable —
 * useful when Stephanie or Emily wants to point someone at one topic area.
 */
export function ProjectsPage() {
  const content = useContent();
  const [searchParams, setSearchParams] = useSearchParams();
  const [openSlug, setOpenSlug] = useState<string | null>(null);

  useEffect(() => {
    document.title = `Explore projects — ${content.settings.siteName}`;
  }, [content.settings.siteName]);

  const topicParam = searchParams.get('topic');
  const activeTopic: TopicKey | null = isTopicKey(topicParam) ? topicParam : null;

  // Drop a stale modal when the topic filter changes.
  useEffect(() => {
    setOpenSlug(null);
  }, [activeTopic]);

  const projects = useMemo(() => {
    const published = publishedProjects(content);
    return activeTopic ? published.filter((p) => p.topic === activeTopic) : published;
  }, [content, activeTopic]);

  const openProject = projects.find((p) => p.slug === openSlug) ?? null;

  function setTopic(topic: TopicKey | null) {
    setSearchParams(topic ? { topic } : {}, { replace: true });
  }

  return (
    <div className="fh-explorer fh-container">
      <header className="fh-explorer__intro">
        <span className="fh-eyebrow">Project explorer</span>
        <h1 className="fh-explorer__heading">Completed research projects</h1>
        <p className="fh-explorer__body">
          Every project analyzed for The Firehouse. Open one to read its takeaways,
          end-user needs, recommendations, and related papers.
        </p>
      </header>

      <div className="fh-explorer__filters" role="group" aria-label="Filter by topic area">
        <button
          type="button"
          className={`fh-chip${activeTopic === null ? ' fh-chip--active' : ''}`}
          aria-pressed={activeTopic === null}
          onClick={() => setTopic(null)}
        >
          All topics
        </button>
        {content.topics.map((topic) => (
          <button
            key={topic.key}
            type="button"
            className={`fh-chip${activeTopic === topic.key ? ' fh-chip--active' : ''}`}
            aria-pressed={activeTopic === topic.key}
            onClick={() => setTopic(topic.key)}
          >
            {topic.short}
          </button>
        ))}
      </div>

      <p className="fh-explorer__count" role="status">
        {projects.length} {projects.length === 1 ? 'project' : 'projects'}
        {activeTopic ? ` in ${content.topics.find((t) => t.key === activeTopic)?.label}` : ''}
        {/* A filtered grid answers "what exists", not "what is this area about" —
            the topic page does that, so offer it once a filter is on. */}
        {activeTopic && (
          <>
            {' · '}
            <Link className="fh-explorer__topic-link" to={`/topics/${activeTopic}`}>
              Read about this area
            </Link>
          </>
        )}
      </p>

      {projects.length > 0 ? (
        <div className="fh-explorer__grid">
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
        <div className="fh-explorer__empty" role="status">
          <p className="fh-explorer__empty-body">
            {activeTopic
              ? 'No published projects match this topic filter yet.'
              : 'No published projects are available yet.'}
          </p>
          {activeTopic ? (
            <button type="button" className="fh-explorer__empty-action" onClick={() => setTopic(null)}>
              Clear topic filter
            </button>
          ) : (
            <Link className="fh-explorer__empty-action" to="/#topic-areas">
              Browse topic areas
            </Link>
          )}
        </div>
      )}

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
