import {
  Megaphone,
  Gauge,
  Table,
  Target,
  FileText,
  Radio,
  Network,
  Globe,
  Cloud,
  TrendingUp,
  ShieldCheck,
  Bot,
  Bell,
  Users,
  BadgeCheck,
  Database,
  type LucideIcon,
} from 'lucide-react';

const map: Record<string, LucideIcon> = {
  megaphone: Megaphone,
  gauge: Gauge,
  table: Table,
  target: Target,
  file: FileText,
  broadcast: Radio,
  sitemap: Network,
  globe: Globe,
  cloud: Cloud,
  trend: TrendingUp,
  shield: ShieldCheck,
  robot: Bot,
  bell: Bell,
  users: Users,
  badge: BadgeCheck,
  database: Database,
};

export function NavIcon({ name, size = 18 }: { name: string; size?: number }) {
  const Icon = map[name] ?? Table;
  return <Icon size={size} />;
}
