import { useEffect } from 'react';
import { Button, Icon } from '../design-system';
import { useContent } from '../content/ContentProvider';
import type { LinkRef, TeamMember } from '../content/types';
import './AboutPage.css';

/**
 * Portraits, discovered rather than imported.
 *
 * Same arrangement as the landing hero's photograph: the binaries are not in the
 * repository yet, and a static import of a missing file fails the build. A glob
 * keeps the page shippable while the photos are outstanding, and picking them up
 * later is a file drop with no code change. See `src/assets/people/README.md`.
 */
const PORTRAITS = import.meta.glob('../assets/people/*.{png,jpg,jpeg,webp,avif}', {
  eager: true,
  query: '?url',
  import: 'default',
}) as Record<string, string>;

function portraitFor(member: TeamMember): string | undefined {
  if (member.photoUrl) return member.photoUrl;
  if (!member.photo) return undefined;
  const match = Object.entries(PORTRAITS).find(
    ([path]) => path.split('/').pop()?.replace(/\.[^.]+$/, '') === member.photo,
  );
  return match?.[1];
}

/**
 * Initials for the stand-in monogram.
 *
 * Honorifics are stripped so "Dr. Emily Wells" reads EW rather than DE — the
 * monogram is only useful if it matches the name printed beside it.
 */
function initials(name: string): string {
  return name
    .replace(/^(?:Dr|Prof|Mr|Ms|Mrs)\.?\s+/i, '')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join('');
}

function ProcessLink({ link }: { link: LinkRef }) {
  const content = (
    <>
      {link.label}
      <Icon name="arrow-right" size={15} />
    </>
  );
  if (link.to) {
    return (
      <Button variant="link" size="md" to={link.to} className="fh-about__process-link">
        {content}
      </Button>
    );
  }
  return (
    <Button variant="link" size="md" href={link.href ?? '#'} className="fh-about__process-link">
      {content}
    </Button>
  );
}

/**
 * About — what the hub is, who it serves, how it works, and who maintains it.
 *
 * Everything on the page comes from `about.json` through the content layer, for the
 * same reason the landing page does: this is the copy most likely to need a change
 * without a developer — a revised description of the audience, a new name on the
 * team.
 *
 * The biographies are rendered verbatim, one paragraph per stored string, with no
 * excerpt and no derived job title. They were written by the people they describe;
 * the page's job is to present them, not to edit them.
 */
