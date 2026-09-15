#!/usr/bin/env node
/**
 * projects.json + topics.json  ->  notebooklm/firehouse-findings.md
 *
 *   node scripts/notebooklm-source.mjs
 *
 * Also run automatically at the end of `import-survey.mjs`.
 *
 * ---------------------------------------------------------------------------
 * Why this exists
 * ---------------------------------------------------------------------------
 * Topic-area "top needs" are synthesized by an LLM across every collected project —
 * a Google NotebookLM notebook, per the September 2026 meeting. NotebookLM has no
 * live connection to this repo; it reads documents you upload. So this script
 * writes ONE document holding every published project, and the synthesis step is:
 * upload it as the notebook's source, run the prompt in `notebooklm/README.md`,
 * review, and paste the result into `src/content/data/topicSummaries.json`.
 *
 * Rules:
 *  - **Verbatim.** The researcher's text is copied as-is, same as the project pages.
 *    A synthesis built on an already-paraphrased source compounds the paraphrase.
 *  - **Published projects only** — the same set the site shows. A record that hasn't
 *    been reviewed for the site shouldn't shape the public synthesis either.
 *  - **Nothing private.** It reads projects.json, which never contains emails, job
 *    titles or Q10 (see import-survey.mjs), so there is nothing to strip here.
 *  - **Deterministic.** No timestamp; the same data writes the same bytes, so a
 *    commit shows only what actually changed in the findings.
 *
 * Labels are imported from the site's own `taxonomy.ts` so the document can never
 * describe a region or phase differently from the page. That uses Node's built-in
 * TypeScript support, which needs Node 23.6 or newer.
 */

import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { FIRE_PHASES, REGIONS } from '../src/design-system/taxonomy.ts';

const HERE = dirname(fileURLToPath(import.meta.url));
const DATA = resolve(HERE, '../src/content/data');
export const NOTEBOOK_SOURCE = resolve(HERE, '../notebooklm/firehouse-findings.md');

const byRecency = (a, b) => b.completionYear - a.completionYear || a.title.localeCompare(b.title);

function list(items) {
  return items.map((item) => `- ${item}`).join('\n');
}

function projectSection(project, index) {
  const lines = [
    `## Project ${index + 1}: ${project.title}`,
    '',
    `- **Project ID:** ${project.id}`,
    `- **Page:** /projects/${project.slug}`,
    `- **Researchers:** ${project.authors.map((a) => a.name).join(', ')}`,
  ];
  if (project.org) lines.push(`- **Organization:** ${project.org}`);
  lines.push(`- **Completed:** ${project.completionYear}`);
  if (project.projectType) lines.push(`- **Project type:** ${project.projectType}`);
  if (project.methods?.length) lines.push(`- **Data collection:** ${project.methods.join(', ')}`);
  if (project.firePhases?.length) {
    const phases = project.firePhases.map((key) => `${FIRE_PHASES[key].short} (${FIRE_PHASES[key].label})`);
    lines.push(`- **Fire cycle phases:** ${phases.join('; ')}`);
  }
  if (project.geo?.regions?.length) {
    lines.push(`- **Geographic areas:** ${project.geo.regions.map((key) => REGIONS[key].label).join('; ')}`);
  }

  if (project.abstract) lines.push('', '### Abstract', '', project.abstract);

  if (project.takeaways?.length) {
    lines.push('', '### Major takeaways', '', list(project.takeaways));
  }

  if (project.needs?.length) {
    lines.push('', '### End-user needs and recommendations');
    project.needs.forEach((entry, i) => {
      lines.push('', `**Entry ${i + 1}**`);
      if (entry.need) lines.push('', `Need: ${entry.need}`);
      if (entry.recommendation) lines.push('', `Recommendation: ${entry.recommendation}`);
    });
  }

  if (project.papers?.length) {
    const papers = project.papers.map((paper) => {
      const link = paper.doi ? `https://doi.org/${paper.doi}` : paper.url;
      const meta = [paper.container, paper.year].filter(Boolean).join(', ');
      return [paper.title, meta, link].filter(Boolean).join(' — ');
    });
    lines.push('', '### Publications and resources', '', list(papers));
  }

  return lines.join('\n');
}

export function buildNotebookSource(projects, topics) {
  const published = projects.filter((p) => p.published).sort(byRecency);

  const topicDefinitions = [...topics]
    .sort((a, b) => a.order - b.order)
    .map((topic) =>
      [
        `### ${topic.label}`,
        '',
        topic.description ?? '',
        ...(topic.covers?.length ? ['', 'Covers:', '', list(topic.covers)] : []),
      ].join('\n'),
    )
    .join('\n\n');

  return `${[
    '# The Firehouse — collected project findings',
    '',
    `Source document for the topic-area synthesis. It contains all ${published.length} ` +
      'published projects in The Firehouse, NOAA Global Systems Laboratory\'s hub for ' +
      'fire weather social science. Each project\'s abstract, takeaways, needs and ' +
      'recommendations are reproduced exactly as the researchers submitted them.',
    '',
    'Topic areas are a lens across the whole collection. No project is assigned to a ' +
      'topic area; a single project may inform several areas or none.',
    '',
    '## The four topic areas',
    '',
    topicDefinitions,
    '',
    '---',
    '',
    published.map(projectSection).join('\n\n---\n\n'),
  ].join('\n')}\n`;
}

export function writeNotebookSource() {
  const projects = JSON.parse(readFileSync(resolve(DATA, 'projects.json'), 'utf8'));
  const topics = JSON.parse(readFileSync(resolve(DATA, 'topics.json'), 'utf8'));
  mkdirSync(dirname(NOTEBOOK_SOURCE), { recursive: true });
  writeFileSync(NOTEBOOK_SOURCE, buildNotebookSource(projects, topics));
  return projects.filter((p) => p.published).length;
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const count = writeNotebookSource();
  console.log(`Wrote ${NOTEBOOK_SOURCE} (${count} published project(s)).`);
}
