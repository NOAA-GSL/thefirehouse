#!/usr/bin/env node
/**
 * Writes the JSON Schemas for a project and a topic, generated from the `Project`,
 * `TopicContent` and `TopicSummary` types in `src/content/types.ts`, to
 * `project.schema.json` and `topic.schema.json` in `wp-content/plugins/firehouse/shared/`.
 *
 *   node scripts/project-schema.mjs            write the files
 *   node scripts/project-schema.mjs --check    fail if either file is out of date
 *
 * The plugin registers each property as post meta, apart from the ones the post itself
 * holds (a project's slug and published flag), and checks every write against the
 * property's schema with WordPress's own validator. One property departs from the
 * type: the plugin stores a need's `projects` as post IDs and swaps in its own schema
 * (includes/class-meta.php). Each schema is self-contained (no `$ref`), because
 * WordPress's schema validation doesn't follow references.
 *
 * It uses the TypeScript compiler the site already builds with, so it adds no
 * dependency. It covers what those types use: strings, numbers, booleans, string
 * literal unions, arrays and nested interfaces. Anything else stops the script, so a
 * new kind of field can't slip through as an empty schema.
 */

import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { basename, dirname, resolve } from 'node:path';
import ts from 'typescript';

const HERE = dirname(fileURLToPath(import.meta.url));
const TYPES = resolve(HERE, '../src/content/types.ts');
const OUT = resolve(HERE, '../wp-content/plugins/firehouse/shared/project.schema.json');
const TOPIC_OUT = resolve(HERE, '../wp-content/plugins/firehouse/shared/topic.schema.json');

const program = ts.createProgram([TYPES], {
  target: ts.ScriptTarget.ES2022,
  module: ts.ModuleKind.ESNext,
  moduleResolution: ts.ModuleResolutionKind.Bundler,
  resolveJsonModule: true,
  strict: true,
  noEmit: true,
  skipLibCheck: true,
});
const checker = program.getTypeChecker();

function description(symbol) {
  const text = ts.displayPartsToString(symbol.getDocumentationComment(checker));
  return text ? text.replace(/\s+/g, ' ').trim() : undefined;
}

function schemaOf(type, where) {
  if (type.flags & ts.TypeFlags.Boolean) return { type: 'boolean' };
  if (type.flags & ts.TypeFlags.String) return { type: 'string' };
  if (type.flags & ts.TypeFlags.Number) return { type: 'number' };

  if (type.isUnion()) {
    const members = type.types.filter((member) => !(member.flags & ts.TypeFlags.Undefined));
    if (members.length === 1) return schemaOf(members[0], where);
    if (members.every((member) => member.flags & ts.TypeFlags.BooleanLiteral)) {
      return { type: 'boolean' };
    }
    if (members.every((member) => member.isStringLiteral())) {
      return { type: 'string', enum: members.map((member) => member.value) };
    }
  }

  if (checker.isArrayType(type)) {
    return { type: 'array', items: schemaOf(checker.getTypeArguments(type)[0], `${where}[]`) };
  }

  if (type.flags & ts.TypeFlags.Object) {
    const properties = {};
    const required = [];
    for (const property of type.getProperties()) {
      const declaration = property.valueDeclaration ?? property.declarations?.[0];
      const schema = schemaOf(
        checker.getTypeOfSymbolAtLocation(property, declaration),
        `${where}.${property.name}`,
      );
      const text = description(property);
      properties[property.name] = text ? { description: text, ...schema } : schema;
      if (!(property.flags & ts.SymbolFlags.Optional)) required.push(property.name);
    }
    // Draft 4 doesn't allow an empty `required` list.
    return {
      type: 'object',
      properties,
      ...(required.length ? { required } : {}),
      additionalProperties: false,
    };
  }

  throw new Error(`${where}: no JSON Schema for type "${checker.typeToString(type)}".`);
}

function schemaOfInterface(name) {
  const source = program.getSourceFile(TYPES);
  const declaration = source.statements.find(
    (statement) => ts.isInterfaceDeclaration(statement) && statement.name.text === name,
  );
  if (!declaration) throw new Error(`No \`${name}\` interface in src/content/types.ts.`);
  return schemaOf(checker.getTypeAtLocation(declaration.name), name);
}

function header(from) {
  return {
    $schema: 'http://json-schema.org/draft-04/schema#',
    $comment: `Generated from ${from} in src/content/types.ts by scripts/project-schema.mjs. Edit the type and run \`npm run schema\`, not this file.`,
  };
}

function buildProjectSchema() {
  return { ...header('the Project type'), title: 'Project', ...schemaOfInterface('Project') };
}

/**
 * A topic's fields, as the plugin stores them on an fh_topic post: TopicContent and
 * TopicSummary in one object. The key and label are the post's slug and title, and
 * the summary's topic is the post itself, so those three aren't fields.
 */
function buildTopicSchema() {
  const content = schemaOfInterface('TopicContent');
  const summary = schemaOfInterface('TopicSummary');
  const shared = Object.keys(content.properties).filter((name) => name in summary.properties);
  if (shared.length) {
    throw new Error(`TopicContent and TopicSummary both have ${shared.join(', ')}; one would hide the other.`);
  }
  const properties = { ...content.properties, ...summary.properties };
  for (const name of ['key', 'label', 'topic']) delete properties[name];
  const required = [...content.required, ...summary.required].filter((name) => name in properties);
  return {
    ...header('the TopicContent and TopicSummary types'),
    title: 'Topic',
    type: 'object',
    properties,
    required,
    additionalProperties: false,
  };
}

const outputs = [
  { path: OUT, schema: buildProjectSchema() },
  { path: TOPIC_OUT, schema: buildTopicSchema() },
];

for (const { path, schema } of outputs) {
  const text = `${JSON.stringify(schema, null, 2)}\n`;
  const name = basename(path);
  if (process.argv.includes('--check')) {
    let current = '';
    try {
      current = readFileSync(path, 'utf8');
    } catch {
      // Missing counts as out of date.
    }
    if (current !== text) {
      console.error(`${name} is out of date with src/content/types.ts. Run: npm run schema`);
      process.exitCode = 1;
    } else {
      console.log(`${name} is up to date.`);
    }
  } else {
    writeFileSync(path, text);
    console.log(`Wrote ${path}`);
  }
}
