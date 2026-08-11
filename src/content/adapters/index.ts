import type { ContentAdapter } from '../types';
import { localAdapter } from './local';
import { createSanityAdapter } from './sanity';
import { createStrapiAdapter } from './strapi';

/**
 * Picks the content source from `VITE_CMS` at build time.
 *
 * The whole point of this indirection: when Stephanie and Emily's CMS is chosen and
 * stood up (creative brief §5.4), moving the site onto it is one env var plus a
 * schema that matches `../types.ts`. No page or component changes.
 */
export function getContentAdapter(): ContentAdapter {
  // An unset *or empty* env var means local — an empty string is what you get from
  // a `VITE_CMS=` line someone commented out halfway.
  const cms = import.meta.env.VITE_CMS || 'local';

  switch (cms) {
    case 'sanity':
      return createSanityAdapter();
    case 'strapi':
      return createStrapiAdapter();
    case 'local':
      return localAdapter;
    default:
      throw new Error(`[content] Unknown VITE_CMS value "${cms}". Use local, sanity, or strapi.`);
  }
}

export { localAdapter };
