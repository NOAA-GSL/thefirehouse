import type { CSSProperties } from 'react';
import { Icon } from './Icon';
import { TopicTag } from './TopicTag';
import type { TopicKey } from './topics';
import './ProjectTile.css';

export interface ProjectTileProps {
  topic: TopicKey;
  title: string;
  summary: string;
  author: string;
  year: number | string;
  onClick?: () => void;
  style?: CSSProperties;
}

/** Tile for the project explorer grid: topic, title, one-line summary, author/year. */
export function ProjectTile({
  topic,
  title,
  summary,
  author,
  year,
  onClick,
  style,
}: ProjectTileProps) {
  return (
    <button type="button" className="fh-tile" onClick={onClick} style={style}>
      <TopicTag topic={topic} size="sm" />
      <h3 className="fh-tile__title">{title}</h3>
      <p className="fh-tile__summary">{summary}</p>
      <span className="fh-tile__meta">
        <span className="fh-tile__byline">
          {author} · {year}
        </span>
        <Icon name="arrow-up-right" size={16} />
      </span>
    </button>
  );
}
