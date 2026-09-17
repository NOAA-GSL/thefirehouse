import {
  ArrowLeft,
  ArrowRight,
  ArrowUpRight,
  Building2,
  Calendar,
  Check,
  ClipboardList,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  Copy,
  ExternalLink,
  Eye,
  FileText,
  Flame,
  FlaskConical,
  Landmark,
  Layers,
  Lock,
  MapPin,
  Menu,
  Moon,
  Plus,
  Quote,
  Radar,
  Search,
  Sun,
  Target,
  Users,
  Waves,
  X,
  type LucideIcon,
} from 'lucide-react';
import type { CSSProperties } from 'react';

/**
 * The design system's `Icon` is a thin wrapper over Lucide, addressed by Lucide's
 * kebab-case names. The original loaded Lucide from a CDN and mutated the DOM in an
 * effect; here it's the `lucide-react` package instead, so icons are real React
 * elements (no CDN call, no layout shift, tree-shaken, and no third-party script —
 * which also matters for federal CDN restrictions).
 *
 * The public API — `name`, `size`, `color`, `strokeWidth` — is unchanged, so design
 * system component code ports over as-is.
 *
 * Registry is explicit rather than dynamic so an unknown name is a type error at
 * build time instead of a blank space at runtime.
 */
const REGISTRY = {
  'arrow-left': ArrowLeft,
  'arrow-right': ArrowRight,
  'arrow-up-right': ArrowUpRight,
  building: Building2,
  calendar: Calendar,
  check: Check,
  clipboard: ClipboardList,
  'chevron-down': ChevronDown,
  'chevron-left': ChevronLeft,
  'chevron-right': ChevronRight,
  copy: Copy,
  eye: Eye,
  layers: Layers,
  'map-pin': MapPin,
  quote: Quote,
  users: Users,
  'external-link': ExternalLink,
  'file-text': FileText,
  flame: Flame,
  flask: FlaskConical,
  landmark: Landmark,
  lock: Lock,
  menu: Menu,
  moon: Moon,
  plus: Plus,
  radar: Radar,
  search: Search,
  sun: Sun,
  target: Target,
  waves: Waves,
  x: X,
} satisfies Record<string, LucideIcon>;

export type IconName = keyof typeof REGISTRY;

export const ICON_NAMES = Object.keys(REGISTRY) as IconName[];

/**
 * Whether a string addresses a real icon.
 *
 * The registry is explicit so a bad name in *code* is a type error, but content can
 * carry one too (an About page audience card names its icon). This is how the
 * content layer turns that into a load-time error rather than a render crash.
 */
export function isIconName(value: unknown): value is IconName {
  return typeof value === 'string' && value in REGISTRY;
}

export interface IconProps {
  name: IconName;
  size?: number;
  color?: string;
  strokeWidth?: number;
  className?: string;
  style?: CSSProperties;
}

export function Icon({
  name,
  size = 20,
  color = 'currentColor',
  strokeWidth = 2,
  className,
  style,
}: IconProps) {
  const Glyph = REGISTRY[name];
  return (
    <Glyph
      aria-hidden="true"
      focusable="false"
      width={size}
      height={size}
      stroke={color}
      strokeWidth={strokeWidth}
      className={className}
      style={{ display: 'inline-flex', flexShrink: 0, ...style }}
    />
  );
}
