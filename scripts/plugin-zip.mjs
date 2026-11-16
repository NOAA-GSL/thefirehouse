#!/usr/bin/env node
/**
 * Builds the FireHouse plugin's installable zip, the file WordPress takes at
 * Plugins → Add New Plugin → Upload Plugin.
 *
 *   npm run plugin:zip                      release/firehouse-<version>-<commit>.zip
 *   npm run plugin:zip -- --tag v0.3.0      release/firehouse-0.3.0.zip, and fails
 *                                           unless the tag names the plugin's version
 *
 * The zip holds wp-content/plugins/firehouse as committed, in a firehouse/ folder (the
 * folder WordPress installs it to), with the repository's LICENSE beside firehouse.php.
 * It leaves out what only the plugin's tests read: tests/ and the shared test cases in
 * shared/cases/. It is built with git archive from HEAD, so a change that isn't
 * committed isn't in it; the script says so when there is one. Line endings are the
 * repository's, whatever the checkout's settings, so the same commit gives the same
 * files on any computer.
 *
 * Before it reports the zip, it reads the zip back and checks that it holds every
 * committed plugin file but those left out, the LICENSE, and nothing else: no tests,
 * no file or folder whose name starts with a dot (Plugin Check refuses them), and one
 * firehouse/ folder at the top. The plugin header, the VERSION constant and readme.txt's
 * stable tag must name the same version (tests/VersionTest.php checks the same).
 *
 * In GitHub Actions it writes version, zip (the path), name and sha256 (the zip's
 * SHA-256, which the release job checks before attaching it) to the step's outputs.
 */

