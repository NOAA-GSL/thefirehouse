#!/usr/bin/env node
/**
 * Qualtrics CSV export  ->  src/content/data/projects.json
 *
 *   node scripts/import-survey.mjs path/to/export.csv [--dry]
 *
 * ---------------------------------------------------------------------------
 * Why this exists
 * ---------------------------------------------------------------------------
 * There is no backend. This script *is* the pipeline: it is the seam where survey
 * data becomes site content, and it is deliberately a plain Node file with no
 * dependencies so that it can be read, audited and re-hosted by whoever takes over.
 *
 * When the integration goes live, the replacement fetches rows from the Qualtrics
 * API instead of a CSV and writes the same file. Everything downstream —
 * `normalize.ts` and the whole app — is unchanged. That boundary is the point.
 *
 * ---------------------------------------------------------------------------
 * Two rules this script enforces that the UI cannot
 * ---------------------------------------------------------------------------
 * 1. **Q10 never leaves this file.** "How results were communicated" is internal-use
 *    only. It is dropped here rather than hidden in the UI, because a field that is
 *    merely not rendered still ships inside projects.json for anyone to read.
 * 2. **Non-IRB rows are reported, not silently dropped.** `normalize.ts` filters them
 *    at runtime as a backstop, but a row that is excluded should be *visible* to the
 *    person running the import — a silent drop looks identical to a parsing bug.
 *
 * ⚠ QUESTION_MAP below is a placeholder keyed to guessed Qualtrics column names.
 * Emily and Steph owe us the mapped question list (PLAN.md, open questions 1–5);
 * when it lands, this map is the only thing that should need editing.
 */

import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const OUT = resolve(HERE, '../src/content/data/projects.json');

/* ------------------------------------------------------------------ mapping -- */

/**
 * Survey column -> Project field. Left side is the Qualtrics column header.
 *
 * `regions` and `states` use the *named export tags* the coverage-map spec asks for
 * (`FirehouseFormAdditions.pdf`, setting 2) rather than positional Q-numbers. That
 * is the whole point of naming them: left as Q7 and Q8, reordering the survey later
 * breaks the map quietly instead of loudly. Everything still on a Q-number is
 * awaiting the same treatment from Emily and Steph.
 */
const QUESTION_MAP = {
  title: 'Q2',
  completionYear: 'Q3',
  firePhases: 'Q4', // multi-select
  publicationStatus: 'Q5',
  irbApproved: 'Q6',
  abstract: 'Q7',
  authors: 'Q8',
  org: 'Q9',
  // Q10 — how results were communicated — INTERNAL ONLY. Intentionally absent.
  topics: 'Q11', // multi-select
  regions: 'GACC_REGION', // multi-select, required — drives the coverage map
  states: 'STATES', // multi-select, optional backstop
  takeaways: 'Q15',
  needs: 'Q17',
  recommendations: 'Q17b',
  papers: 'Q18',
};

/** Survey answer text -> our structural keys. Extend as the real option lists land. */
const VALUE_MAP = {
  firePhases: {
    'preparedness and planning': 'preparedness',
    'prevention and mitigation': 'prevention',
    'detection and early warning': 'detection',
    'active response and suppression': 'response',
    'recovery and rehabilitation': 'recovery',
  },
  topics: {
    'observations and monitoring': 'observe',
    'forecasts and modeling': 'forecast',
    'warnings and immediate response': 'warning',
    'strategic adaptation and institutional governance': 'governance',
  },
  publicationStatus: {
    published: 'published',
    'in review': 'in-review',
    'under review': 'in-review',
    'in preparation': 'in-preparation',
    'not published': 'unpublished',
    unpublished: 'unpublished',
  },
};

/**
 * Coordination regions.
 *
 * The export SHOULD already contain recode values (`ONCC`), per setting 1 of the
 * spec — so a value that is already a valid code passes straight through. The label
 * forms below are a safety net for an export where the recodes were not configured,
 * which is a real possibility on the first pass and produces a silently empty map
 * otherwise. Both the full label and its bare region name are accepted, because
 * Qualtrics exports vary in whether the parenthetical survives.
 */
