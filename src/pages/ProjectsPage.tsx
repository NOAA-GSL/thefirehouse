import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { ProjectDetailModal, ProjectTile } from '../design-system';
import { useContent } from '../content/ContentProvider';
import { byRecency, publishedProjects } from '../content/derive';
import { formatByline } from '../content/format';
import { FIRE_PHASES, PUBLICATION_STATUSES } from '../design-system/taxonomy';
import './ProjectsPage.css';

/**
 * Project explorer — the "brick and mortar" grid from creative brief §5.2.
 *
 * Included because the landing page's secondary CTA points here and the design
 * system ships `ProjectTile`/`ProjectDetailModal` for exactly this screen. It is a
 * working first pass, not the finished explorer: search, sorting and per-project
 * URLs are still open (see README "What's not built yet").
 *
 * There is no topic filter, on purpose. Topic areas are an LLM synthesis across the
 * whole collection, not a tag on any one project, so filtering projects "by topic"
 * would invent a mapping the data doesn't contain.
 */
export function ProjectsPage() {
  const content = useContent();
  const [openSlug, setOpenSlug] = useState<string | null>(null);

  useEffect(() => {
    document.title = `Explore projects — ${content.settings.siteName}`;
  }, [content.settings.siteName]);

  const projects = useMemo(() => publishedProjects(content).sort(byRecency), [content]);

  const openProject = projects.find((p) => p.slug === openSlug) ?? null;

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

      <p className="fh-explorer__count" role="status">
        {projects.length} {projects.length === 1 ? 'project' : 'projects'}
      </p>

      {projects.length > 0 ? (
        <div className="fh-explorer__grid">
          {projects.map((project) => (
            <ProjectTile
              key={project.id}
              kicker={project.projectType}
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
        <div className="fh-explorer__empty" role="status">
          <p className="fh-explorer__empty-body">No published projects are available yet.</p>
          <Link className="fh-explorer__empty-action" to="/#topic-areas">
            Browse topic areas
          </Link>
        </div>
      )}

      {openProject && (
        <ProjectDetailModal
          kicker={openProject.projectType}
          title={openProject.title}
          byline={formatByline(openProject.authors, 0)}
          org={openProject.org}
          year={openProject.completionYear}
          phases={openProject.firePhases.map((phase) => FIRE_PHASES[phase].short)}
          statusLabel={
            openProject.publicationStatus &&
            PUBLICATION_STATUSES[openProject.publicationStatus].label
          }
          abstract={openProject.abstract}
          takeaways={openProject.takeaways}
          needs={openProject.needs}
          papers={openProject.papers}
          pageUrl={`/projects/${openProject.slug}`}
          onClose={() => setOpenSlug(null)}
        />
      )}
    </div>
  );
}
