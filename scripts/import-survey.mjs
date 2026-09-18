#!/usr/bin/env node
/**
 * Qualtrics export (.xlsx or .csv)  ->  src/content/data/projects.json
 *
 *   node scripts/import-survey.mjs path/to/export.xlsx [--dry] [--offline]
 *
 * ---------------------------------------------------------------------------
 * Why this exists
 * ---------------------------------------------------------------------------
 * There is no backend. This script *is* the pipeline: it is the seam where survey
 * data becomes site content, and it is deliberately a plain Node file with no
 * dependencies so that it can be read, audited and re-hosted by whoever takes over.
 *
 * When the integration goes live, the replacement fetches rows from the Qualtrics
 * API instead of an export file and writes the same JSON. Everything downstream —
 * `normalize.ts` and the whole app — is unchanged. That boundary is the point.
 *
 * ---------------------------------------------------------------------------
 * Rules this script enforces that the UI cannot
 * ---------------------------------------------------------------------------
 * 1. **Private answers never leave this file.** Q2 (email), Q3 (job title) and Q10
 *    (how results were shared — internal use only) are not read at all, and neither
 *    are Qualtrics' own IP address and location columns. They are dropped here rather
 *    than hidden in the UI, because a field that is merely not rendered still ships
 *    inside projects.json for anyone to read.
 * 2. **Excluded rows are reported, not silently dropped.** `normalize.ts` filters
 *    non-IRB records at runtime as a backstop, but a row that is excluded should be
 *    *visible* to the person running the import — a silent drop looks identical to a
 *    parsing bug.
 * 3. **The researcher's words are copied, never rewritten.** Parsing here only ever
 *    *segments* text (one need/recommendation pair per entry, the recommendation
 *    sentence apart from the need); it never trims, merges or rephrases it.
 *
 * ---------------------------------------------------------------------------
 * Two inputs
 * ---------------------------------------------------------------------------
 *  - The export, for everything the researcher wrote.
 *  - `scripts/survey-editorial.json`, keyed by ResponseId, for the decisions the
 *    survey does not ask for: whether the record is live on the site, and optional
 *    overrides. Re-running an import never loses those decisions.
 *
 * Topic areas are not assigned here or anywhere per project. They are synthesized
 * across the whole collection (see `TopicSummary` in src/content/types.ts).
 *
 * Papers are resolved over the network (DOI metadata, then the page's own citation
 * tags) so the project page can show a title instead of a bare link. `--offline`
 * skips that; the links still render, just labelled by host.
 *
 * It then regenerates `notebooklm/firehouse-findings.md`, the source document for the
 * topic-area synthesis (see scripts/notebooklm-source.mjs). Needs Node 23.6+.
 */

import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, extname, resolve } from 'node:path';
import { inflateRawSync } from 'node:zlib';
import { NOTEBOOK_SOURCE, writeNotebookSource } from './notebooklm-source.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const OUT = resolve(HERE, '../src/content/data/projects.json');
const EDITORIAL = resolve(HERE, 'survey-editorial.json');

/* ------------------------------------------------------------------ mapping -- */

/**
 * Project field -> Qualtrics column id, from the live survey export of 2026-09-14.
 *
 * Numbered columns (`Q15_1`…) are one text box each. They are listed as prefixes and
 * gathered in column order, so adding a fifth takeaway box to the survey needs no
 * change here.
 */
const QUESTION_MAP = {
  responseId: 'ResponseId',
  submitter: 'Q1', // "First Last" — must be a co-author
  // Q2 email and Q3 job title — PRIVATE. Intentionally absent.
  org: 'Q4',
  title: 'Q5',
  coResearchers: 'Q6', // "First Last, First Last"
  completionYear: 'Q7',
  irbApproved: 'Q8',
  firePhases: 'Q9', // multi-select
  // Q10 — how results were shared — INTERNAL ONLY. Intentionally absent.
  projectType: 'Q11',
  projectTypeOther: 'Q11_4_TEXT',
  methods: 'Q12', // multi-select
  methodsOther: 'Q12_6_TEXT',
  regions: 'Q13', // multi-select of GACC recode values — drives the coverage map
  abstract: 'Q14',
};

