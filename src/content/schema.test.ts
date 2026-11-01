import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

/**
 * Today's projects must pass the schema the plugin registers its post meta with, or
 * the seed import would be refused. The checker covers the keywords
 * `scripts/project-schema.mjs` writes: type, enum, properties, required,
 * additionalProperties and items.
 */

interface Schema {
  type?: 'object' | 'array' | 'string' | 'number' | 'boolean';
  enum?: unknown[];
  properties?: Record<string, Schema>;
  required?: string[];
  additionalProperties?: boolean;
  items?: Schema;
}

const read = (path: string) =>
  JSON.parse(readFileSync(fileURLToPath(new URL(path, import.meta.url)), 'utf8')) as unknown;

const schema = read('../../wp-content/plugins/firehouse/shared/project.schema.json') as Schema;
const projects = read('./data/projects.json') as { slug: string }[];

function problems(value: unknown, s: Schema, where: string): string[] {
  const kind = Array.isArray(value) ? 'array' : value === null ? 'null' : typeof value;
  if (s.type && kind !== s.type) return [`${where} is ${kind}, not ${s.type}`];
  if (s.enum && !s.enum.includes(value)) return [`${where} is "${String(value)}", not one of ${s.enum.join(', ')}`];

  if (kind === 'array' && s.items) {
    return (value as unknown[]).flatMap((item, i) => problems(item, s.items!, `${where}[${i}]`));
  }
  if (kind === 'object' && s.properties) {
    const object = value as Record<string, unknown>;
    return [
      ...(s.required ?? []).filter((key) => !(key in object)).map((key) => `${where}.${key} is missing`),
      ...Object.entries(object).flatMap(([key, child]) =>
        s.properties![key]
          ? problems(child, s.properties![key], `${where}.${key}`)
          : s.additionalProperties === false
            ? [`${where}.${key} isn't in the schema`]
            : [],
      ),
    ];
  }
  return [];
}

describe('shared/project.schema.json', () => {
  it.each(projects.map((project) => [project.slug, project]))('accepts %s', (slug, project) => {
    expect(problems(project, schema, slug)).toEqual([]);
  });

  it('refuses what WordPress should refuse', () => {
    const [first] = projects;
    expect(problems({ ...first, firePhases: ['winter'] }, schema, 'p')).toHaveLength(1);
    expect(problems({ ...first, completionYear: '2025' }, schema, 'p')).toHaveLength(1);
    expect(problems({ ...first, surprise: true }, schema, 'p')).toHaveLength(1);
    const { slug: _slug, ...noSlug } = first;
    expect(problems(noSlug, schema, 'p')).toEqual(['p.slug is missing']);
  });
});