import { appendFileSync, mkdirSync, readFileSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { dirname, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const PLUGIN = 'wp-content/plugins/firehouse';
const SLUG = 'firehouse';
const OUT = resolve(ROOT, 'release');

/** Paths in the plugin that stay out of the zip: only the tests read them. */
export const LEFT_OUT = ['tests', 'shared/cases'];

/** Added to the zip from the repository's root, beside firehouse.php. */
const ADDED = ['LICENSE'];

const fail = (message) => {
  console.error(`plugin-zip: ${message}`);
  process.exit(1);
};

// Line endings as committed, whatever core.autocrlf says on this computer.
const git = (args, options = {}) =>
  execFileSync('git', ['-c', 'core.autocrlf=false', '-c', 'core.eol=lf', ...args], {
    cwd: ROOT,
    encoding: options.encoding ?? 'utf8',
    maxBuffer: 64 * 1024 * 1024,
  });

function parseArgs(argv) {
  const args = { tag: '' };
  for (let i = 0; i < argv.length; i++) {
    if (argv[i] === '--tag') args.tag = argv[++i] ?? '';
    else if (argv[i].startsWith('--tag=')) args.tag = argv[i].slice('--tag='.length);
    else fail(`unknown argument ${argv[i]}`);
  }
  return args;
}

/** The version, from the committed files, after checking the three places agree. */
function committedVersion() {
  const header = git(['show', `HEAD:${PLUGIN}/firehouse.php`]);
  const readme = git(['show', `HEAD:${PLUGIN}/readme.txt`]);
  const found = {
    'firehouse.php header': header.match(/^\s*\*\s*Version:\s*(\S+)/m)?.[1],
    'VERSION constant': header.match(/^const VERSION = '([^']+)';/m)?.[1],
    'readme.txt Stable tag': readme.match(/^Stable tag:\s*(\S+)/m)?.[1],
  };
  const versions = new Set(Object.values(found));
  if (versions.size !== 1 || versions.has(undefined)) {
    fail(
      'the version must be the same in all three places:\n' +
        Object.entries(found)
          .map(([where, version]) => `  ${where}: ${version ?? '(not found)'}`)
          .join('\n'),
    );
  }
  const [version] = versions;
  if (!/^\d+\.\d+\.\d+$/.test(version)) fail(`version ${version} isn't major.minor.patch`);
  return version;
}

/**
 * The names in a zip's central directory. git archive writes plain zips (no zip64 at
 * this size), so the end-of-central-directory record is the last 22 bytes plus the
 * comment git puts there (the commit's ID).
 */
export function zipEntries(buffer) {
  let end = -1;
  for (let i = buffer.length - 22; i >= Math.max(0, buffer.length - 22 - 0xffff); i--) {
    if (buffer.readUInt32LE(i) === 0x06054b50) {
      end = i;
      break;
    }
  }
  if (end < 0) throw new Error('no end-of-central-directory record: not a zip');
  const count = buffer.readUInt16LE(end + 10);
  let offset = buffer.readUInt32LE(end + 16);
  const names = [];
  for (let n = 0; n < count; n++) {
    if (buffer.readUInt32LE(offset) !== 0x02014b50) throw new Error('bad central directory entry');
    const nameLength = buffer.readUInt16LE(offset + 28);
    const extraLength = buffer.readUInt16LE(offset + 30);
    const commentLength = buffer.readUInt16LE(offset + 32);
    names.push(buffer.toString('utf8', offset + 46, offset + 46 + nameLength));
    offset += 46 + nameLength + extraLength + commentLength;
  }
  return names;
}

const isLeftOut = (path) => LEFT_OUT.some((dir) => path === dir || path.startsWith(`${dir}/`));

/** What the zip must hold: every committed plugin file but those left out, and ADDED. */
function expectedFiles() {
  const committed = git(['ls-tree', '-r', '--name-only', `HEAD:${PLUGIN}`])
    .split('\n')
    .filter(Boolean);
  for (const name of ADDED) {
    if (committed.includes(name)) fail(`the plugin has its own ${name}, which the zip's would replace`);
  }
  return [...committed.filter((path) => !isLeftOut(path)), ...ADDED].map((path) => `${SLUG}/${path}`);
}

function check(zipPath) {
  const entries = zipEntries(readFileSync(zipPath));
  const files = entries.filter((name) => !name.endsWith('/'));
  const problems = [];

  const outside = entries.filter((name) => !name.startsWith(`${SLUG}/`));
  if (outside.length) problems.push(`outside the ${SLUG}/ folder: ${outside.join(', ')}`);

  const dotted = entries.filter((name) => name.split('/').some((part) => part.startsWith('.')));
  if (dotted.length) problems.push(`names starting with a dot: ${dotted.join(', ')}`);

  const leftOut = files.filter((name) => isLeftOut(name.slice(SLUG.length + 1)));
  if (leftOut.length) problems.push(`should have been left out: ${leftOut.join(', ')}`);

  const expected = new Set(expectedFiles());
  const missing = [...expected].filter((name) => !files.includes(name));
  if (missing.length) problems.push(`missing: ${missing.join(', ')}`);
  const extra = files.filter((name) => !expected.has(name));
  if (extra.length) problems.push(`not expected: ${extra.join(', ')}`);

  for (const required of ['firehouse.php', 'readme.txt', 'uninstall.php', 'LICENSE']) {
    if (!files.includes(`${SLUG}/${required}`)) problems.push(`no ${required}`);
  }

  if (problems.length) fail(`the zip isn't right:\n  ${problems.join('\n  ')}`);
  return files.length;
}

function main() {
  const { tag } = parseArgs(process.argv.slice(2));
  const version = committedVersion();
  if (tag && tag !== `v${version}`) {
    fail(`tag ${tag} doesn't name the plugin's version: tag v${version}, or change the version first`);
  }

  const commit = git(['rev-parse', '--short=7', 'HEAD']).trim();
  const name = tag ? `${SLUG}-${version}.zip` : `${SLUG}-${version}-${commit}.zip`;
  const zipPath = resolve(OUT, name);

  // The LICENSE as committed, not as it is in the working tree.
  const staging = resolve(OUT, '.added');
  rmSync(staging, { recursive: true, force: true });
  mkdirSync(staging, { recursive: true });
  const addFiles = ADDED.map((file) => {
    const path = resolve(staging, file);
    writeFileSync(path, git(['show', `HEAD:${file}`], { encoding: 'buffer' }));
    return `--add-file=${path}`;
  });

  rmSync(zipPath, { force: true });
  git([
    'archive',
    '--format=zip',
    `--prefix=${SLUG}/`,
    ...addFiles,
    `--output=${zipPath}`,
    `HEAD:${PLUGIN}`,
    '--',
    '.',
    ...LEFT_OUT.map((path) => `:(exclude)${path}`),
  ]);
  rmSync(staging, { recursive: true, force: true });

  const count = check(zipPath);
  const kb = Math.round(statSync(zipPath).size / 1024);
  const sha256 = createHash('sha256').update(readFileSync(zipPath)).digest('hex');
  console.log(`${relative(ROOT, zipPath)}: The FireHouse ${version}, commit ${commit}, ${count} files, ${kb} KB`);
  console.log(`SHA-256 ${sha256}`);

  const uncommitted = git(['status', '--porcelain', '--', PLUGIN, ...ADDED]).trimEnd();
  if (uncommitted) {
    console.warn(
      `plugin-zip: built from commit ${commit}; these changes aren't committed, so they aren't in it:\n` +
        uncommitted.replace(/^/gm, '  '),
    );
  }

  if (process.env.GITHUB_OUTPUT) {
    appendFileSync(
      process.env.GITHUB_OUTPUT,
      `version=${version}\nzip=${relative(ROOT, zipPath).replaceAll('\\', '/')}\nname=${name}\nsha256=${sha256}\n`,
    );
  }
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) main();
