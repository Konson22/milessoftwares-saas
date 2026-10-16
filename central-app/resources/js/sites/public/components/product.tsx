import {
    Boxes,
    Calculator,
    GraduationCap,
    Handshake,
    LayoutGrid,
    Pill,
    ShoppingCart,
    Stethoscope,
    Users,
    UtensilsCrossed,
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
    inventory: Boxes,
    crm: Handshake,
    clinic: Stethoscope,
    restaurant: UtensilsCrossed,
};

export function productIcon(code: string): LucideIcon {
    return icons[code] ?? LayoutGrid;
}

/** The application's icon on a soft blue rounded square. */
export function ProductTile({
    code,
    size = 'md',
}: {
    code: string;
    size?: 'sm' | 'md' | 'lg';
}) {
    const Icon = productIcon(code);
    const box = {
        sm: 'size-9 rounded-lg',
        md: 'size-12 rounded-xl',
        lg: 'size-16 rounded-2xl',
    }[size];
    const glyph = { sm: 'size-4', md: 'size-6', lg: 'size-8' }[size];

    return (
        <span
            className={`inline-flex shrink-0 items-center justify-center bg-brand-blue/10 text-brand-blue ${box}`}
        >
            <Icon className={glyph} aria-hidden />
        </span>
    );
}