const MULTI_COLUMN = {
  takeaways: 'Q15_', // Q15_1 … Q15_4
  needs: 'Q16_', // Q16_1 … Q16_10
  papers: 'Q17_', // Q17_1 … Q17_5
};

/**
 * Fire cycle phase answers -> taxonomy keys.
 *
 * Matched on the word before the parenthetical ("Before (Pre-fire Planning, …)"),
 * so the examples in the survey wording can be edited without breaking the import.
 * An unmatched answer is warned about — which is how a new survey option shows up.
 */
const FIRE_PHASE_MAP = {
  before: 'before',
  during: 'during',
  after: 'after',
  'long-term': 'long-term',
};

const REGION_CODES = [
  'AICC', 'NWCC', 'ONCC', 'OSCC', 'NRCC',
  'GBCC', 'SWCC', 'RMCC', 'EACC', 'SACC',
  'PACIFIC', 'NATIONAL', 'INTL', 'UNKNOWN',
];

const PUBLICATION_STATUSES = ['published', 'in-review', 'in-preparation', 'unpublished'];

/* ------------------------------------------------------------ export files -- */

/**
 * Minimal RFC 4180 parser.
 *
 * Hand-rolled rather than pulled from npm because survey abstracts are long free
 * text full of commas, quotes and newlines — a naive `split(',')` mangles exactly
 * the field this site cares most about — and a build-time dependency here would be
 * one more thing for a federal review to ask about.
 */
function parseCsv(text) {
  const rows = [];
  let row = [];
  let field = '';
  let quoted = false;

  for (let i = 0; i < text.length; i += 1) {
    const char = text[i];

    if (quoted) {
      if (char === '"') {
        if (text[i + 1] === '"') {
          field += '"';
          i += 1;
        } else {
          quoted = false;
        }
      } else {
        field += char;
      }
      continue;
    }

    if (char === '"') quoted = true;
    else if (char === ',') {
      row.push(field);
      field = '';
    } else if (char === '\n' || char === '\r') {
      // Close the row on the first of a \r\n pair, skip the partner.
      if (char === '\r' && text[i + 1] === '\n') i += 1;
      row.push(field);
      rows.push(row);
      row = [];
      field = '';
    } else field += char;
  }

  if (field !== '' || row.length > 0) {
    row.push(field);
    rows.push(row);
  }
  return rows;
}

/**
 * Reads the first worksheet of an .xlsx as rows of strings.
 *
 * Qualtrics' default download is Excel, and asking researchers-turned-editors to
 * re-export as CSV is exactly the kind of step that gets skipped. An .xlsx is a zip
 * of XML, and Node ships the inflater, so reading one needs ~60 lines rather than a
 * dependency. Only what a Qualtrics export uses is supported: shared and inline
 * strings, and plain numbers.
 */
