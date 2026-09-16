import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { Icon, ProjectDetailModal, ProjectTile } from '../design-system';
import { TOPICS, type TopicKey } from '../design-system/topics';
import { useContent } from '../content/ContentProvider';
import { publishedProjects } from '../content/derive';
import { formatByline } from '../content/format';
import {
  FIRE_PHASE_LIST,
  FIRE_PHASES,
  GACC_LIST,
  NON_GACC_LIST,
  PUBLICATION_STATUSES,
  PUBLICATION_STATUS_LIST,
  REGIONS,
} from '../design-system/taxonomy';
import {
  SORT_OPTIONS,
  activeFacetCount,
  effectiveSort,
  facetCounts,
  filterProjects,
  parseProjectQuery,
  serializeProjectQuery,
  type FacetKey,
  type ProjectQuery,
  type ProjectSort,
} from '../content/search';
import './ProjectsPage.css';

/** How long typing pauses before the URL (and the live result count) updates. */
const SEARCH_DEBOUNCE_MS = 200;

interface SelectOption {
  value: string;
  label: string;
  count?: number;
}

interface SelectGroup {
  label: string;
  options: SelectOption[];
}

/**
 * A labelled native `<select>`.
 *
 * Native on purpose: it is the one dropdown that is keyboard-, screen-reader- and
 * touch-correct on every platform with no extra code, and on a phone at AMS it
 * opens the OS picker rather than a tiny custom menu.
 */
function FilterSelect({
  label,
  value,
  onChange,
  allLabel,
  groups,
  markSet = true,
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  allLabel?: string;
  groups: SelectGroup[];
  /** Style a chosen value as an applied filter. Off for sort, which always has one. */
  markSet?: boolean;
}) {
  const id = useId();
  const renderOption = (option: SelectOption) => (
    <option
      key={option.value}
      value={option.value}
      // Zero-result options stay selectable when already chosen, so a stale URL
      // never leaves the control showing a value it can't display.
      disabled={option.count === 0 && option.value !== value}
    >
      {option.count === undefined ? option.label : `${option.label} (${option.count})`}
    </option>
  );

  return (
    <div className={`fh-filter${markSet && value ? ' fh-filter--set' : ''}`}>
      <label className="fh-filter__label" htmlFor={id}>
        {label}
      </label>
      <div className="fh-filter__control">
        <select id={id} value={value} onChange={(e) => onChange(e.target.value)}>
          {allLabel && <option value="">{allLabel}</option>}
          {groups.map((group) =>
            group.label ? (
              <optgroup key={group.label} label={group.label}>
                {group.options.map(renderOption)}
              </optgroup>
            ) : (
              group.options.map(renderOption)
            ),
          )}
        </select>
        <Icon name="chevron-down" size={16} />
      </div>
    </div>
  );
}

/**
 * Project explorer — the "brick and mortar" grid from creative brief §5.2.
 *
 * Search and filters follow the usual pattern for a faceted catalogue:
 *  - one prominent search box first, because most visitors arrive with a word in
 *    mind ("evacuation", a colleague's name) rather than a category;
 *  - topic chips for the one facet everyone understands at a glance;
 *  - the remaining facets as compact dropdowns, each option showing how many
 *    results it would leave, so nobody filters their way into an empty grid;
 *  - a results line that says what is applied, lets each filter be removed on its
 *    own, and offers one "clear all".
 *
 * All of it lives in the query string, so any view is linkable — useful when
 * Stephanie or Emily wants to point someone at "response-phase work in the Great
 * Basin".
 */
