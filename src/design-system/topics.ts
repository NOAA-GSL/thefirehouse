import type { IconName } from './Icon';

/**
 * The four topic areas from the FireHouse 1.0 thematic analysis (creative brief §2).
 *
 * These keys are the contract between the CMS, the submission-triage pipeline, and
 * the UI. They are deliberately *not* editable content: adding or renaming a topic
 * is a structural change that needs a code + schema change, not a CMS edit. Human-
 * readable labels are editable in the CMS and override `label`/`short` at render
 * time (see `content/types.ts`).
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

export const TOPICS: Record<TopicKey, TopicDefinition> = {
  observe: {
    key: 'observe',
    label: 'Observations and Monitoring',
    short: 'Observations',
    icon: 'radar',
    text: 'var(--topic-observe-text)',
    fill: 'var(--topic-observe-fill)',
    tint: 'var(--topic-observe-tint)',
    border: 'var(--topic-observe-border)',
  },
  forecast: {
    key: 'forecast',
    label: 'Forecasts and Modeling',
    short: 'Forecasts',
    icon: 'waves',
    text: 'var(--topic-forecast-text)',
    fill: 'var(--topic-forecast-fill)',
    tint: 'var(--topic-forecast-tint)',
    border: 'var(--topic-forecast-border)',
  },
  warning: {
    key: 'warning',
    label: 'Warnings and Immediate Response',
    short: 'Warnings',
    icon: 'flame',
    text: 'var(--topic-warning-text)',
    fill: 'var(--topic-warning-fill)',
    tint: 'var(--topic-warning-tint)',
    border: 'var(--topic-warning-border)',
  },
  governance: {
    key: 'governance',
    label: 'Strategic Adaptation and Institutional Governance',
    short: 'Governance',
    icon: 'landmark',
    text: 'var(--topic-governance-text)',
    fill: 'var(--topic-governance-fill)',
    tint: 'var(--topic-governance-tint)',
    border: 'var(--topic-governance-border)',
  },
};

export const TOPIC_LIST: TopicDefinition[] = Object.values(TOPICS);

export const TOPIC_KEYS = Object.keys(TOPICS) as TopicKey[];

export function isTopicKey(value: unknown): value is TopicKey {
  return typeof value === 'string' && value in TOPICS;
}
