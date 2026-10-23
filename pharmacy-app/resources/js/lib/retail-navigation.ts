import {
    ArrowLeftRight,
    BarChart3,
    Boxes,
    BookOpen,
    ClipboardCheck,
    ClipboardList,
    FileText,
    LayoutDashboard,
    MapPin,
    PackageCheck,
    Percent,
    Gift,
    ScanBarcode,
    ReceiptText,
    Settings2,
    ShoppingCart,
    Tags,
    Trash2,
    Truck,
    Undo2,
    Users,
    Wallet,
} from 'lucide-react';
import type { NavGroup } from '@/lib/navigation';
import { home } from '@/routes/retail';
import { edit as counterSettings } from '@/routes/retail/counter-settings';
import { index as counts } from '@/routes/retail/counts';
import { index as customers } from '@/routes/retail/customers';
import { index as disposals } from '@/routes/retail/disposals';
import { index as locations } from '@/routes/retail/locations';
import { edit as loyalty } from '@/routes/retail/loyalty';
import { index as orders } from '@/routes/retail/orders';
import { index as priceLists } from '@/routes/retail/price-lists';
import { index as products } from '@/routes/retail/products';
import { index as promotions } from '@/routes/retail/promotions';
import { index as purchaseOrders } from '@/routes/retail/purchase-orders';
import { index as receipts } from '@/routes/retail/receipts';
import { index as reports } from '@/routes/retail/reports';
import { create as counter, index as sales } from '@/routes/retail/sales';
import { index as serials } from '@/routes/retail/serials';
import { index as stock } from '@/routes/retail/stock';
import { index as supplierReturns } from '@/routes/retail/supplier-returns';
import { index as suppliers } from '@/routes/retail/suppliers';
import { index as tills } from '@/routes/retail/tills';
import { index as transfers } from '@/routes/retail/transfers';

/**
 * The shop's sections, in menu order.
 */
export const retailNavigation: NavGroup[] = [
    {
        title: 'Overview',
        items: [
            {
                title: 'Dashboard',
                href: home(),
                icon: LayoutDashboard,
                permission: null,
                scope: 'org',
            },
            {
                title: 'Reports',
                href: reports(),
                icon: BarChart3,
                permission: 'retail.reports.view',
                scope: 'branch',
            },
        ],
    },
    {
        title: 'Counter',
        items: [
            {
                title: 'Counter',
                href: counter(),
                icon: ShoppingCart,
                permission: 'retail.sales.create',
                scope: 'branch',
            },
            {
                title: 'Sales',
                href: sales(),
                icon: ReceiptText,
                permission: 'retail.sales.view',
                scope: 'branch',
            },
            {
                title: 'Shifts',
                href: tills(),
                icon: Wallet,
                permission: 'retail.sales.create',
                scope: 'branch',
            },
            {
                title: 'Customers',
                href: customers(),
                icon: Users,
                permission: 'retail.customers.view',
                scope: 'branch',
            },
            {
                title: 'Counter settings',
                href: counterSettings(),
                icon: Settings2,
                permission: 'retail.sales.settings',
                scope: 'branch',
            },
        ],
    },
    {
        title: 'Wholesale',
        items: [
            {
                title: 'Orders',
                href: orders(),
                icon: FileText,
                permission: 'retail.orders.view',
                scope: 'branch',
            },
            {
                title: 'Price lists',
                href: priceLists(),
                icon: BookOpen,
                permission: 'retail.prices.manage',
                scope: 'org',
            },
            {
                title: 'Promotions',
                href: promotions(),
                icon: Percent,
                permission: 'retail.prices.manage',
                scope: 'org',
            },
            {
                title: 'Loyalty',
                href: loyalty(),
                icon: Gift,
                permission: 'retail.prices.manage',
                scope: 'org',
            },
        ],
    },
    {
        title: 'Inventory',
        items: [
            {
                title: 'Stock',
                href: stock(),
                icon: Boxes,
                permission: 'retail.stock.view',
                scope: 'branch',
            },
            {
                title: 'Shelves',
                href: locations(),
                icon: MapPin,
                permission: 'retail.stock.view',
                scope: 'branch',
            },
            {
                title: 'Stock counts',
                href: counts(),
                icon: ClipboardCheck,
                permission: 'retail.stock.view',
                scope: 'branch',
            },
            {
                title: 'Serial numbers',
                href: serials(),
                icon: ScanBarcode,
                permission: 'retail.stock.view',
                scope: 'branch',
            },
            {
                title: 'Transfers',
                href: transfers(),
                icon: ArrowLeftRight,
                permission: 'retail.stock.view',
                scope: 'branch',
            },
            {
                title: 'Write-offs',
                href: disposals(),
                icon: Trash2,
                permission: 'retail.stock.adjust',
                scope: 'branch',
            },
            {
                title: 'Products',
                href: products(),
                icon: Tags,
                permission: 'retail.products.view',
                scope: 'org',
            },
        ],
    },
    {
        title: 'Purchasing',
        items: [
            {
                title: 'Purchase orders',
                href: purchaseOrders(),
                icon: ClipboardList,
                permission: 'retail.purchasing.view',
                scope: 'branch',
            },
            {
                title: 'Goods received',
                href: receipts(),
                icon: PackageCheck,
                permission: 'retail.purchasing.view',
                scope: 'branch',
            },
            {
                title: 'Returns',
                href: supplierReturns(),
                icon: Undo2,
                permission: 'retail.purchasing.view',
                scope: 'branch',
            },
            {
                title: 'Suppliers',
                href: suppliers(),
                icon: Truck,
                permission: 'retail.purchasing.view',
                scope: 'org',
            },
        ],
    },
];
