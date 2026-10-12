import type { InertiaLinkProps } from '@inertiajs/react';
import { index as medicines } from '@/routes/medicines';
import { index as purchaseOrders } from '@/routes/purchase-orders';
import { index as stock } from '@/routes/stock';
import { index as suppliers } from '@/routes/suppliers';

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    /** Shown to members holding this catalog permission (or owners). */
    permission: string;
};

/**
 * The pharmacy's sections, in menu order. Each feature adds its own entry.
 */
export const navigation: NavItem[] = [
    { title: 'Stock', href: stock(), permission: 'pharmacy.stock.view' },
    {
        title: 'Purchasing',
        href: purchaseOrders(),
        permission: 'pharmacy.purchasing.view',
    },
    {
        title: 'Medicines',
        href: medicines(),
        permission: 'pharmacy.medicines.view',
    },
    {
        title: 'Suppliers',
        href: suppliers(),
        permission: 'pharmacy.purchasing.view',
    },
];
