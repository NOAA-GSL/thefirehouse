import { TopicTag } from './TopicTag';
import type { TopicKey } from './topics';
import { TOPICS } from './topics';
import './TopicTagRow.css';

export interface TopicTagRowProps {
  topics: TopicKey[];
  size?: 'sm' | 'md';
  /**
   * Show at most this many tags, then a "+N" counter. Projects can carry all four
   * topic areas, and four full-width tags on a grid tile pushes the title below the
   * fold — the tile is a scanning surface, not the record.
   *
   * `0` means no limit; the X-ray page uses that.
   */
  max?: number;
}

/**
 * The topic tags for one project.
 *
 * Exists because topics went from one-per-project to many (the walkthrough's
 * "Observation + Warning" case), and every surface that used to render a single
 * `TopicTag` now needs the same overflow rule, the same ordering, and — the part
 * that is easy to get wrong — the same accessible name.
 *
 * The overflow counter is not `aria-hidden`: a sighted reader can see there are two
 * more areas, so a screen reader user must be told too. It names them rather than
 * announcing "+2", because "+2" is meaningless read aloud out of context.
 */
export function TopicTagRow({ topics, size = 'sm', max = 2 }: TopicTagRowProps) {
  if (topics.length === 0) return null;

  const limit = max > 0 ? max : topics.length;
  const shown = topics.slice(0, limit);
  const hidden = topics.slice(limit);

  // Icons are dropped as soon as there is more than one tag. With a single tag the
  // icon aids recognition; with two it costs ~18px each and pushes the pair onto a
  // second line, which shifts every title in the grid row out of alignment. The
  // colour and the label already tell the areas apart, so the icon is the part that
  // can go.
  const showIcon = shown.length === 1;

  return (
    <span className="fh-tag-row">
      {shown.map((topic) => (
        <TopicTag key={topic} topic={topic} size={size} showIcon={showIcon} />
      ))}
      {hidden.length > 0 && (
        <span className="fh-tag-row__more">
          <span aria-hidden="true">+{hidden.length}</span>
          <span className="fh-visually-hidden">
            {`and ${hidden.map((key) => TOPICS[key].label).join(', ')}`}
          </span>
        </span>
      )}
    </span>
  );
}
