import { createContext, useContext, useEffect, useState, type ReactNode } from 'react';
import { getContentAdapter } from './adapters';
import type { SiteContent } from './types';

type ContentState =
  | { status: 'loading' }
  | { status: 'ready'; content: SiteContent }
  | { status: 'error'; error: Error };

const ContentContext = createContext<ContentState | null>(null);

export function ContentProvider({ children }: { children: ReactNode }) {
  const [state, setState] = useState<ContentState>({ status: 'loading' });

  useEffect(() => {
    let cancelled = false;
    getContentAdapter()
      .load()
      .then((content) => {
        if (!cancelled) setState({ status: 'ready', content });
      })
      .catch((error: unknown) => {
        if (!cancelled) {
          setState({ status: 'error', error: error instanceof Error ? error : new Error(String(error)) });
        }
      });
    return () => {
      cancelled = true;
    };
  }, []);

  return <ContentContext.Provider value={state}>{children}</ContentContext.Provider>;
}

/** Raw state, including loading and error — used by the app shell. */
export function useContentState(): ContentState {
  const state = useContext(ContentContext);
  if (!state) throw new Error('useContentState must be used inside <ContentProvider>.');
  return state;
}

/**
 * Content, guaranteed loaded.
 *
 * Pages render only after the shell has resolved content, so they can treat it as
 * present rather than threading `content?.` optionals through every component.
 */
export function useContent(): SiteContent {
  const state = useContentState();
  if (state.status !== 'ready') {
    throw new Error('useContent called before content resolved — render inside <ContentGate>.');
  }
  return state.content;
}
