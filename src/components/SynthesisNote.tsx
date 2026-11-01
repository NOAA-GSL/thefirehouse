import { useContent } from '../content/ContentProvider';
import { publishedProjects } from '../content/derive';
import { formatReviewDate } from '../content/format';
import './SynthesisNote.css';

export interface SynthesisNoteProps {
  model?: string;
  reviewedBy?: string;
  updatedAt?: string;
  sourceCount: number;
  /** Whether to show the review status after the synthesis line. Default true. */
  showStatus?: boolean;
  className?: string;
}

/**
 * The one-line AI disclosure for synthesized needs.
 *
 * This is the one place the site paraphrases researchers instead of quoting them,
 * and a federal site presenting model-derived synthesis has to say so plainly —
 * including when a pass has not been reviewed yet. Shared by the landing page and
 * the topic pages so the wording can't drift between them.
 *
 * It says "all" only while the pass covers every published project. Projects
 * published since the last pass make it "4 of 8", until the next pass catches up.
 */
export function SynthesisNote({
  model,
  reviewedBy,
  updatedAt,
  sourceCount,
  showStatus = true,
  className = '',
}: SynthesisNoteProps) {
  const published = publishedProjects(useContent()).length;
  const projects =
    sourceCount < published
      ? `${sourceCount} of ${published} projects`
      : `all ${sourceCount} ${sourceCount === 1 ? 'project' : 'projects'}`;
  const date = updatedAt && (
    <time dateTime={updatedAt}>{formatReviewDate(updatedAt)}</time>
  );

  const draft = showStatus && !reviewedBy;

  return (
    <p className={['fh-synthesis-note', draft && 'fh-synthesis-note--draft', className].filter(Boolean).join(' ')}>
      <span className="fh-synthesis-note__dot" aria-hidden="true" />
      <span>
        <strong>{model ? 'AI-synthesized' : 'Synthesized'}</strong> across {projects}.
        {showStatus &&
          (reviewedBy ? (
            <>
              {' '}
              Reviewed by {reviewedBy}
              {date && <> on {date}</>}.
            </>
          ) : (
            <>
              {' '}
              <strong>Draft, pending team review</strong>
              {date && <> · updated {date}</>}.
            </>
          ))}
      </span>
    </p>
  );
}