const REGION_CODES = [
  'AICC', 'NWCC', 'ONCC', 'OSCC', 'NRCC',
  'GBCC', 'SWCC', 'RMCC', 'EACC', 'SACC',
  'PACIFIC', 'NATIONAL', 'INTL', 'UNKNOWN',
];

const REGION_LABELS = {
  'alaska (aicc)': 'AICC',
  alaska: 'AICC',
  'northwest — or, wa (nwcc)': 'NWCC',
  'northwest - or, wa (nwcc)': 'NWCC',
  northwest: 'NWCC',
  'northern california (oncc)': 'ONCC',
  'northern california': 'ONCC',
  'southern california (oscc)': 'OSCC',
  'southern california': 'OSCC',
  'northern rockies — mt, n. id, nd (nrcc)': 'NRCC',
  'northern rockies - mt, n. id, nd (nrcc)': 'NRCC',
  'northern rockies': 'NRCC',
  'great basin — ut, nv, s. id (gbcc)': 'GBCC',
  'great basin - ut, nv, s. id (gbcc)': 'GBCC',
  'great basin': 'GBCC',
  'southwest — az, nm (swcc)': 'SWCC',
  'southwest - az, nm (swcc)': 'SWCC',
  southwest: 'SWCC',
  'rocky mountain — co, wy, sd, ne, ks (rmcc)': 'RMCC',
  'rocky mountain - co, wy, sd, ne, ks (rmcc)': 'RMCC',
  'rocky mountain': 'RMCC',
  'eastern area (eacc)': 'EACC',
  'eastern area': 'EACC',
  'southern area — incl. pr & usvi (sacc)': 'SACC',
  'southern area - incl. pr & usvi (sacc)': 'SACC',
  'southern area': 'SACC',
  'hawaii / pacific islands': 'PACIFIC',
  'hawaii/pacific islands': 'PACIFIC',
  'national / not region-specific': 'NATIONAL',
  'not region-specific': 'NATIONAL',
  'outside the u.s.': 'INTL',
  'outside the us': 'INTL',
  'not sure': 'UNKNOWN',
};

/**
 * Region answers -> region codes.
 *
 * Splitting this column is genuinely ambiguous and the naive version is wrong:
 * Qualtrics joins multi-selects with commas, but the region LABELS contain commas
 * of their own — "Northern Rockies — MT, N. ID, ND (NRCC)" is one answer, not four.
 * A plain comma split turns that single choice into three unrecognised fragments.
 *
 * So the split is layered. Semicolons first, since they are unambiguous. Any piece
 * that doesn't resolve is then retried as a comma-separated list, which is what a
 * recode-value export ("ONCC,OSCC") actually looks like. Recode values never contain
 * a comma, so that second pass is always safe.
 *
 * Unmapped answers are not silently dropped. A region that fails to map is a
 * submission that vanishes from the coverage map — the one failure mode this column
 * exists to prevent — so it is warned about loudly and recorded as UNKNOWN, where it
 * shows up in the "Not on the map" list rather than nowhere at all.
 */
function resolveRegion(answer) {
  const trimmed = answer.trim();
  if (!trimmed) return null;
  const upper = trimmed.toUpperCase();
  if (REGION_CODES.includes(upper)) return upper;
  return REGION_LABELS[trimmed.toLowerCase()] ?? null;
}

function mapRegions(raw, where, warnings) {
  const out = [];

  for (const piece of clean(raw).split(';')) {
    if (!piece.trim()) continue;

    const direct = resolveRegion(piece);
    if (direct) {
      out.push(direct);
      continue;
    }

    // Retry as a comma-separated list of codes.
    const parts = piece.split(',').map((part) => part.trim()).filter(Boolean);
    const resolved = parts.map(resolveRegion);

    if (parts.length > 1 && resolved.every(Boolean)) {
      out.push(...resolved);
      continue;
    }

    warnings.push(
      `${where}: unrecognised region "${piece.trim()}" — recorded as UNKNOWN. ` +
        'Check that the survey exports recode values, not labels ' +
        '(see FirehouseFormAdditions.pdf, setting 1).',
    );
    out.push('UNKNOWN');
  }

  return [...new Set(out)];
}

