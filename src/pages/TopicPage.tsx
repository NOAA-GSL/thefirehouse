import { useEffect, type CSSProperties } from 'react';
import { Link, useParams } from 'react-router-dom';
import { Button, Icon, TopicTag } from '../design-system';
import { TOPICS, isTopicKey } from '../design-system/topics';
import { useContent } from '../content/ContentProvider';
import { findTopic, findTopicSummary, publishedProjects } from '../content/derive';
import { formatReviewDate } from '../content/format';
import { NotFoundPage } from './NotFoundPage';
import './TopicPage.css';

/**
 * One topic area in full: introduction, the current synthesized top needs, and
 * where that synthesis comes from.
 *
 * This is the destination the four landing-page cards have always implied. The
 * landing page can only show two needs per card with no room to say what the area
 * is; the explorer can filter to a topic but opens on a bare grid with no framing.
 * A reader arriving from a conference slide or an email link needs the framing
 * first and the evidence second, which is the order this page runs in.
 *
 * There is no "projects in this area" list. Topic areas are an LLM synthesis across
 * every collected project (NotebookLM), not a tag on individual ones, so listing a
 * subset here would assert a project-to-topic mapping that doesn't exist.
 *
 * The route key is the `TopicKey` itself (`/topics/observe`) rather than a CMS
 * slug — the four keys are structural (see `design-system/topics.ts`), so they are
 * the one identifier an editor cannot break a published URL by renaming.
 */
export function TopicPage() {
  const content = useContent();
  const { topicKey } = useParams();

  const key = isTopicKey(topicKey) ? topicKey : null;
  const topic = key ? findTopic(content, key) : undefined;

  useEffect(() => {
    if (!topic) return;
    document.title = `${topic.label} — ${content.settings.siteName}`;
  }, [topic, content.settings.siteName]);

  // An unknown key is a bad URL, not a content error — the four keys are fixed in
  // code, so nothing an editor does can land a reader here.
  if (!key || !topic) return <NotFoundPage />;

  const definition = TOPICS[key];
  const summary = findTopicSummary(content, key);
  const needs = summary?.topNeeds ?? [];
  const others = content.topics.filter((t) => t.key !== key);
  // The synthesis records how many projects it read; fall back to what is live now.
  const sourceCount = summary?.sourceCount ?? publishedProjects(content).length;

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
              <dt>Projects synthesized</dt>
              <dd>{sourceCount}</dd>
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
            AI-synthesized across all projects and reviewed before publication.
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

      {/* ---- Where the synthesis comes from ---- */}
      <section className="fh-topic__source fh-container" aria-labelledby="source-heading">
        <div className="fh-topic__source-copy">
          <h2 id="source-heading" className="fh-topic__subheading">
            Where these needs come from
          </h2>
          <p className="fh-topic__source-body">
            Topic areas are not assigned project by project. The needs above are
            synthesized across {sourceCount} completed{' '}
            {sourceCount === 1 ? 'project' : 'projects'} at once
            {summary?.model ? ` using ${summary.model}` : ''}, then reviewed by
            {summary?.reviewedBy ? ` the ${summary.reviewedBy}` : ' the team'} before
            publication. Each project page keeps the researchers' own words.
          </p>
        </div>
        <Button variant="secondary" size="md" iconRight="arrow-right" to="/projects">
          Explore all projects
        </Button>
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

    </div>
  );
}
