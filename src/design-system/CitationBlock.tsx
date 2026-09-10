import { useEffect, useId, useRef, useState } from 'react';
import { Icon } from './Icon';
import './CitationBlock.css';

export interface CitationFormatOption {
  key: string;
  label: string;
}

export interface CitationBlockProps {
  formats: CitationFormatOption[];
  /** Returns the formatted citation for a format key. */
  render: (format: string) => string;
  defaultFormat?: string;
}

/**
 * "Cite this project" — a format switcher over a copyable citation string.
 *
 * The format switcher is a real tablist rather than four buttons with an "active"
 * class, so arrow keys move between formats the way a keyboard user expects and
 * only the selected tab is a tab stop.
 *
 * Copy feedback is announced, not just shown. The icon flip to a checkmark is
 * invisible to a screen reader, so the confirmation is also written into a live
 * region — otherwise pressing the button appears to do nothing at all.
 */
export function CitationBlock({ formats, render, defaultFormat }: CitationBlockProps) {
  const [format, setFormat] = useState(defaultFormat ?? formats[0].key);
  const [copied, setCopied] = useState(false);
  const tabsRef = useRef<(HTMLButtonElement | null)[]>([]);
  const panelId = useId();
  const text = render(format);

  // Reset the confirmation when the citation changes underneath it — otherwise the
  // button still reads "Copied" for a string that is no longer on the clipboard.
  useEffect(() => setCopied(false), [format]);

  useEffect(() => {
    if (!copied) return;
    const timer = window.setTimeout(() => setCopied(false), 2400);
    return () => window.clearTimeout(timer);
  }, [copied]);

  async function copy() {
    try {
      await navigator.clipboard.writeText(text);
      setCopied(true);
    } catch {
      // Clipboard access can be refused (insecure origin, permissions policy).
      // Selecting the text is the honest fallback: the citation is visible on the
      // page, so the reader can still copy it with the keyboard.
      const node = document.getElementById(panelId);
      if (node) {
        const range = document.createRange();
        range.selectNodeContents(node);
        const selection = window.getSelection();
        selection?.removeAllRanges();
        selection?.addRange(range);
      }
    }
  }

  function onTabKeyDown(event: React.KeyboardEvent, index: number) {
    const keys = ['ArrowRight', 'ArrowLeft', 'Home', 'End'];
    if (!keys.includes(event.key)) return;
    event.preventDefault();

    const last = formats.length - 1;
    const next =
      event.key === 'Home'
        ? 0
        : event.key === 'End'
          ? last
          : event.key === 'ArrowRight'
            ? (index + 1) % formats.length
            : (index - 1 + formats.length) % formats.length;

    setFormat(formats[next].key);
    tabsRef.current[next]?.focus();
  }

  return (
    <section className="fh-cite fh-glass" aria-labelledby={`${panelId}-heading`}>
      <div className="fh-cite__head">
        <h2 id={`${panelId}-heading`} className="fh-cite__heading">
          <Icon name="quote" size={16} />
          Cite this project
        </h2>

        <div className="fh-cite__tabs" role="tablist" aria-label="Citation format">
          {formats.map((option, i) => (
            <button
              key={option.key}
              ref={(node) => {
                tabsRef.current[i] = node;
              }}
              type="button"
              role="tab"
              id={`${panelId}-tab-${option.key}`}
              aria-selected={format === option.key}
              aria-controls={panelId}
              // Roving tabindex: the tablist is one tab stop, and arrow keys move
              // within it. Without this every format is its own stop.
              tabIndex={format === option.key ? 0 : -1}
              className={`fh-cite__tab${format === option.key ? ' fh-cite__tab--active' : ''}`}
              onClick={() => setFormat(option.key)}
              onKeyDown={(event) => onTabKeyDown(event, i)}
            >
              {option.label}
            </button>
          ))}
        </div>
      </div>

      <div className="fh-cite__body">
        <p
          id={panelId}
          role="tabpanel"
          aria-labelledby={`${panelId}-tab-${format}`}
          className="fh-cite__text"
          tabIndex={0}
        >
          {text}
        </p>

        <button type="button" className="fh-cite__copy" onClick={copy}>
          <Icon name={copied ? 'check' : 'copy'} size={15} />
          {copied ? 'Copied' : 'Copy'}
        </button>
      </div>

      {/* The icon swap above is purely visual; this is what actually announces it. */}
      <span className="fh-visually-hidden" role="status">
        {copied ? 'Citation copied to clipboard' : ''}
      </span>
    </section>
  );
}
