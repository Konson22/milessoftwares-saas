/**
 * The eight application areas that circle the dashboard in the home hero.
 *
 * The illustration is a 700 × 650 stage (`heroStage`). The icons travel on
 * an ellipse centred on the dashboard; `angle` is where each one starts, in
 * degrees clockwise from the right (−90 is the top). `href` is set only
 * where the site has a page for it.
 */
export type HeroApplication = {
    id: string;
    name: string;
    icon: string;
    angle: number;
    href?: string;
};

export const heroStage = {
    width: 700,
    height: 650,
    /** Centre of the dashboard and of the orbit. */
    center: { x: 350, y: 306 },
    /** The orbit's horizontal and vertical radii. */
    rx: 300,
    ry: 260,
    dashboard: { width: 300, height: 197 },
    /** Icon size in stage units. */
    icon: 92,
    /** Seconds for one full turn. */
    turn: 60,
} as const;

export const heroApplications: HeroApplication[] = [
    {
        id: 'finance',
        name: 'Finance',
        icon: '/images/hero/apps/finance.webp',
        angle: -90,
        href: '/apps/finance',
    },
    {
        id: 'hr',
        name: 'Human Resources',
        icon: '/images/hero/apps/human-resources.webp',
        angle: -45,
        href: '/apps/hr',
    },
    {
        id: 'sales',
        name: 'Sales & POS',
        icon: '/images/hero/apps/sales-pos.webp',
        angle: 0,
        href: '/erp/retail',
    },
    {
        id: 'operations',
        name: 'Operations',
        icon: '/images/hero/apps/operations.webp',
        angle: 45,
    },
    {
        id: 'scheduling',
        name: 'Scheduling',
        icon: '/images/hero/apps/scheduling.webp',
        angle: 90,
    },
    {
        id: 'projects',
        name: 'Projects',
        icon: '/images/hero/apps/projects.webp',
        angle: 135,
    },
    {
        id: 'inventory',
        name: 'Inventory',
        icon: '/images/hero/apps/inventory.webp',
        angle: 180,
    },
    {
        id: 'analytics',
        name: 'Analytics',
        icon: '/images/hero/apps/analytics.webp',
        angle: 225,
    },
];

/**
 * Lines that carry a moving dot, with the direction of travel: `in` runs
 * from the application to the dashboard. Few on purpose.
 */
export const heroDataFlows: {
    id: string;
    direction: 'in' | 'out';
    begin: number;
}[] = [
    { id: 'sales', direction: 'in', begin: 1.6 },
    { id: 'finance', direction: 'out', begin: 2.6 },
    { id: 'inventory', direction: 'in', begin: 3.4 },
    { id: 'projects', direction: 'in', begin: 4.4 },
];
