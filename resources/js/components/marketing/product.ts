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
