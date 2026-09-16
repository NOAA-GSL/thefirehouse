import { Suspense, lazy, useEffect, useMemo, type CSSProperties } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { Button, Icon, StatCounter, TopicTag } from '../design-system';
import { useTheme } from '../components/ThemeProvider';
import { useContent } from '../content/ContentProvider';
import { buildTopicCards, publishedProjects, regionCounts, resolveStatValue } from '../content/derive';
import { isRegionKey, type RegionKey } from '../design-system/taxonomy';
import { TOPICS } from '../design-system/topics';
import { formatReviewDate } from '../content/format';
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
        regions: project.geo?.regions ?? [],
      })),
    [content],
  );

  function selectRegion(key: RegionKey | null) {
    setSearchParams(key ? { region: key } : {}, { replace: true });
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

          {/* The coverage map is the hero's second half, not a section further down
              the page: it is the one visual that answers "what is in here?" before
              anyone reads a word, which is why the walkthrough put it up top. */}
          {/* Deliberately NOT .fh-glass. This panel defines its own dark glass in
              CSS, and .fh-glass sets `background` at the same specificity — so
              source order decided the winner, which in light mode meant a white
              panel behind text pinned to on-dark colours (1.15:1, unreadable).
              The panel owns its surface outright instead. */}
          <section className="fh-hero__map" aria-labelledby="coverage-heading">
            <div className="fh-hero__map-head">
              <h2 id="coverage-heading" className="fh-hero__map-title">
                Where the research comes from
              </h2>
              <p className="fh-hero__map-sub">
                Submissions by Geographic Area Coordination Center (GACC). Select
                one to open the projects filed there.
              </p>
            </div>
            {/* The fallback reserves the map's height so the stats band below does
                not jump upward and then back down as the chunk lands. */}
            <Suspense
              fallback={
                <div className="fh-hero__map-loading" role="status">
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
          </section>
          </div>
        </div>

        <span className="fh-hero__horizon" aria-hidden="true" />
      </section>

      {/* ---- Live stats ---- */}
      <section className="fh-stats" aria-label="Hub statistics">
        <div className="fh-stats__row fh-container">
          {stats.map((stat) => (
            <div key={stat.id} className="fh-stat-pane fh-glass fh-glass--raised">
              <StatCounter
                value={resolveStatValue(stat, content)}
                label={stat.label}
                caption={stat.caption}
                accent={stat.accent}
              />
            </div>
          ))}
        </div>
      </section>

      {/* ---- Topic summary cards ---- */}
      <section className="fh-topics fh-container" id="topic-areas" aria-labelledby="topics-heading">
        <div className="fh-topics__intro">
          {topicSection.eyebrow && <span className="fh-eyebrow">{topicSection.eyebrow}</span>}
          <h2 id="topics-heading" className="fh-topics__heading">
            {topicSection.heading}
          </h2>
          {topicSection.body && <p className="fh-topics__body">{topicSection.body}</p>}
        </div>

        <div className="fh-topics__grid">
          {cards.map(({ topic, needs, updatedAt, projectCount, sourceCount, model }) => (
            <article
              key={topic.key}
              className="fh-topic-card fh-glass"
              style={{ '--card-tint': TOPICS[topic.key].tint } as CSSProperties}
            >
              <span className="fh-topic-card__wash" aria-hidden="true" />
              <TopicTag topic={topic.key} label={topic.short} />
              {/* The link sits on the title and is stretched over the whole card by
                  CSS. A card-wide <a> would work too, but it would announce the tag,
                  every need and the date as one link name — this keeps the accessible
                  name to the topic while the click target stays the full card. */}
              <h3 className="fh-topic-card__title">
                <Link className="fh-topic-card__link" to={`/topics/${topic.key}`}>
                  {topic.label}
                </Link>
              </h3>
              <ul className="fh-topic-card__needs">
                {needs.map((need, i) => (
                  <li key={i}>{need}</li>
                ))}
              </ul>
              {model && (
                <p className="fh-topic-card__provenance">
                  <span className="fh-topic-card__provenance-dot" aria-hidden="true" />
                  {sourceCount
                    ? `AI-synthesized from ${sourceCount} submissions`
                    : 'AI-synthesized from submissions'}
                </p>
              )}
              <div className="fh-topic-card__foot">
                <p className="fh-topic-card__updated">
                  {projectCount} {projectCount === 1 ? 'project' : 'projects'}
                  {updatedAt && (
                    <>
                      {' · reviewed '}
                      <time dateTime={updatedAt}>{formatReviewDate(updatedAt)}</time>
                    </>
                  )}
                </p>
                <span className="fh-topic-card__cue" aria-hidden="true">
                  View topic area
                  <Icon name="arrow-right" size={15} />
                </span>
              </div>
            </article>
          ))}
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