export function AboutPage() {
  const content = useContent();
  const { hero, mission, audiences, process, team, cta } = content.about;

  useEffect(() => {
    document.title = `About — ${content.settings.siteName}`;
  }, [content.settings.siteName]);

  return (
    <div className="fh-about">
      {/* ---- Introduction ---- */}
      <section className="fh-about__hero">
        <div className="fh-about__hero-inner fh-container">
          {hero.eyebrow && <span className="fh-eyebrow">{hero.eyebrow}</span>}
          <h1 className="fh-about__heading">{hero.heading}</h1>
          {hero.body && <p className="fh-about__lede">{hero.body}</p>}
        </div>
      </section>

      <div className="fh-about__body fh-container">
        {/* ---- Why it exists ---- */}
        <section className="fh-about__section" aria-labelledby="about-mission">
          <h2 id="about-mission" className="fh-about__section-heading">
            {mission.heading}
          </h2>
          <div className="fh-about__prose">
            {mission.paragraphs.map((paragraph, i) => (
              <p key={i}>{paragraph}</p>
            ))}
          </div>
        </section>

        {/* ---- Who it serves ---- */}
        <section className="fh-about__section" aria-labelledby="about-audiences">
          <div className="fh-about__section-intro">
            {audiences.eyebrow && <span className="fh-eyebrow">{audiences.eyebrow}</span>}
            <h2 id="about-audiences" className="fh-about__section-heading">
              {audiences.heading}
            </h2>
            {audiences.body && <p className="fh-about__section-body">{audiences.body}</p>}
          </div>

          <ul className="fh-about__audiences">
            {audiences.items.map((item) => (
              <li key={item.title} className="fh-audience fh-glass">
                <span className="fh-audience__icon" aria-hidden="true">
                  <Icon name={item.icon} size={20} />
                </span>
                <h3 className="fh-audience__title">{item.title}</h3>
                <p className="fh-audience__body">{item.body}</p>
              </li>
            ))}
          </ul>
        </section>

        {/* ---- How it works ----
            An ordered list because the steps are a sequence, not a set: a project is
            submitted before it can be synthesized, and synthesized before it can be
            traced. The numerals are drawn by CSS from the list itself, so the order
            a screen reader announces and the order the eye reads are the same one. */}
        <section className="fh-about__section" aria-labelledby="about-process">
          <div className="fh-about__section-intro">
            {process.eyebrow && <span className="fh-eyebrow">{process.eyebrow}</span>}
            <h2 id="about-process" className="fh-about__section-heading">
              {process.heading}
            </h2>
            {process.body && <p className="fh-about__section-body">{process.body}</p>}
          </div>

          <ol className="fh-about__steps">
            {process.steps.map((step, i) => (
              <li key={step.title} className="fh-step">
                <span className="fh-step__rank" aria-hidden="true">
                  {i + 1}
                </span>
                <div className="fh-step__main">
                  <h3 className="fh-step__title">{step.title}</h3>
                  <p className="fh-step__body">{step.body}</p>
                </div>
              </li>
            ))}
          </ol>

          {process.links.length > 0 && (
            <div className="fh-about__process-links">
              {process.links.map((link) => (
                <ProcessLink key={link.label} link={link} />
              ))}
            </div>
          )}
        </section>

        {/* ---- The team ---- */}
        <section className="fh-about__section" aria-labelledby="about-team">
          <div className="fh-about__section-intro">
            {team.eyebrow && <span className="fh-eyebrow">{team.eyebrow}</span>}
            <h2 id="about-team" className="fh-about__section-heading">
              {team.heading}
            </h2>
            {team.body && <p className="fh-about__section-body">{team.body}</p>}
          </div>

          <ul className="fh-about__team">
            {team.members.map((member) => {
              const portrait = portraitFor(member);
              return (
                <li key={member.name} className="fh-person fh-glass">
                  {/* Portrait and name share a row, and the biography runs the full
                      width of the card beneath them. Beside a portrait the bio was
                      reading at about 43 characters a line — under the comfortable
                      floor for a paragraph this long. */}
                  <div className="fh-person__head">
                    <div className="fh-person__portrait">
                      {portrait ? (
                        /* Alt is empty by default: the name is the very next thing in
                           the reading order, so describing the photo as "Emily Wells"
                           would announce it twice. `photoAlt` overrides when a portrait
                           starts carrying information of its own. */
                        <img
                          className="fh-person__photo"
                          src={portrait}
                          alt={member.photoAlt ?? ''}
                          loading="lazy"
                          decoding="async"
                        />
                      ) : (
                        <span className="fh-person__monogram" aria-hidden="true">
                          {initials(member.name)}
                        </span>
                      )}
                    </div>
                    <h3 className="fh-person__name">{member.name}</h3>
                  </div>
                  <div className="fh-person__bio">
                    {member.bio.map((paragraph, i) => (
                      <p key={i}>{paragraph}</p>
                    ))}
                  </div>
                </li>
              );
            })}
          </ul>
        </section>
      </div>

      {/* ---- Submit CTA ---- */}
      <section className="fh-about__cta-section fh-container" aria-labelledby="about-cta">
        <div className="fh-about__cta fh-glass">
          <span className="fh-about__cta-glow" aria-hidden="true" />
          <div>
            <h2 id="about-cta" className="fh-about__cta-heading">
              {cta.heading}
            </h2>
            <p className="fh-about__cta-body">{cta.body}</p>
          </div>
          {cta.cta.to ? (
            <Button variant="accent" size="lg" iconLeft="plus" to={cta.cta.to} blockOnMobile>
              {cta.cta.label}
            </Button>
          ) : (
            <Button
              variant="accent"
              size="lg"
              iconLeft="plus"
              href={cta.cta.href ?? '#'}
              blockOnMobile
            >
              {cta.cta.label}
            </Button>
          )}
        </div>
      </section>
    </div>
  );
}
