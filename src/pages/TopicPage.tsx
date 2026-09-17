import { useEffect, type CSSProperties } from 'react';
import { Link, useParams } from 'react-router-dom';
import { Button, Icon, TopicTag } from '../design-system';
import { TOPICS, isTopicKey } from '../design-system/topics';
import { SynthesisNote } from '../components/SynthesisNote';
import { useContent } from '../content/ContentProvider';
import {
  findProjectBySlug,
  findTopic,
  findTopicSummary,
  needMentions,
  publishedProjects,
} from '../content/derive';
import type { Project } from '../content/types';
import { NotFoundPage } from './NotFoundPage';
import './TopicPage.css';

/**
 * One topic area: its top needs, front and centre, each traced to the projects it
 * was drawn from — with what the area covers as a callout alongside.
 *
 * Topic areas are a synthesis across every collected project, not a tag on
 * individual ones, so there is no "projects in this area" list. What a reader can
 * follow instead is attribution per *need*: which projects raised it. That comes
 * from the synthesis's own citations (see `TopNeed`).
 *
 * How the synthesis works is explained once, on the landing page, and linked from
 * here rather than repeated on four pages.
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
  const needs = (summary?.topNeeds ?? []).slice(0, 5);
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
      {/* ---- Title ---- */}
      <section className="fh-topic__hero">
        <div className="fh-topic__hero-inner fh-container">
          <nav className="fh-crumbs" aria-label="Breadcrumb">
            <Link to="/">Home</Link>
            <Icon name="chevron-right" size={14} />
            <Link to="/#top-needs">Top needs</Link>
            <Icon name="chevron-right" size={14} />
            <span aria-current="page">{topic.short}</span>
          </nav>

          <TopicTag topic={key} label={topic.short} />
          <h1 className="fh-topic__heading">{topic.label}</h1>
        </div>
      </section>

      <div className="fh-topic__body fh-container">
        {/* ---- Top needs ---- */}
        <section className="fh-topic__needs" aria-labelledby="needs-heading">
          <div className="fh-topic__needs-head">
            <h2 id="needs-heading" className="fh-topic__subheading">
              Top {needs.length > 0 ? needs.length : ''} needs
            </h2>
            <SynthesisNote
              model={summary?.model}
              reviewedBy={summary?.reviewedBy}
              updatedAt={summary?.updatedAt}
              sourceCount={sourceCount}
            />
            <p className="fh-topic__how">
              Ranked by mentions — how many submitted needs and recommendations raise
              each one. <Link to="/#needs-source">How these needs are synthesized</Link>
            </p>
          </div>

          {needs.length > 0 ? (
            <ol className="fh-topic__needs-list">
              {needs.map((need, i) => {
                const mentions = needMentions(need);
                const sources = (need.projects ?? [])
                  .map((slug) => findProjectBySlug(content, slug))
                  .filter((p): p is Project => Boolean(p));
                return (
                  <li key={i} className="fh-need">
                    <span className="fh-need__rank" aria-hidden="true">
                      {i + 1}
                    </span>
                    <div className="fh-need__main">
                      <p className="fh-need__text">{need.text}</p>
                      <p className="fh-need__meta">
                        {mentions} {mentions === 1 ? 'mention' : 'mentions'}
                        {sources.length > 0 &&
                          ` · from ${sources.length} ${sources.length === 1 ? 'project' : 'projects'}`}
                      </p>
                      {sources.length > 0 && (
                        <ul
                          className="fh-need__sources"
                          aria-label={`Projects behind need ${i + 1}`}
                        >
                          {sources.map((project) => (
                            <li key={project.slug}>
                              <Link className="fh-need__source" to={`/projects/${project.slug}`}>
                                <Icon name="file-text" size={14} />
                                <span className="fh-need__source-title">{project.title}</span>
                                <span className="fh-need__source-year">
                                  {project.completionYear}
                                </span>
                              </Link>
                            </li>
                          ))}
                        </ul>
                      )}
                    </div>
                  </li>
                );
              })}
            </ol>
          ) : (
            <p className="fh-topic__empty">No needs have been published for this area yet.</p>
          )}
        </section>

        {/* ---- What this area covers ---- */}
        <aside className="fh-topic__aside" aria-labelledby="covers-heading">
          {topic.covers && topic.covers.length > 0 && (
            <div className="fh-topic__covers-panel">
              <h2 id="covers-heading" className="fh-topic__covers-heading">
                What this area covers
              </h2>
              <ul className="fh-topic__covers">
                {topic.covers.map((item, i) => (
                  <li key={i}>{item}</li>
                ))}
              </ul>
            </div>
          )}

          <div className="fh-topic__contribute">
            <p>Have a finding that speaks to this area?</p>
            <Button variant="accent" size="sm" iconLeft="plus" href={content.settings.submitFormUrl}>
              {content.settings.submitLabel}
            </Button>
          </div>
        </aside>
      </div>

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
