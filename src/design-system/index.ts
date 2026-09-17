/**
 * The Firehouse design system, ported to React from the Claude Design project
 * `the-firehouse-design-system-a05f43c6-bfb5-47a4-b052-1f16fdeac42a`.
 *
 * Everything visual lives behind this barrel. Pages import from here, never from
 * raw token values, so a design system re-sync stays a one-directory change.
 */
export { Button } from './Button';
export type { ButtonProps, ButtonSize, ButtonVariant } from './Button';

export { BrandMark } from './BrandMark';
export type { BrandMarkProps } from './BrandMark';

export { Icon } from './Icon';
export type { IconName, IconProps } from './Icon';

export { GovBanner } from './GovBanner';
export { SiteHeader } from './SiteHeader';
export type { NavItem, SiteHeaderProps } from './SiteHeader';
export { SiteFooter } from './SiteFooter';
export type { FooterLinkGroup, SiteFooterProps } from './SiteFooter';

export { StatCounter } from './StatCounter';
export type { StatCounterProps } from './StatCounter';

export { TopicTag } from './TopicTag';
export type { TopicTagProps } from './TopicTag';

export { TopicTagRow } from './TopicTagRow';
export type { TopicTagRowProps } from './TopicTagRow';

export { ProjectTile } from './ProjectTile';
export type { ProjectTileProps } from './ProjectTile';

/* RegionMap is deliberately NOT re-exported here. It pulls in Leaflet, and this
   barrel is imported by every page — a single named export would drag the whole
   mapping library back into the shared chunk and undo the code split. Import it
   directly from './design-system/RegionMap' (lazily, as LandingPage does). */

export { CitationBlock } from './CitationBlock';
export type { CitationBlockProps, CitationFormatOption } from './CitationBlock';

export { ProjectDetailModal } from './ProjectDetailModal';
export type { ProjectDetailModalProps, ProjectPaper } from './ProjectDetailModal';

export { TextArea, TextField } from './Field';
export type { TextAreaProps, TextFieldProps } from './Field';

export { TOPICS, TOPIC_KEYS, TOPIC_LIST, isTopicKey } from './topics';
export type { TopicDefinition, TopicKey } from './topics';

export {
  FIRE_PHASES,
  FIRE_PHASE_KEYS,
  FIRE_PHASE_LIST,
  GACCS,
  GACC_KEYS,
  GACC_LIST,
  NON_GACC_LIST,
  PUBLICATION_STATUSES,
  PUBLICATION_STATUS_LIST,
  REGIONS,
  REGION_KEYS,
  REGION_LIST,
  isFirePhase,
  isGaccKey,
  isPublicationStatus,
  isRegionKey,
} from './taxonomy';
export type {
  FirePhase,
  FirePhaseDefinition,
  GaccKey,
  NonGaccRegion,
  PublicationStatus,
  PublicationStatusDefinition,
  RegionDefinition,
  RegionKey,
} from './taxonomy';
