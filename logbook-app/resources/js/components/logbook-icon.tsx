import type { LucideIcon } from 'lucide-react';
import {
    BookOpen,
    Building2,
    ClipboardList,
    MapPin,
    Server,
    Shield,
    Truck,
    Users,
    Wrench,
    Zap,
} from 'lucide-react';

/** The icons a logbook may carry (App\Logbook\Models\Logbook::ICONS). */
export const LOGBOOK_ICONS: Record<string, LucideIcon> = {
    book: BookOpen,
    server: Server,
    building: Building2,
    wrench: Wrench,
    truck: Truck,
    zap: Zap,
    shield: Shield,
    clipboard: ClipboardList,
    'map-pin': MapPin,
    users: Users,
};

export function LogbookIcon({
    icon,
    className = 'size-5',
}: {
    icon: string | null;
    className?: string;
}) {
    const Icon = LOGBOOK_ICONS[icon ?? 'book'] ?? BookOpen;

    return <Icon className={className} aria-hidden />;
}