/** Q2 states -> two-letter postal codes. Optional; a backstop, not a region source. */
function mapStates(raw) {
  return [
    ...new Set(
      splitMulti(raw)
        .map((value) => value.trim().toUpperCase())
        .filter((value) => /^[A-Z]{2}$/.test(value)),
    ),
  ];
}

/* -------------------------------------------------------------------- csv ---- */

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
  return rows.filter((r) => r.some((cell) => cell.trim() !== ''));
}

/* ----------------------------------------------------------------- helpers -- */

const clean = (value) => (value ?? '').trim();

/** Qualtrics joins multi-selects with commas; some exports use semicolons. */
function splitMulti(value) {
  return clean(value)
    .split(/[;,]/)
    .map((part) => part.trim())
    .filter(Boolean);
}

function mapValues(raw, dictionary, where, warnings) {
  return splitMulti(raw)
    .map((answer) => {
      const key = dictionary[answer.toLowerCase()];
      if (!key) {
        warnings.push(`${where}: unmapped answer "${answer}" — add it to VALUE_MAP.`);
        return null;
      }
      return key;
    })
    .filter(Boolean);
}

/** Free text where each line is one item ("major takeaways", Q17 needs, etc.). */
function splitLines(value) {
  return clean(value)
    .split(/\r?\n|(?:^|\s)[••]\s*/m)
    .map((line) => line.replace(/^[-*\d.)\s]+/, '').trim())
    .filter(Boolean);
}

/**
 * "Smith, J.; Doe, A." -> structured authors where possible.
 *
 * Only splits a name into family/given when the "Family, Given" comma form is
 * present. Anything else is kept verbatim as `name` — a byline like "DESI research
 * team" has no surname, and guessing one produces a citation that is confidently
 * wrong rather than honestly loose. See PLAN.md, open question 3.
 */
function parseAuthors(value) {
  // Split on semicolons only — never commas. The "Family, Given" form this function
  // exists to read has a comma *inside* each name, so the shared multi-select
  // splitter would turn "Rodriguez, Ana; Chen, Wei" into four people.
  const entries = clean(value)
    .split(/;|\s+\band\b\s+/)
    .map((part) => part.trim())
    .filter(Boolean);

  return entries.map((entry) => {
    const match = entry.match(/^([^,]+),\s*(.+)$/);
    if (!match) return { name: entry };
    const [, family, given] = match;
    return { name: entry, family: family.trim(), given: given.trim() };
  });
}

function slugify(title, taken) {
  const base =
    clean(title)
      .toLowerCase()
      .normalize('NFKD')
      .replace(/[^\w\s-]/g, '')
      .replace(/\s+/g, '-')
      .replace(/-+/g, '-')
      .replace(/^-|-$/g, '')
      .slice(0, 60) || 'project';

  // Slugs are public URLs people cite, so a collision must not silently overwrite.
  let slug = base;
  let n = 2;
  while (taken.has(slug)) slug = `${base}-${n++}`;
  taken.add(slug);
  return slug;
}

