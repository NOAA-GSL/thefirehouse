import { Suspense, lazy, useEffect, useMemo, type CSSProperties } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { BrandMark, Button, Icon, StatCounter, TopicTag } from '../design-system';
import { SynthesisNote } from '../components/SynthesisNote';
import { useTheme } from '../components/ThemeProvider';
import { useContent } from '../content/ContentProvider';
import {
  buildTopicCards,
  effectiveRegions,
  needMentions,
  publishedProjects,
  regionCounts,
  resolveStatValue,
  synthesisInfo,
} from '../content/derive';
import { isRegionKey, type RegionKey } from '../design-system/taxonomy';
import { TOPICS } from '../design-system/topics';
import type { LinkRef } from '../content/types';
import './LandingPage.css';

/**
 * The coverage map, split into its own chunk.
 *
 * Leaflet plus the boundary GeoJSON is roughly 180 kB, and it is needed on exactly
 * one route. Bundled with everything else it was landing in the initial payload for
 * the explorer, the topic pages and every project page too — none of which draw a
 * map. Splitting it keeps those routes at their previous weight and lets the
 * landing page render its copy and CTAs before the map arrives, which matters on
 * the conference wifi this is meant to be shown on.
 */
const RegionMap = lazy(() =>
  import('../design-system/RegionMap').then((m) => ({ default: m.RegionMap })),
);

/**
 * Picks up the design system's hero photo if it has been added to
 * `src/assets/imagery/` — see the README there. Deliberately a glob rather than a
 * static import so the build doesn't break while the binary is still missing.
 */
const packagedHero = Object.values(
  import.meta.glob('../assets/imagery/fire-weather-hero.{png,jpg,jpeg,webp,avif}', {
    eager: true,
    query: '?url',
    import: 'default',
  }),
)[0] as string | undefined;

/** Renders a CTA as a router link, an external link, or nothing sensible-but-broken. */
function CtaButton({
  cta,
  ...rest
}: { cta: LinkRef } & Omit<
  Parameters<typeof Button>[0],
  'children' | 'to' | 'href'
>) {
  if (cta.to) {
    return (
      <Button {...rest} to={cta.to}>
        {cta.label}
      </Button>
    );
  }
  return (
    <Button {...rest} href={cta.href ?? '#'}>
      {cta.label}
    </Button>
  );
}

