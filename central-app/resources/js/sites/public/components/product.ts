import {
    Calculator,
    GraduationCap,
    LayoutGrid,
    Pill,
    ShoppingCart,
    Users,
    Wallet,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

export type Product = {
    code: string;
    name: string;
    tagline: string | null;
    description: string | null;
    status: 'available' | 'beta' | 'planned';
    features: string[];
};

export const statusLabel: Record<Product['status'], string | null> = {
    available: null,
    beta: 'Beta',
    planned: 'Coming soon',
};

/** An icon per known application code; anything new gets a generic one. */
const icons: Record<string, LucideIcon> = {
    pharmacy: Pill,
    finance: Calculator,
    retail: ShoppingCart,
    school: GraduationCap,
    hr: Users,
    payroll: Wallet,
};

export function productIcon(code: string): LucideIcon {
    return icons[code] ?? LayoutGrid;
}