function readXlsx(buffer) {
  const files = unzip(buffer);
  const sheetName = [...files.keys()]
    .filter((name) => /^xl\/worksheets\/sheet\d+\.xml$/.test(name))
    .sort()[0];
  if (!sheetName) throw new Error('No worksheet found in the .xlsx file.');

  const text = (xml) =>
    [...xml.matchAll(/<t\b[^>]*>([\s\S]*?)<\/t>/g)].map((m) => decodeXml(m[1])).join('');

  const shared = files.has('xl/sharedStrings.xml')
    ? [...files.get('xl/sharedStrings.xml').matchAll(/<si>([\s\S]*?)<\/si>/g)].map((m) => text(m[1]))
    : [];

  const rows = [];
  for (const rowMatch of files.get(sheetName).matchAll(/<row\b[^>]*>([\s\S]*?)<\/row>/g)) {
    const row = [];
    for (const cell of rowMatch[1].matchAll(/<c\b([^>]*?)(?:\/>|>([\s\S]*?)<\/c>)/g)) {
      const attrs = cell[1];
      const inner = cell[2] ?? '';
      const ref = attrs.match(/\br="([A-Z]+)\d+"/)?.[1];
      const type = attrs.match(/\bt="(\w+)"/)?.[1];
      const raw = inner.match(/<v>([\s\S]*?)<\/v>/)?.[1];

      let value = '';
      if (type === 's') value = shared[Number(raw)] ?? '';
      else if (type === 'inlineStr') value = text(inner);
      else if (raw !== undefined) value = decodeXml(raw);

      row[ref ? columnIndex(ref) : row.length] = value;
    }
    rows.push(Array.from(row, (value) => value ?? ''));
  }
  return rows;
}

function columnIndex(letters) {
  return [...letters].reduce((n, char) => n * 26 + char.charCodeAt(0) - 64, 0) - 1;
}

