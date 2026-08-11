import { useEffect } from 'react';
import { Link } from 'react-router-dom';
import { Button, Icon, StatCounter, TopicTag } from '../design-system';
import { useContent } from '../content/ContentProvider';
import { buildTopicCards, resolveStatValue } from '../content/derive';
import { formatReviewDate } from '../content/format';
import type { LinkRef } from '../content/types';
import './LandingPage.css';

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
  const { hero, stats, topicSection, submitBand, needsPerCard } = content.landing;
  const cards = buildTopicCards(content, needsPerCard);
  const heroImage = hero.imageUrl ?? packagedHero;

  useEffect(() => {
    document.title = `${content.settings.siteName} — Fire Weather Social Science Hub | NOAA GSL`;
  }, [content.settings.siteName]);

  return (
    <>
      {/* ---- Hero ---- */}
      <section className="fh-hero">
        <div className="fh-hero__media" aria-hidden="true">
          {heroImage && <img className="fh-hero__image" src={heroImage} alt={hero.imageAlt} />}
          <div className="fh-hero__scrim" />
        </div>

        <div className="fh-hero__content fh-container">
          <span className="fh-hero__eyebrow">{hero.eyebrow}</span>
          <h1 className="fh-hero__heading">{hero.heading}</h1>
          <p className="fh-hero__body">{hero.body}</p>
          <div className="fh-hero__actions">
            <CtaButton cta={hero.primaryCta} variant="accent" size="lg" iconLeft="plus" blockOnMobile />
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
      </section>

      {/* ---- Live stats ---- */}
      <section className="fh-stats" aria-label="Hub statistics">
        <div className="fh-stats__row fh-container">
          {stats.map((stat) => (
            <StatCounter
              key={stat.id}
              value={resolveStatValue(stat, content)}
              label={stat.label}
              caption={stat.caption}
              accent={stat.accent}
            />
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
          {cards.map(({ topic, needs, updatedAt, projectCount }) => (
            <article key={topic.key} className="fh-topic-card">
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
        <div className="fh-cta-band__inner fh-container">
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
