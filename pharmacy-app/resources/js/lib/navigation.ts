import type { InertiaLinkProps } from '@inertiajs/react';
import { index as controlled } from '@/routes/controlled';
import { index as medicines } from '@/routes/medicines';
import { index as prescriptions } from '@/routes/prescriptions';
import { index as purchaseOrders } from '@/routes/purchase-orders';
import { index as reports } from '@/routes/reports';
import { create as counter, index as sales } from '@/routes/sales';
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
    { title: 'Counter', href: counter(), permission: 'pharmacy.sales.create' },
    { title: 'Sales', href: sales(), permission: 'pharmacy.sales.view' },
    {
        title: 'Prescriptions',
        href: prescriptions(),
        permission: 'pharmacy.prescriptions.view',
    },
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
    {
        title: 'Controlled register',
        href: controlled(),
        permission: 'pharmacy.controlled.view',
    },
    { title: 'Reports', href: reports(), permission: 'pharmacy.reports.view' },
];
