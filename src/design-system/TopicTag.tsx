import type { CSSProperties } from 'react';
import { Icon } from './Icon';
import { TOPICS, type TopicKey } from './topics';
import './TopicTag.css';

export interface TopicTagProps {
  topic: TopicKey;
  size?: 'sm' | 'md';
  showIcon?: boolean;
  /** Override the label — lets CMS-edited topic names win over the built-in short name. */
  label?: string;
  style?: CSSProperties;
}

/** Pill badge identifying one of the four topic areas; used on cards, tags, section headers. */
export function TopicTag({ topic, size = 'md', showIcon = true, label, style }: TopicTagProps) {
  const t = TOPICS[topic] ?? TOPICS.observe;
  return (
    <span
      className={`fh-topic-tag fh-topic-tag--${size}`}
      style={
        {
          '--tag-text': t.text,
          '--tag-tint': t.tint,
          '--tag-border': t.border,
          ...style,
        } as CSSProperties
      }
    >
      {showIcon && (
        <Icon name={t.icon} size={size === 'sm' ? 12 : 14} color={t.text} strokeWidth={2.25} />
      )}
      {label ?? t.short}
    </span>
  );
}
