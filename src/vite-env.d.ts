/// <reference types="vite/client" />

interface ImportMetaEnv {
  /** Which content adapter to use: 'local' (default) | 'sanity' | 'strapi'. */
  readonly VITE_CMS?: 'local' | 'sanity' | 'strapi';

  readonly VITE_SANITY_PROJECT_ID?: string;
  readonly VITE_SANITY_DATASET?: string;
  readonly VITE_SANITY_API_VERSION?: string;

  readonly VITE_STRAPI_URL?: string;
  readonly VITE_STRAPI_TOKEN?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}

declare module '*.svg' {
  const src: string;
  export default src;
}

declare module '*.png' {
  const src: string;
  export default src;
}
