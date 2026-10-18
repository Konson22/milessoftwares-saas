import {
    Boxes,
    Calculator,
    GraduationCap,
    Handshake,
    Landmark,
    LayoutGrid,
    Pill,
    ShoppingCart,
    Stethoscope,
    Users,
    UtensilsCrossed,
    Wallet,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

/**
 * One icon per application code, wherever an application is shown (public
 * site, workspace sidebar, launcher). The products use the same ones in
 * their own sidebars. Anything new gets a generic icon.
 */
const icons: Record<string, LucideIcon> = {
    pharmacy: Pill,
    finance: Landmark,
    advanced_accounting: Calculator,
    retail: ShoppingCart,
    school: GraduationCap,
    hr: Users,
    payroll: Wallet,
    inventory: Boxes,
    crm: Handshake,
    clinic: Stethoscope,
    restaurant: UtensilsCrossed,
};

export function appIcon(code: string): LucideIcon {
    return icons[code] ?? LayoutGrid;
}
