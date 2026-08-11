import { useId, useState } from 'react';
import { Icon } from './Icon';
import './GovBanner.css';

function FlagIcon({ size = 16 }: { size?: number }) {
  return (
    <svg width={size} height={size * 0.75} viewBox="0 0 20 15" aria-hidden="true" focusable="false">
      <rect width="20" height="15" fill="#B31942" />
      {[0, 2, 4, 6, 8, 10, 12].map((y) => (
        <rect key={y} y={y + 1.15} width="20" height="1.15" fill="#fff" />
      ))}
      <rect width="8" height="8.1" fill="#0A3161" />
    </svg>
  );
}

/**
 * Required U.S. federal ".gov" identification banner with a "Here's how you know"
 * expandable panel.
 *
 * Per the design system readme, the banner deliberately has no dark variant — it
 * stays on the neutral sunken surface in both themes, per federal convention.
 */
export function GovBanner() {
  const [open, setOpen] = useState(false);
  const panelId = useId();

  return (
    <div className="fh-gov">
      <div className="fh-gov__bar fh-container">
        <FlagIcon />
        <span>An official website of the United States government</span>
        <button
          type="button"
          className="fh-gov__toggle"
          aria-expanded={open}
          aria-controls={panelId}
          onClick={() => setOpen((v) => !v)}
        >
          Here&rsquo;s how you know <Icon name={open ? 'chevron-down' : 'chevron-right'} size={12} />
        </button>
      </div>

      {open && (
        <div id={panelId} className="fh-gov__panel">
          <div className="fh-gov__panel-inner fh-container">
            <div className="fh-gov__fact">
              <Icon name="landmark" size={22} color="var(--color-brand-primary)" />
              <div>
                <strong>Official websites use .gov</strong>
                <span>
                  A .gov website belongs to an official government organization in the United States.
                </span>
              </div>
            </div>
            <div className="fh-gov__fact">
              <Icon name="lock" size={22} color="var(--color-brand-primary)" />
              <div>
                <strong>Secure .gov websites use HTTPS</strong>
                <span>
                  A lock icon or https:// means you&rsquo;ve safely connected to the .gov website.
                </span>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