export function LandingPage() {
  const content = useContent();
  const { theme } = useTheme();
  const [searchParams, setSearchParams] = useSearchParams();
  const { hero, stats, topicSection, submitBand, needsPerCard } = content.landing;
  const cards = buildTopicCards(content, needsPerCard);
  const synthesis = synthesisInfo(content);
  const heroImage = hero.imageUrl ?? packagedHero;

  // The selected region lives in the query string, so a view of the map is a
  // linkable thing — "here's what we have for the Great Basin" is a URL someone can
  // paste into an email rather than a set of instructions to click.
  const regionParam = searchParams.get('region');
  const selectedRegion: RegionKey | null = isRegionKey(regionParam) ? regionParam : null;

  const counts = useMemo(() => regionCounts(content), [content]);

  const mapProjects = useMemo(
    () =>
      publishedProjects(content).map((project) => ({
        slug: project.slug,
        title: project.title,
        year: project.completionYear,
        regions: effectiveRegions(project.geo?.regions),
        national: project.geo?.regions.includes('NATIONAL') ?? false,
      })),
    [content],
  );

  function selectRegion(key: RegionKey | null) {
    // preventScrollReset: the map now sits mid-page, and a search-param change must
    // not throw the reader back to the top.
    setSearchParams(key ? { region: key } : {}, { replace: true, preventScrollReset: true });
  }

  useEffect(() => {
    document.title = `${content.settings.siteName} — Fire Weather Social Science Hub | NOAA GSL`;
  }, [content.settings.siteName]);

  return (
    <>
      {/* ---- Hero ---- */}
      <section className="fh-hero">
        <div className="fh-hero__media" aria-hidden="true">
          {heroImage && <img className="fh-hero__image" src={heroImage} alt={hero.imageAlt} />}
          {/* Only over the gradient stand-in. Once the real photograph lands it has
              its own motion and texture, and a drifting overlay would fight it. */}
          {!heroImage && <div className="fh-hero__smoke" />}
          <div className="fh-hero__scrim" />
        </div>

        <div className="fh-hero__content fh-container">
          <div className="fh-hero__grid">
            <div className="fh-hero__panel">
              {/* The full combination mark, once, where the site introduces itself.
                  The hero is dark in both themes, so it always takes the light-
                  stroked artwork. */}
              <BrandMark
                variant="wordmark"
                surface="dark"
                height={72}
                alt="FireHouse — Fire Weather Research Hub"
                className="fh-hero__logo"
              />
              <span className="fh-hero__eyebrow">{hero.eyebrow}</span>
              <h1 className="fh-hero__heading">{hero.heading}</h1>
              <p className="fh-hero__body">{hero.body}</p>
              <div className="fh-hero__actions">
                <CtaButton
                  cta={hero.primaryCta}
                  variant="accent"
                  size="lg"
                  iconLeft="plus"
                  blockOnMobile
                />
                <CtaButton
                  cta={hero.secondaryCta}
                  variant="secondary"
                  size="lg"
                  iconRight="arrow-right"
                  onDark
                  blockOnMobile
                />
              </div>
            </div>

            {/* Live stats sit beside the copy rather than in a band below it, so
                nothing stands between the hero and the top needs. */}
            <ul className="fh-hero__stats" aria-label="Hub statistics">
              {stats.map((stat) => (
                <li key={stat.id} className="fh-stat-pane">
                  <StatCounter
                    value={resolveStatValue(stat, content)}
                    label={stat.label}
                    caption={stat.caption}
                    accent={stat.accent}
                  />
                </li>
              ))}
            </ul>
          </div>
        </div>

        <span className="fh-hero__horizon" aria-hidden="true" />
      </section>

      {/* ---- Top needs ----
          Straight after the hero: this synthesis is what the site exists to offer. */}
      <section className="fh-topics fh-container" id="top-needs" aria-labelledby="topics-heading">
        <div className="fh-topics__intro">
          {topicSection.eyebrow && <span className="fh-eyebrow">{topicSection.eyebrow}</span>}
          <h2 id="topics-heading" className="fh-topics__heading">
            {topicSection.heading}
          </h2>
          {topicSection.body && <p className="fh-topics__body">{topicSection.body}</p>}
          {/* One disclosure for all four cards, instead of a badge on each. */}
          <SynthesisNote {...synthesis} className="fh-topics__note" />
        </div>

        <div className="fh-topics__grid">
          {cards.map(({ topic, needs }) => (
            <article
              key={topic.key}
              className="fh-topic-card fh-glass"
              style={{ '--card-tint': TOPICS[topic.key].tint } as CSSProperties}
            >
              <span className="fh-topic-card__wash" aria-hidden="true" />
              <TopicTag topic={topic.key} label={topic.short} />
              {/* The link sits on the title and is stretched over the whole card by
                  CSS, so the accessible name stays the topic while the click target
                  is the full card. */}
              <h3 className="fh-topic-card__title">
                <Link className="fh-topic-card__link" to={`/topics/${topic.key}`}>
                  {topic.label}
                </Link>
              </h3>
              {needs.length > 0 ? (
                <ol className="fh-needs">
                  {needs.map((need, i) => {
                    const mentions = needMentions(need);
                    return (
                      <li key={i} className="fh-needs__item">
                        <span className="fh-needs__rank" aria-hidden="true">
                          {i + 1}
                        </span>
                        <span className="fh-needs__text">{need.text}</span>
                        {mentions > 0 && (
                          <span className="fh-needs__count">
                            {mentions} {mentions === 1 ? 'mention' : 'mentions'}
                          </span>
                        )}
                      </li>
                    );
                  })}
                </ol>
              ) : (
                <p className="fh-topic-card__empty">No needs have been published for this area yet.</p>
              )}
              <div className="fh-topic-card__foot">
                <span className="fh-topic-card__cue" aria-hidden="true">
                  View topic area and sources
                  <Icon name="arrow-right" size={15} />
                </span>
              </div>
            </article>
          ))}
        </div>

        {/* ---- Where these needs come from ---- */}
        <aside className="fh-source" id="needs-source" aria-labelledby="source-heading">
          <div className="fh-source__copy">
            <h3 id="source-heading" className="fh-source__heading">
              Where these needs come from
            </h3>
            <p className="fh-source__body">
              Topic areas are not assigned project by project. Every completed project
              in The Firehouse — {synthesis.sourceCount}{' '}
              {synthesis.sourceCount === 1 ? 'project' : 'projects'} today — is read
              together{synthesis.model ? `, with ${synthesis.model},` : ''} and the
              end-user needs its researchers recorded are grouped into the four areas
              above and ranked by how often they come up. A <em>mention</em> is one
              need or recommendation a researcher submitted; the same one can count in
              more than one area.
              {synthesis.reviewedBy
                ? ` The ${synthesis.reviewedBy} reviews every pass before it is published.`
                : ' This pass is a draft: the team reviews each synthesis before it is final.'}{' '}
              Open a topic area to see which projects each need draws on; each project
              page keeps the researchers’ own words.
            </p>
          </div>
          <Button variant="secondary" size="md" iconRight="arrow-right" to="/projects">
            Explore all projects
          </Button>
        </aside>
      </section>

      {/* ---- Coverage map ----
          Deliberately NOT .fh-glass: this panel defines its own dark surface in CSS
          (see .fh-coverage), and .fh-glass would fight it on `background`. */}
      <section className="fh-coverage-section fh-container" aria-labelledby="coverage-heading">
        <div className="fh-coverage">
          <div className="fh-coverage__head">
            <h2 id="coverage-heading" className="fh-coverage__title">
              Where the research comes from
            </h2>
            <p className="fh-coverage__sub">
              Submissions by Geographic Area Coordination Center (GACC). Select one to
              open the projects filed there.
            </p>
          </div>
          {/* The fallback reserves the map's height so nothing below it jumps as
              the chunk lands. */}
          <Suspense
            fallback={
              <div className="fh-coverage__loading" role="status">
                <span className="fh-visually-hidden">Loading the coverage map</span>
              </div>
            }
          >
            <RegionMap
              counts={counts}
              projects={mapProjects}
              selected={selectedRegion}
              onSelect={selectRegion}
              projectHref={(slug) => `${import.meta.env.BASE_URL}projects/${slug}`}
              theme={theme === 'dark' ? 'dark' : 'light'}
            />
          </Suspense>
        </div>
      </section>

      {/* ---- Submit CTA band ---- */}
      <section className="fh-cta-band" aria-labelledby="submit-heading">
        <div className="fh-cta-band__inner fh-container fh-glass">
          <span className="fh-cta-band__glow" aria-hidden="true" />
          <div className="fh-cta-band__copy">
            <h2 id="submit-heading" className="fh-cta-band__heading">
              {submitBand.heading}
            </h2>
            <p className="fh-cta-band__body">{submitBand.body}</p>
          </div>
          <CtaButton cta={submitBand.cta} variant="accent" size="lg" iconLeft="plus" blockOnMobile />
        </div>
      </section>
    </>
  );
}
