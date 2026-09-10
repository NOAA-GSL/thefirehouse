import type { Author, Project } from './types';

/**
 * Citation builders for the X-ray page.
 *
 * This is the whole point of giving each project its own URL (walkthrough: "each
 * project opens on its own unique URL page for citability"). A stable address is
 * only half of it — the other half is handing people a correctly formatted string
 * so they don't hand-assemble one and get it wrong.
 *
 * ---------------------------------------------------------------------------
 * On author names
 * ---------------------------------------------------------------------------
 * `Author.family` is optional, because the survey may capture a free-text byline
 * ("DESI research team") rather than parsed names. Where it is missing, every
 * builder falls back to printing `name` verbatim instead of guessing which word is
 * the surname.
 *
 * That is a deliberate trade: the result is not strictly APA, but it is *true*. A
 * guessed surname produces a citation that is confidently wrong, which is worse
 * than one that is honestly loose — and worse still because nobody would catch it.
 * See PLAN.md, open question 3: if the survey starts capturing structured names,
 * these functions upgrade automatically with no code change.
 */

export type CitationFormat = 'apa' | 'chicago' | 'bibtex';

export const CITATION_FORMATS: { key: CitationFormat; label: string }[] = [
  { key: 'apa', label: 'APA 7' },
  { key: 'chicago', label: 'Chicago' },
  { key: 'bibtex', label: 'BibTeX' },
];

/** True when we have real name parts and can format to a standard properly. */
function isStructured(author: Author): boolean {
  return Boolean(author.family);
}

/** "Rodriguez, A." — APA's surname-plus-initials form. */
function apaName(author: Author): string {
  if (!isStructured(author)) return author.name;
  const initials = (author.given ?? '')
    .split(/[\s.]+/)
    .filter(Boolean)
    .map((part) => `${part[0].toUpperCase()}.`)
    .join(' ');
  return initials ? `${author.family}, ${initials}` : author.family!;
}

/**
 * APA 7 author strings.
 *
 * Two rules that are easy to miss and that reviewers do catch: the list is joined
 * with an ampersand before the final name, and at 21+ authors APA wants the first
 * nineteen, an ellipsis, then the *last* author — not "et al.".
 */
function apaAuthors(authors: Author[]): string {
  const names = authors.map(apaName);
  if (names.length === 0) return 'The Firehouse';
  if (names.length === 1) return names[0];
  if (names.length === 2) return `${names[0]}, & ${names[1]}`;
  if (names.length <= 20) {
    return `${names.slice(0, -1).join(', ')}, & ${names[names.length - 1]}`;
  }
  return `${names.slice(0, 19).join(', ')}, ... ${names[names.length - 1]}`;
}

/** Chicago: first author inverted, the rest in natural order. */
function chicagoAuthors(authors: Author[]): string {
  if (authors.length === 0) return 'The Firehouse';

  const natural = (a: Author) =>
    isStructured(a) ? [a.given, a.family].filter(Boolean).join(' ') : a.name;
  const first = isStructured(authors[0])
    ? [authors[0].family, authors[0].given].filter(Boolean).join(', ')
    : authors[0].name;

  if (authors.length === 1) return first;
  if (authors.length <= 3) {
    const rest = authors.slice(1).map(natural);
    return `${first}, and ${rest.join(', ')}`;
  }
  return `${first}, et al.`;
}

const PUBLISHER = 'NOAA Global Systems Laboratory';
const SITE = 'The Firehouse';

export interface CitationInput {
  project: Project;
  /** The project's canonical absolute URL. Pass `window.location.href`. */
  url: string;
}

function apa({ project, url }: CitationInput): string {
  const doi = project.papers.find((paper) => paper.doi)?.doi;
  // A DOI supersedes the site URL when one exists: it is the durable identifier,
  // and a citation should point at the thing that will still resolve in ten years.
  const locator = doi ? `https://doi.org/${doi}` : url;
  return `${apaAuthors(project.authors)} (${project.completionYear}). ${project.title}. ${SITE}, ${PUBLISHER}. ${locator}`;
}

function chicago({ project, url }: CitationInput): string {
  const doi = project.papers.find((paper) => paper.doi)?.doi;
  const locator = doi ? `https://doi.org/${doi}` : url;
  return `${chicagoAuthors(project.authors)}. "${project.title}." ${SITE}, ${PUBLISHER}, ${project.completionYear}. ${locator}.`;
}

function bibtex({ project, url }: CitationInput): string {
  const doi = project.papers.find((paper) => paper.doi)?.doi;

  // BibTeX joins authors with " and " and expects "Family, Given" per name.
  const authors = project.authors
    .map((a) => (isStructured(a) ? [a.family, a.given].filter(Boolean).join(', ') : a.name))
    .join(' and ');

  // Braces around the title stop BibTeX styles from lowercasing proper nouns —
  // without them "NOAA" comes out as "noaa" in most numeric styles.
  const fields = [
    ['author', authors || SITE],
    ['title', `{${project.title}}`],
    ['year', String(project.completionYear)],
    ['institution', PUBLISHER],
    ['howpublished', SITE],
    doi ? ['doi', doi] : ['url', url],
  ].filter(Boolean) as [string, string][];

  const body = fields
    .map(([key, value]) => `  ${key.padEnd(13)}= ${value.startsWith('{') ? value : `{${value}}`}`)
    .join(',\n');

  return `@techreport{${citationKey(project)},\n${body}\n}`;
}

/** A stable BibTeX key: first surname (or slug), then year. */
function citationKey(project: Project): string {
  const first = project.authors[0];
  const stem = first && isStructured(first) ? first.family! : project.slug;
  return `${stem.toLowerCase().replace(/[^a-z0-9]/g, '')}${project.completionYear}`;
}

const BUILDERS: Record<CitationFormat, (input: CitationInput) => string> = {
  apa,
  chicago,
  bibtex,
};

export function buildCitation(format: CitationFormat, input: CitationInput): string {
  return BUILDERS[format](input);
}
