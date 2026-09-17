import { formatReviewDate } from '../content/format';
import './SynthesisNote.css';

export interface SynthesisNoteProps {
  model?: string;
  reviewedBy?: string;
  updatedAt?: string;
  sourceCount: number;
  className?: string;
}

/**
 * The one-line AI disclosure for synthesized needs.
 *
 * This is the one place the site paraphrases researchers instead of quoting them,
 * and a federal site presenting model-derived synthesis has to say so plainly —
 * including when a pass has not been reviewed yet. Shared by the landing page and
 * the topic pages so the wording can't drift between them.
 */
export function SynthesisNote({
  model,
  reviewedBy,
  updatedAt,
  sourceCount,
  className = '',
}: SynthesisNoteProps) {
  const projects = `${sourceCount} ${sourceCount === 1 ? 'project' : 'projects'}`;
  const date = updatedAt && (
    <time dateTime={updatedAt}>{formatReviewDate(updatedAt)}</time>
  );

  return (
    <p className={`fh-synthesis-note ${reviewedBy ? '' : 'fh-synthesis-note--draft'} ${className}`.trim()}>
      <span className="fh-synthesis-note__dot" aria-hidden="true" />
      <span>
        <strong>{model ? 'AI-synthesized' : 'Synthesized'}</strong> across all {projects}.{' '}
        {reviewedBy ? (
          <>
            Reviewed by {reviewedBy}
            {date && <> on {date}</>}.
          </>
        ) : (
          <>
            <strong>Draft, pending team review</strong>
            {date && <> · updated {date}</>}.
          </>
        )}
      </span>
    </p>
  );
}