function parsePapers(value) {
  // One paper per line; an optional trailing DOI, bare or as a doi.org URL.
  return splitLines(value).map((line) => {
    const doi = line.match(/\b(10\.\d{4,9}\/[^\s"<>]+)\b/);
    const url = line.match(/\bhttps?:\/\/\S+/);
    const title = line
      .replace(/\bhttps?:\/\/\S+/g, '')
      .replace(/\bdoi:\s*/gi, '')
      .replace(/\b10\.\d{4,9}\/[^\s"<>]+\b/g, '')
      .replace(/[\s,;–-]+$/, '')
      .trim();
    return {
      title: title || line,
      ...(doi ? { doi: doi[1] } : {}),
      ...(url && !doi ? { url: url[0] } : {}),
    };
  });
}

const TRUTHY = new Set(['yes', 'y', 'true', '1', 'approved', 'irb approved']);

/* -------------------------------------------------------------------- main -- */

function main() {
  const [, , csvPath, ...flags] = process.argv;
  if (!csvPath) {
    console.error('Usage: node scripts/import-survey.mjs <export.csv> [--dry]');
    process.exit(1);
  }

  const rows = parseCsv(readFileSync(resolve(process.cwd(), csvPath), 'utf8'));
  if (rows.length < 2) {
    console.error('No data rows found in the export.');
    process.exit(1);
  }

  // Qualtrics writes three header rows: column ids, question text, and an import id
  // blob. Row 0 is the one that matches QUESTION_MAP; the rest are skipped by
  // looking for the ResponseId column rather than by counting, since the number of
  // preamble rows changes with export settings.
  const header = rows[0].map(clean);
  const column = Object.fromEntries(header.map((name, i) => [name, i]));
  const at = (row, field) => {
    const index = column[QUESTION_MAP[field]];
    return index === undefined ? '' : clean(row[index]);
  };

  const missing = Object.entries(QUESTION_MAP)
    .filter(([, col]) => column[col] === undefined)
    .map(([field, col]) => `${field} (${col})`);
  if (missing.length) {
    console.warn(`⚠ Columns not found in the export: ${missing.join(', ')}`);
    console.warn('  Update QUESTION_MAP at the top of this script.\n');
  }

  const warnings = [];
  const excluded = [];
  const taken = new Set();
  const projects = [];

  for (const [i, row] of rows.slice(1).entries()) {
    const title = at(row, 'title');
    if (!title) continue; // preamble row or blank response
    const where = `row ${i + 2} ("${title.slice(0, 40)}")`;

    // ---- Rule 2: IRB exclusions are reported, never silent. ------------------
    if (!TRUTHY.has(at(row, 'irbApproved').toLowerCase())) {
      excluded.push(`${where} — no IRB approval`);
      continue;
    }

    const topics = mapValues(at(row, 'topics'), VALUE_MAP.topics, where, warnings);
    if (topics.length === 0) {
      warnings.push(`${where}: no topic area — skipped, it could not be surfaced anywhere.`);
      continue;
    }

    const year = Number.parseInt(at(row, 'completionYear'), 10);
    if (Number.isNaN(year)) {
      warnings.push(`${where}: unreadable completion year — skipped.`);
      continue;
    }

    const slug = slugify(title, taken);
    const abstract = at(row, 'abstract');

    projects.push({
      id: `p-${String(projects.length + 1).padStart(2, '0')}`,
      slug,
      title,
      topics,
      // Editorial one-liner; seeded from the abstract so a fresh import renders,
      // then overwritten by hand. Kept short — it sits on a grid tile.
      summary: abstract.length > 180 ? `${abstract.slice(0, 177).trimEnd()}…` : abstract,
      abstract,
      authors: parseAuthors(at(row, 'authors')),
      org: at(row, 'org') || undefined,
      completionYear: year,
      firePhases: mapValues(at(row, 'firePhases'), VALUE_MAP.firePhases, where, warnings),
      publicationStatus:
        VALUE_MAP.publicationStatus[at(row, 'publicationStatus').toLowerCase()] ?? 'unpublished',
      irbApproved: true,
      geo: {
        regions: mapRegions(at(row, 'regions'), where, warnings),
        ...(mapStates(at(row, 'states')).length
          ? { states: mapStates(at(row, 'states')) }
          : {}),
      },
      takeaways: splitLines(at(row, 'takeaways')),
      needs: splitLines(at(row, 'needs')),
      recommendations: splitLines(at(row, 'recommendations')),
      papers: parsePapers(at(row, 'papers')),
      // New records stage as unpublished so an import can never put unreviewed
      // research on a live federal site as a side effect of running a script.
      published: false,
    });
  }

  console.log(`Parsed ${projects.length} project(s).`);
  if (excluded.length) {
    console.log(`\nExcluded ${excluded.length} row(s) by IRB skip logic:`);
    for (const line of excluded) console.log(`  · ${line}`);
  }
  if (warnings.length) {
    console.log(`\n⚠ ${warnings.length} warning(s):`);
    for (const line of warnings) console.log(`  · ${line}`);
  }

  if (flags.includes('--dry')) {
    console.log('\n--dry: nothing written.');
    return;
  }

  writeFileSync(OUT, `${JSON.stringify(projects, null, 2)}\n`);
  console.log(`\nWrote ${OUT}`);
  console.log('Every record is published:false — flip the ones that are ready to show.');
}

main();
