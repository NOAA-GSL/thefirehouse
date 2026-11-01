#!/usr/bin/env node
/**
 * Writes the JSON Schema for a project, generated from the `Project` type in
 * `src/content/types.ts`, to `wp-content/plugins/firehouse/shared/project.schema.json`.
 *
 *   node scripts/project-schema.mjs            write the file
 *   node scripts/project-schema.mjs --check    fail if the file is out of date
 *
 * The plugin passes each property's schema to `register_post_meta`, so WordPress
 * itself rejects a malformed value, and the TypeScript type stays the one place a
 * field is defined. The schema is self-contained (no `$ref`), because WordPress's
 * schema validation doesn't follow references.
 *
 * It uses the TypeScript compiler the site already builds with, so it adds no
 * dependency. It covers what `Project` uses: strings, numbers, booleans, string
 * literal unions, arrays and nested interfaces. Anything else stops the script, so a
 * new kind of field can't slip through as an empty schema.
 */

import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import ts from 'typescript';

const HERE = dirname(fileURLToPath(import.meta.url));
const TYPES = resolve(HERE, '../src/content/types.ts');
const OUT = resolve(HERE, '../wp-content/plugins/firehouse/shared/project.schema.json');

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

function buildSchema() {
  const source = program.getSourceFile(TYPES);
  const project = source.statements.find(
    (statement) => ts.isInterfaceDeclaration(statement) && statement.name.text === 'Project',
  );
  if (!project) throw new Error('No `Project` interface in src/content/types.ts.');

  return {
    $schema: 'http://json-schema.org/draft-04/schema#',
    $comment:
      'Generated from the Project type in src/content/types.ts by scripts/project-schema.mjs. Edit the type and run `npm run schema`, not this file.',
    title: 'Project',
    ...schemaOf(checker.getTypeAtLocation(project.name), 'Project'),
  };
}

const text = `${JSON.stringify(buildSchema(), null, 2)}\n`;

if (process.argv.includes('--check')) {
  let current = '';
  try {
    current = readFileSync(OUT, 'utf8');
  } catch {
    // Missing counts as out of date.
  }
  if (current !== text) {
    console.error('project.schema.json is out of date with src/content/types.ts. Run: npm run schema');
    process.exit(1);
  }
  console.log('project.schema.json is up to date.');
} else {
  writeFileSync(OUT, text);
  console.log(`Wrote ${OUT}`);
}
