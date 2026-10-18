import { Head, Link } from '@inertiajs/react';
import { Badge } from '@/shared/components/ui/badge';

type Product = {
    id: string;
    code: string;
    name: string;
    tagline: string | null;
    status: 'planned' | 'pilot' | 'available' | 'retired';
    applications: { name: string; status: string; requirement: string }[];
    plans: { code: string; name: string; status: string }[];
};

export default function ProductsIndex({ products }: { products: Product[] }) {
    return (
        <>
            <Head title="Products" />

            <h1 className="text-2xl font-semibold">ERP products</h1>
            <p className="mt-1 text-sm text-muted-foreground">
                What is sold, made of registry applications. An organization
                holds a product while it is entitled to every required
                application. Products change through{' '}
                <code>database/erp-products.json</code> and{' '}
                <code>php artisan platform:erp-products</code>; sell one with a{' '}
                <Link href="/plans" className="underline">
                    plan
                </Link>{' '}
                linked to it.
            </p>

            <ul className="mt-5 space-y-4">
                {products.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        No products yet. Run{' '}
                        <code>php artisan platform:erp-products</code>.
                    </p>
                )}
                {products.map((product) => (
                    <li key={product.id} className="rounded-lg border p-4">
                        <div className="flex flex-wrap items-center gap-2">
                            <p className="font-medium">{product.name}</p>
                            <code className="text-xs text-muted-foreground">
                                {product.code}
                            </code>
                            <Badge
                                variant={
                                    product.status === 'available'
                                        ? 'secondary'
                                        : 'outline'
                                }
                            >
                                {product.status}
                            </Badge>
                        </div>
                        {product.tagline && (
                            <p className="mt-1 text-sm text-muted-foreground">
                                {product.tagline}
                            </p>
                        )}
                        <div className="mt-3 grid gap-4 text-sm sm:grid-cols-2">
                            <div>
                                <p className="font-medium">Applications</p>
                                <ul className="mt-1 space-y-0.5 text-muted-foreground">
                                    {product.applications.map((application) => (
                                        <li key={application.name}>
                                            {application.name} ·{' '}
                                            {application.requirement} ·{' '}
                                            {application.status}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                            <div>
                                <p className="font-medium">Plans</p>
                                {product.plans.length === 0 ? (
                                    <p className="mt-1 text-muted-foreground">
                                        None yet.
                                    </p>
                                ) : (
                                    <ul className="mt-1 space-y-0.5 text-muted-foreground">
                                        {product.plans.map((plan) => (
                                            <li key={plan.code}>
                                                {plan.name} · {plan.status}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        </div>
                    </li>
                ))}
            </ul>
        </>
    );
}
