/**
 * Finds text shaped like an email address, by the pattern in
 * `wp-content/plugins/firehouse/shared/email-shape.json`, which the plugin's Import
 * screen reads too. The survey import and the seed bundle use it as the backstop for
 * WORDPRESS-PLAN.md rule 2: a submitter's email never reaches the site's files.
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const shape = JSON.parse(
  readFileSync(fileURLToPath(new URL('../wp-content/plugins/firehouse/shared/email-shape.json', import.meta.url)), 'utf8'),
);

/** The pattern's source, for a script that needs its own flags. */
export const EMAIL_PATTERN = shape.pattern;

/** Whether text holds something shaped like an email address. */
export const hasEmail = (text) => new RegExp(shape.pattern, 'i').test(text);

/** Whether the whole of a text is a team member's work address. */
export const isTeamEmail = (text) => new RegExp(shape.teamEmail, 'i').test(text);

/**
 * The paths of every string in the site's content that holds something shaped like an
 * email address. The one exception is an About page team member's own `email` field,
 * when the whole of it is a team address: the site shows it on purpose.
 *
 * @param {Record<string, unknown>} blocks The content files, by name: settings, about…
 * @returns {string[]} Each path, such as "projects.3.abstract". Never the value.
 */
export function findEmails(blocks) {
  const found = [];
  const scan = (value, path) => {
    if (typeof value === 'string') {
      const teamField = path.length === 5 && path[0] === 'about' && path[1] === 'team' && path[2] === 'members' && /^\d+$/.test(path[3]) && path[4] === 'email';
      if (hasEmail(value) && !(teamField && isTeamEmail(value))) found.push(path.join('.'));
    } else if (value && typeof value === 'object') {
      for (const [key, inner] of Object.entries(value)) scan(inner, [...path, key]);
    }
  };
  for (const [name, block] of Object.entries(blocks)) scan(block, [name]);
  return found;
}