function decodeXml(value) {
  return value
    .replace(/&#x([0-9a-f]+);/gi, (_, hex) => String.fromCodePoint(parseInt(hex, 16)))
    .replace(/&#(\d+);/g, (_, dec) => String.fromCodePoint(Number(dec)))
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&apos;/g, "'")
    .replace(/&amp;/g, '&');
}

/** Just enough of the zip format to read an .xlsx: the central directory, stored or deflated. */
function unzip(buffer) {
  let end = buffer.length - 22;
  while (end >= 0 && buffer.readUInt32LE(end) !== 0x06054b50) end -= 1;
  if (end < 0) throw new Error('Not a zip file — is this really an .xlsx export?');

  const files = new Map();
  let offset = buffer.readUInt32LE(end + 16);
  for (let i = 0, count = buffer.readUInt16LE(end + 10); i < count; i += 1) {
    const method = buffer.readUInt16LE(offset + 10);
    const size = buffer.readUInt32LE(offset + 20);
    const nameLength = buffer.readUInt16LE(offset + 28);
    const skip = nameLength + buffer.readUInt16LE(offset + 30) + buffer.readUInt16LE(offset + 32);
    const local = buffer.readUInt32LE(offset + 42);
    const name = buffer.toString('utf8', offset + 46, offset + 46 + nameLength);

    const start = local + 30 + buffer.readUInt16LE(local + 26) + buffer.readUInt16LE(local + 28);
    const data = buffer.subarray(start, start + size);
    if (name.endsWith('.xml')) {
      files.set(name, (method === 8 ? inflateRawSync(data) : data).toString('utf8'));
    }
    offset += 46 + skip;
  }
  return files;
}

/* ----------------------------------------------------------------- helpers -- */

const clean = (value) => String(value ?? '').trim();

/**
 * Splits a Qualtrics multi-select answer.
 *
 * Qualtrics joins choices with bare commas, and the choice LABELS contain commas of
 * their own — "Before (Pre-fire Planning, Prevention, and Mitigation (e.g., …))" is
 * one answer, not four. Every comma in these labels sits inside parentheses, so only
 * commas at depth zero are separators.
 */
function splitChoices(value) {
  const out = [];
  let depth = 0;
  let current = '';
  for (const char of clean(value)) {
    if (char === '(') depth += 1;
    if (char === ')') depth = Math.max(0, depth - 1);
    if (char === ',' && depth === 0) {
      out.push(current);
      current = '';
    } else current += char;
  }
  out.push(current);
  return out.map((part) => part.trim()).filter(Boolean);
}

/** Collapses runs of whitespace. Changes spacing only — never a word. */
const tidy = (value) => clean(value).replace(/\s+/g, ' ');

/**
 * "First Last" names -> structured authors, where that can be done honestly.
 *
 * The survey asks for "First Last", so a two-word name splits cleanly. A longer one
 * does not: "Laura Sample McMeeking" has a two-word surname, which a last-space
 * split would render as "McMeeking, L. S." in a citation — confidently wrong. Those
 * are kept verbatim as `name`, and the citation builder prints them as written.
 */
function toAuthor(name) {
  const parts = tidy(name).split(' ');
  if (parts.length !== 2) return { name: tidy(name) };
  return { name: tidy(name), given: parts[0], family: parts[1] };
}

function parseAuthors(submitter, coResearchers) {
  const seen = new Set();
  return [clean(submitter), ...clean(coResearchers).split(/[,;]/)]
    .map(tidy)
    .filter((name) => {
      const key = name.toLowerCase();
      if (!name || seen.has(key)) return false;
      seen.add(key);
      return true;
    })
    .map(toAuthor);
}

function slugify(title, taken) {
  const words = clean(title)
    .toLowerCase()
    .normalize('NFKD')
    .replace(/[^\w\s-]/g, ' ')
    .split(/[\s_-]+/)
    .filter(Boolean);

  // Cut at a word boundary: a slug is a public URL people read aloud and cite.
  let base = '';
  for (const word of words) {
    if (base && base.length + word.length + 1 > 60) break;
    base = base ? `${base}-${word}` : word;
  }
  // A cut that lands after "and" or "of" reads as a typo in the address bar.
  base = base.replace(/(?:-(?:a|an|and|for|in|of|on|or|the|to|with|within))+$/, '') || 'project';

  // Slugs are public URLs people cite, so a collision must not silently overwrite.
  let slug = base;
  let n = 2;
  while (taken.has(slug)) slug = `${base}-${n++}`;
  taken.add(slug);
  return slug;
}

/** The abstract's opening sentence, as a seed for the grid tile's one-liner. */
function firstSentence(abstract) {
  const match = abstract.match(/^[\s\S]*?[a-z0-9)”"][.!?](?=\s+[A-Z])/);
  const sentence = (match ? match[0] : abstract).trim();
  if (sentence.length <= 240) return sentence;
  return `${sentence.slice(0, 237).replace(/\s+\S*$/, '')}…`;
}

/**
 * One need/recommendation text box -> entries.
 *
 * The survey asks for "Need for [topic] among [group]: [gap]. Recommend [solution]."
 * in each box. Two things real responses do anyway, both handled here:
 *  - Two entries pasted into one box, separated by a run of spaces. Split, because
 *    rendering them as one card would pair the second need with the first
 *    recommendation.
 *  - A stand-alone need or a stand-alone recommendation — explicitly allowed.
 *
 * The recommendation is everything from the first sentence that begins "Recommend".
 * The split point is a sentence boundary, so both halves stay verbatim.
 */
function parseNeeds(cells, where, warnings) {
  const entries = [];
  for (const cell of cells) {
    const pieces = clean(cell).split(/\s{2,}(?=Need\b)/);
    if (pieces.length > 1) {
      warnings.push(`${where}: one needs box held ${pieces.length} entries — split them.`);
    }
    for (const piece of pieces.map(tidy).filter(Boolean)) {
      const at = piece.search(/(?:^|(?<=[.!?:;)"”’]\s))Recommend/);
      const need = at === -1 ? piece : piece.slice(0, at).trim();
      const recommendation = at === -1 ? '' : piece.slice(at).trim();
      entries.push({
        ...(need ? { need } : {}),
        ...(recommendation ? { recommendation } : {}),
      });
    }
  }
  return entries;
}

/* ------------------------------------------------------------------ papers -- */

const first = (value) => (Array.isArray(value) ? value[0] : value);

/** One retry: repository sites are slow often enough that a single miss means little. */
async function fetchText(url, accept, attempt = 1) {
  try {
    const response = await fetch(url, {
      headers: { Accept: accept, 'User-Agent': 'FirehouseImporter/1.0 (NOAA GSL)' },
      redirect: 'follow',
      signal: AbortSignal.timeout(30000),
    });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    return await response.text();
  } catch (error) {
    if (attempt >= 2) throw error;
    return fetchText(url, accept, attempt + 1);
  }
}

async function resolveDoi(doi) {
  const csl = JSON.parse(
    await fetchText(`https://doi.org/${doi}`, 'application/vnd.citationstyles.csl+json'),
  );
  return {
    title: tidy(first(csl.title)),
    container: tidy(first(csl['container-title'])) || tidy(csl.publisher) || undefined,
    year: csl.issued?.['date-parts']?.[0]?.[0],
  };
}

/** Reads the citation tags a repository or journal page publishes about itself. */
async function resolvePage(url) {
  const html = await fetchText(url, 'text/html');
  const meta = (name) =>
    html.match(new RegExp(`<meta[^>]+(?:name|property)=["']${name}["'][^>]*content=["']([^"']*)["']`, 'i'))?.[1];
  const title = meta('citation_title') ?? meta('og:title');
  return {
    title: title ? tidy(decodeXml(title)) : undefined,
    container: meta('og:site_name') ? tidy(decodeXml(meta('og:site_name'))) : undefined,
    doi: meta('citation_doi')?.replace(/^doi:/i, ''),
  };
}

/**
 * Repository links whose DOI can be read straight off the address. Resolving those
 * through doi.org is faster and more reliable than scraping the repository page —
 * Zenodo's own pages regularly time out for scripted requests.
 */
const DOI_FROM_URL = [[/^https?:\/\/(?:www\.)?zenodo\.org\/(?:records|record)\/(\d+)/i, (id) => `10.5281/zenodo.${id}`]];

/**
 * One link box -> a Paper.
 *
 * The survey collects links only, so the title comes from the link's own metadata.
 * A failed lookup is not an error: the link is still rendered, labelled by its host,
 * and the warning tells whoever is running the import that a title is missing.
 */
async function parsePaper(raw, where, warnings, offline, known) {
  const value = clean(raw);
  const url = value.match(/\bhttps?:\/\/\S+/)?.[0];
  const doiInLink = value.match(/\b(10\.\d{4,9}\/[^\s"<>]+)/)?.[1]?.replace(/[.,;]$/, '');
  const derived = url && DOI_FROM_URL.map(([re, toDoi]) => url.match(re) && toDoi(url.match(re)[1])).find(Boolean);
  const doi = doiInLink ?? derived;
  if (!doi && !url) {
    warnings.push(`${where}: "${value}" is not a link or DOI — skipped.`);
    return null;
  }

  // Keep the researcher's own link beside a DOI we derived from it.
  const paper = doi ? { doi, ...(derived ? { url } : {}) } : { url };
  // What the last import resolved for this link. Used when a lookup can't run or
  // fails, so a slow repository site never strips a title out of committed data.
  const previous = known.get(url ?? doi) ?? known.get(doi);
  const reuse = () => Object.assign(paper, { ...previous, ...paper });
  if (offline) return previous ? reuse() : paper;

  try {
    const found = doi ? await resolveDoi(doi) : await resolvePage(url);
    if (found.doi && !doi) paper.doi = found.doi;
    for (const key of ['title', 'container', 'year']) {
      if (found[key]) paper[key] = found[key];
    }
    if (!paper.title) warnings.push(`${where}: no title published at ${doi ?? url}.`);
  } catch (error) {
    if (previous) reuse();
    warnings.push(
      `${where}: could not resolve ${doi ?? url} (${error.message})` +
        (previous ? ' — kept the metadata from the last import.' : '.'),
    );
  }
  // Keep the link the researcher gave when the DOI came from the page: it is the
  // address they chose, and the DOI is shown beside it.
  if (paper.doi && url && !doi) paper.url = url;
  return paper;
}

const TRUTHY = new Set(['yes', 'y', 'true', '1', 'approved', 'irb approved']);

/* -------------------------------------------------------------------- main -- */

async function main() {
  const [, , inputPath, ...flags] = process.argv;
  if (!inputPath) {
    console.error('Usage: node scripts/import-survey.mjs <export.xlsx|export.csv> [--dry] [--offline]');
    process.exit(1);
  }

  const path = resolve(process.cwd(), inputPath);
  const rows =
    extname(path).toLowerCase() === '.xlsx'
      ? readXlsx(readFileSync(path))
      : parseCsv(readFileSync(path, 'utf8'));

  const editorial = JSON.parse(readFileSync(EDITORIAL, 'utf8'));

  // Paper metadata from the previous import, keyed by the link the researcher gave.
  const known = new Map();
  if (existsSync(OUT)) {
    for (const paper of JSON.parse(readFileSync(OUT, 'utf8')).flatMap((p) => p.papers ?? [])) {
      // A `url` is only stored when the researcher gave a link rather than a DOI.
      if (paper.title) known.set(paper.url ?? paper.doi, paper);
    }
  }
  const offline = flags.includes('--offline');

  // Row 0 holds the column ids QUESTION_MAP is keyed to. Qualtrics then writes one or
  // two more header rows (question text, an import-id blob) depending on export
  // settings, so responses are found by their ResponseId rather than by counting.
  const header = rows[0].map(clean);
  const column = Object.fromEntries(header.map((name, i) => [name, i]));
  const at = (row, field) => {
    const index = column[QUESTION_MAP[field]];
    return index === undefined ? '' : clean(row[index]);
  };
  const series = (row, prefix) =>
    header
      .map((name, i) => [name, i])
      .filter(([name]) => new RegExp(`^${prefix}\\d+$`).test(name))
      .map(([, i]) => clean(row[i]))
      .filter(Boolean);

  const missing = [
    ...Object.entries(QUESTION_MAP).filter(([, col]) => column[col] === undefined),
    ...Object.entries(MULTI_COLUMN).filter(([, prefix]) => !header.some((h) => h.startsWith(prefix))),
  ].map(([field, col]) => `${field} (${col})`);
  if (missing.length) {
    console.warn(`⚠ Columns not found in the export: ${missing.join(', ')}`);
    console.warn('  Update QUESTION_MAP at the top of this script.\n');
  }

  const responses = rows.slice(1).filter((row) => /^R_\w+$/.test(at(row, 'responseId')));
  const warnings = [];
  const excluded = [];
  const taken = new Set();
  const projects = [];

  for (const row of responses) {
    const responseId = at(row, 'responseId');
    const title = tidy(at(row, 'title'));
    const where = `${responseId} ("${title.slice(0, 40)}")`;
    const edit = editorial[responseId] ?? {};

    // ---- Rule 2: exclusions are reported, never silent. ----------------------
    if (!title) {
      excluded.push(`${where} — no project title`);
      continue;
    }
    if (!TRUTHY.has(at(row, 'irbApproved').toLowerCase())) {
      excluded.push(`${where} — no IRB approval or exemption`);
      continue;
    }

    const year = Number.parseInt(at(row, 'completionYear'), 10);
    if (Number.isNaN(year)) {
      warnings.push(`${where}: unreadable completion year — skipped.`);
      continue;
    }

    const firePhases = splitChoices(at(row, 'firePhases'))
      .map((answer) => {
        const key = FIRE_PHASE_MAP[answer.split(/\s*\(/)[0].trim().toLowerCase()];
        if (!key) warnings.push(`${where}: unmapped fire cycle phase "${answer.slice(0, 50)}".`);
        return key;
      })
      .filter(Boolean);

    const regions = splitChoices(at(row, 'regions')).map((answer) => {
      const code = answer.toUpperCase();
      if (REGION_CODES.includes(code)) return code;
      // A region that fails to map is a submission that vanishes from the coverage
      // map — the one failure this column exists to prevent — so it is filed
      // under UNKNOWN, where it is at least listed, rather than nowhere.
      warnings.push(
        `${where}: unrecognised region "${answer}" — recorded as UNKNOWN. ` +
          'Check that Q13 exports recode values, not labels.',
      );
      return 'UNKNOWN';
    });

    // "Other" choices carry their text in a sibling column; show that, not "Other".
    const other = (answer, text) => (/^other\b/i.test(answer) && text ? text : answer);
    const projectType = other(at(row, 'projectType'), at(row, 'projectTypeOther'));
    const methods = splitChoices(at(row, 'methods')).map((m) => other(m, at(row, 'methodsOther')));

    const abstract = tidy(at(row, 'abstract'));
    const papers = [];
    for (const link of series(row, MULTI_COLUMN.papers)) {
      const paper = await parsePaper(link, where, warnings, offline, known);
      if (paper) papers.push(paper);
    }
    if (papers.length === 0) {
      warnings.push(`${where}: no publication link — the inclusion criteria require one.`);
    }

    projects.push({
      // The ResponseId, not a running number: it survives re-imports and re-ordering,
      // and it is the key that ties a record back to its row in Qualtrics.
      id: responseId,
      slug: edit.slug ?? slugify(title, taken),
      title,
      // Editorial one-liner for the grid tile. Seeded with the abstract's first
      // sentence (verbatim) so a fresh import renders; override in the editorial file.
      summary: edit.summary ?? firstSentence(abstract),
      abstract,
      authors: parseAuthors(at(row, 'submitter'), at(row, 'coResearchers')),
      ...(at(row, 'org') ? { org: tidy(at(row, 'org')) } : {}),
      completionYear: year,
      firePhases: [...new Set(firePhases)],
      ...(PUBLICATION_STATUSES.includes(edit.publicationStatus)
        ? { publicationStatus: edit.publicationStatus }
        : {}),
      ...(projectType ? { projectType: tidy(projectType) } : {}),
      methods: methods.map(tidy),
      irbApproved: true,
      geo: {
        regions: [...new Set(regions)],
        ...(edit.geoNote ? { note: edit.geoNote } : {}),
      },
      takeaways: series(row, MULTI_COLUMN.takeaways).map(tidy),
      needs: parseNeeds(series(row, MULTI_COLUMN.needs), where, warnings),
      papers,
      ...(edit.fullRecordUrl ? { fullRecordUrl: edit.fullRecordUrl } : {}),
      // Records stage as unpublished unless the editorial file says otherwise, so an
      // import can never put unreviewed research on a live federal site by accident.
      published: edit.published === true,
    });
  }

  console.log(`Parsed ${projects.length} project(s) from ${responses.length} response(s).`);
  if (excluded.length) {
    console.log(`\nExcluded ${excluded.length} response(s):`);
    for (const line of excluded) console.log(`  · ${line}`);
  }
  if (warnings.length) {
    console.log(`\n⚠ ${warnings.length} warning(s):`);
    for (const line of warnings) console.log(`  · ${line}`);
  }

  if (flags.includes('--dry')) {
    console.log('\n--dry: nothing written.');
    console.log(JSON.stringify(projects, null, 2));
    return;
  }

  writeFileSync(OUT, `${JSON.stringify(projects, null, 2)}\n`);
  const live = projects.filter((p) => p.published).length;
  console.log(`\nWrote ${OUT}`);
  console.log(`${live} of ${projects.length} published — set "published" in survey-editorial.json.`);

  // Keep the NotebookLM source in step with the site. After changing only
  // survey-editorial.json, run `node scripts/notebooklm-source.mjs` on its own.
  writeNotebookSource();
  console.log(`Wrote ${NOTEBOOK_SOURCE} — re-upload it to the notebook before the next synthesis.`);
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