export function ProjectsPage() {
  const content = useContent();
  const [searchParams, setSearchParams] = useSearchParams();
  const [openSlug, setOpenSlug] = useState<string | null>(null);
  // Only consulted on narrow screens; the facets are always shown on wide ones.
  const [filtersOpen, setFiltersOpen] = useState(false);
  const facetsId = useId();
  const searchRef = useRef<HTMLInputElement>(null);
  const searchId = useId();

  useEffect(() => {
    document.title = `Explore projects — ${content.settings.siteName}`;
  }, [content.settings.siteName]);

  const query = useMemo(() => parseProjectQuery(searchParams), [searchParams]);

  /* The input is held locally and written to the URL after a short pause. Binding
     the input straight to the URL makes every keystroke a navigation, which can lag
     the caret and would re-announce the result count on each letter. */
  const [text, setText] = useState(query.q);
  const lastWritten = useRef(query.q);

  useEffect(() => {
    // Pick up changes that came from elsewhere (Back, "Clear all", a pasted URL).
    if (query.q !== lastWritten.current) {
      lastWritten.current = query.q;
      setText(query.q);
    }
  }, [query.q]);

  function update(patch: Partial<ProjectQuery>) {
    const next = serializeProjectQuery({ ...query, q: text, ...patch });
    lastWritten.current = next.get('q') ?? '';
    setSearchParams(next, { replace: true });
  }

  useEffect(() => {
    // Compared with what was last *written*, not with the URL as of this render:
    // "Clear all" empties the text and the URL together, and a stale comparison
    // would schedule a write that puts the cleared filters back.
    if (text === lastWritten.current) return;
    const timer = window.setTimeout(() => update({ q: text }), SEARCH_DEBOUNCE_MS);
    return () => window.clearTimeout(timer);
    // Keyed on `text` alone: `update` changes every render, and re-running on it
    // would restart the timer before it could fire.
  }, [text]);

  /* "/" focuses search, as on most catalogue sites — but never while typing. */
  useEffect(() => {
    function onKey(event: KeyboardEvent) {
      if (event.key !== '/' || event.metaKey || event.ctrlKey || event.altKey) return;
      const target = event.target as HTMLElement | null;
      if (target?.closest('input, textarea, select, [contenteditable="true"]')) return;
      event.preventDefault();
      searchRef.current?.focus();
    }
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, []);

  const all = useMemo(() => publishedProjects(content), [content]);
  const projects = useMemo(() => filterProjects(all, query), [all, query]);
  const sort = effectiveSort(query);

  // Drop a stale modal when the result set no longer contains it.
  useEffect(() => {
    if (openSlug && !projects.some((p) => p.slug === openSlug)) setOpenSlug(null);
  }, [projects, openSlug]);

  const openProject = projects.find((p) => p.slug === openSlug) ?? null;

  /* ---- Facet options, each with its would-be result count ---- */
  const topicCounts = useMemo(() => facetCounts(all, query, 'topic', (p) => p.topics), [all, query]);
  const phaseCounts = useMemo(() => facetCounts(all, query, 'phase', (p) => p.firePhases), [all, query]);
  const regionCounts = useMemo(
    () => facetCounts(all, query, 'region', (p) => p.geo?.regions ?? []),
    [all, query],
  );
  const yearCounts = useMemo(() => facetCounts(all, query, 'year', (p) => [p.completionYear]), [all, query]);
  const statusCounts = useMemo(
    () => facetCounts(all, query, 'status', (p) => [p.publicationStatus]),
    [all, query],
  );

  const years = useMemo(
    () => [...new Set(all.map((p) => p.completionYear))].sort((a, b) => b - a),
    [all],
  );

  const topicLabel = (key: TopicKey) =>
    content.topics.find((t) => t.key === key)?.short ?? TOPICS[key].label;

  /* ---- Applied-filter pills ---- */
  const applied: { key: FacetKey; label: string }[] = [];
  if (query.topic) applied.push({ key: 'topic', label: `Topic: ${topicLabel(query.topic)}` });
  if (query.phase) applied.push({ key: 'phase', label: `Phase: ${FIRE_PHASES[query.phase].short}` });
  if (query.region) applied.push({ key: 'region', label: `GACC: ${REGIONS[query.region].short}` });
  if (query.year) applied.push({ key: 'year', label: `Year: ${query.year}` });
  if (query.status) applied.push({ key: 'status', label: `Status: ${PUBLICATION_STATUSES[query.status].label}` });

  const searching = query.q.trim().length > 0;
  const filtered = searching || activeFacetCount(query) > 0;

  function clearAll() {
    setText('');
    lastWritten.current = '';
    setSearchParams(query.sort ? { sort: query.sort } : {}, { replace: true });
    searchRef.current?.focus();
  }

  return (
    <div className="fh-explorer fh-container">
      <header className="fh-explorer__intro">
        <span className="fh-eyebrow">Project explorer</span>
        <h1 className="fh-explorer__heading">Completed research projects</h1>
        <p className="fh-explorer__body">
          Every project analyzed for The Firehouse. Search or filter, then open one to
          read its takeaways, end-user needs, recommendations, and related papers.
        </p>
      </header>

      <search className="fh-explorer__toolbar" aria-label="Search and filter projects">
        {/* ---- Search ---- */}
        <form
          className="fh-search"
          onSubmit={(e) => {
            // Enter applies immediately rather than waiting out the debounce.
            e.preventDefault();
            update({ q: text });
          }}
        >
          <label className="fh-visually-hidden" htmlFor={searchId}>
            Search projects
          </label>
          <Icon name="search" size={18} />
          <input
            ref={searchRef}
            id={searchId}
            className="fh-search__input"
            type="search"
            value={text}
            placeholder="Search projects"
            autoComplete="off"
            spellCheck={false}
            enterKeyHint="search"
            aria-describedby={`${searchId}-hint`}
            onChange={(e) => setText(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Escape' && text) {
                e.preventDefault();
                setText('');
              }
            }}
          />
          {text ? (
            <button
              type="button"
              className="fh-search__clear"
              aria-label="Clear search"
              onClick={() => {
                setText('');
                update({ q: '' });
                searchRef.current?.focus();
              }}
            >
              <Icon name="x" size={16} />
            </button>
          ) : (
            <kbd className="fh-search__kbd" aria-hidden="true">
              /
            </kbd>
          )}
          <span id={`${searchId}-hint`} className="fh-visually-hidden">
            Searches titles, authors, abstracts, and findings. Results update as you type. Press Escape to clear.
          </span>
        </form>

        {/* ---- Topic chips ---- */}
        <div className="fh-explorer__filters" role="group" aria-label="Filter by topic area">
          <button
            type="button"
            className={`fh-chip${query.topic === null ? ' fh-chip--active' : ''}`}
            aria-pressed={query.topic === null}
            onClick={() => update({ topic: null })}
          >
            All topics
          </button>
          {content.topics.map((topic) => {
            const count = topicCounts.get(topic.key) ?? 0;
            const active = query.topic === topic.key;
            return (
              <button
                key={topic.key}
                type="button"
                className={`fh-chip${active ? ' fh-chip--active' : ''}`}
                aria-pressed={active}
                disabled={count === 0 && !active}
                onClick={() => update({ topic: active ? null : topic.key })}
              >
                {topic.short}
                <span className="fh-chip__count">{count}</span>
              </button>
            );
          })}
        </div>

        {/* ---- Secondary facets + sort ----
            On a phone five dropdowns would push the results a full screen down, so
            they fold behind a toggle there. The toggle is hidden on wide screens,
            where the facets are always visible. */}
        <button
          type="button"
          className="fh-explorer__facets-toggle"
          aria-expanded={filtersOpen}
          aria-controls={facetsId}
          onClick={() => setFiltersOpen((open) => !open)}
        >
          More filters and sort
          {activeFacetCount({ ...query, topic: null }) > 0 && (
            <span className="fh-explorer__facets-badge">
              {activeFacetCount({ ...query, topic: null })}
              <span className="fh-visually-hidden"> applied</span>
            </span>
          )}
          <Icon name="chevron-down" size={16} />
        </button>
        <div
          id={facetsId}
          className={`fh-explorer__facets${filtersOpen ? ' fh-explorer__facets--open' : ''}`}
        >
          <FilterSelect
            label="Fire phase"
            value={query.phase ?? ''}
            allLabel="Any phase"
            onChange={(v) => update({ phase: (v || null) as ProjectQuery['phase'] })}
            groups={[
              {
                label: '',
                options: FIRE_PHASE_LIST.map((phase) => ({
                  value: phase.key,
                  label: phase.label,
                  count: phaseCounts.get(phase.key) ?? 0,
                })),
              },
            ]}
          />
          <FilterSelect
            label="GACC"
            value={query.region ?? ''}
            allLabel="Any area"
            onChange={(v) => update({ region: (v || null) as ProjectQuery['region'] })}
            groups={[
              {
                label: 'Geographic Area Coordination Centers',
                options: GACC_LIST.map((region) => ({
                  value: region.key,
                  label: `${region.short} (${region.key})`,
                  count: regionCounts.get(region.key) ?? 0,
                })),
              },
              {
                label: 'Not on the map',
                options: NON_GACC_LIST.map((region) => ({
                  value: region.key,
                  label: region.short,
                  count: regionCounts.get(region.key) ?? 0,
                })),
              },
            ]}
          />
          <FilterSelect
            label="Year completed"
            value={query.year ? String(query.year) : ''}
            allLabel="Any year"
            onChange={(v) => update({ year: v ? Number(v) : null })}
            groups={[
              {
                label: '',
                options: years.map((year) => ({
                  value: String(year),
                  label: String(year),
                  count: yearCounts.get(year) ?? 0,
                })),
              },
            ]}
          />
          <FilterSelect
            label="Publication status"
            value={query.status ?? ''}
            allLabel="Any status"
            onChange={(v) => update({ status: (v || null) as ProjectQuery['status'] })}
            groups={[
              {
                label: '',
                options: PUBLICATION_STATUS_LIST.map((status) => ({
                  value: status.key,
                  label: status.label,
                  count: statusCounts.get(status.key) ?? 0,
                })),
              },
            ]}
          />
          <FilterSelect
            label="Sort by"
            value={sort}
            markSet={false}
            onChange={(v) => update({ sort: v as ProjectSort })}
            groups={[
              {
                label: '',
                // "Best match" only means something while there is text to match.
                options: SORT_OPTIONS.filter((o) => o.key !== 'relevance' || searching).map(
                  (o) => ({ value: o.key, label: o.label }),
                ),
              },
            ]}
          />
        </div>
      </search>

      {/* ---- Result summary ---- */}
      <div className="fh-explorer__summary">
        <p className="fh-explorer__count" role="status">
          {filtered ? (
            <>
              Showing <strong>{projects.length}</strong> of {all.length}{' '}
              {all.length === 1 ? 'project' : 'projects'}
              {searching && (
                <>
                  {' '}
                  matching <strong>“{query.q.trim()}”</strong>
                </>
              )}
            </>
          ) : (
            <>
              <strong>{projects.length}</strong> {projects.length === 1 ? 'project' : 'projects'}
            </>
          )}
          {/* A filtered grid answers "what exists", not "what is this area about" —
              the topic page does that, so offer it once a topic filter is on. */}
          {query.topic && (
            <>
              {' · '}
              <Link className="fh-explorer__topic-link" to={`/topics/${query.topic}`}>
                About {topicLabel(query.topic).toLowerCase()}
              </Link>
            </>
          )}
        </p>

        {(applied.length > 0 || filtered) && (
          <ul className="fh-explorer__applied" aria-label="Applied filters">
            {applied.map((item) => (
              <li key={item.key}>
                <button
                  type="button"
                  className="fh-pill"
                  aria-label={`Remove filter ${item.label}`}
                  onClick={() => update({ [item.key]: null })}
                >
                  {item.label}
                  <Icon name="x" size={14} />
                </button>
              </li>
            ))}
            {filtered && (
              <li>
                <button type="button" className="fh-explorer__clear-all" onClick={clearAll}>
                  Clear all
                </button>
              </li>
            )}
          </ul>
        )}
      </div>

      {projects.length > 0 ? (
        <div className="fh-explorer__grid">
          {projects.map((project) => (
            <ProjectTile
              key={project.id}
              topics={project.topics}
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
        <div className="fh-explorer__empty">
          <p className="fh-explorer__empty-title">
            {filtered ? 'No projects match your search and filters.' : 'No published projects are available yet.'}
          </p>
          {filtered && (
            <p className="fh-explorer__empty-body">
              Try fewer or shorter search words, or remove a filter. Every filter above
              shows how many projects each choice would leave.
            </p>
          )}
          {filtered ? (
            <button type="button" className="fh-explorer__empty-action" onClick={clearAll}>
              Clear search and filters
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
