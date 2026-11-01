import vocabulary from '../../wp-content/plugins/firehouse/shared/vocabulary.json' with { type: 'json' };
import type { IconName } from './Icon';

/**
 * The four topic areas from the FireHouse 1.0 thematic analysis (creative brief §2).
 *
 * These keys are the contract between the CMS, the submission-triage pipeline, and
 * the UI. They are deliberately *not* editable content: adding or renaming a topic
 * is a structural change that needs a code + schema change, not a CMS edit. Human-
 * readable labels are editable in the CMS and override `label`/`short` at render
 * time (see `content/types.ts`).
 *
 * The keys, labels and icons live in `shared/vocabulary.json` with the other
 * vocabularies, so the WordPress plugin reads the same ones. The colours are this
 * site's CSS custom properties, named after the key.
 */
export interface TopicDefinition {
  key: TopicKey;
  label: string;
  short: string;
  icon: IconName;
  /** CSS custom property references — resolve per theme. */
  text: string;
  fill: string;
  tint: string;
  border: string;
}

export type TopicKey = 'observe' | 'forecast' | 'warning' | 'governance';

export const TOPICS: Record<TopicKey, TopicDefinition> = Object.fromEntries(
  vocabulary.topics.map((topic) => {
    const key = topic.key as TopicKey;
    return [
      key,
      {
        key,
        label: topic.label,
        short: topic.short,
        icon: topic.icon as IconName,
        text: `var(--topic-${key}-text)`,
        fill: `var(--topic-${key}-fill)`,
        tint: `var(--topic-${key}-tint)`,
        border: `var(--topic-${key}-border)`,
      },
    ];
  }),
) as Record<TopicKey, TopicDefinition>;

export const TOPIC_LIST: TopicDefinition[] = Object.values(TOPICS);

export const TOPIC_KEYS = Object.keys(TOPICS) as TopicKey[];

export function isTopicKey(value: unknown): value is TopicKey {
  return typeof value === 'string' && value in TOPICS;
}
