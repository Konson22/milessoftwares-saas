import { Head, Link } from '@inertiajs/react';
import {
    Calculator,
    GraduationCap,
    HeartHandshake,
    Package,
    Pill,
    Store,
    UtensilsCrossed,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/shared/lib/utils';

type Status = 'planned' | 'pilot' | 'available' | 'retired';

type Product = {
    id: string;
    code: string;
    name: string;
    tagline: string | null;
    status: Status;
    applications: { name: string; status: string; requirement: string }[];
    plans: { code: string; name: string; status: string }[];
};

const icons: Record<string, LucideIcon> = {
    pharmacy: Pill,
    retail_trading: Store,
    restaurant: UtensilsCrossed,
    education: GraduationCap,
    ngo: HeartHandshake,
    finance_accounting: Calculator,
};

const statusStyles: Record<
    Status,
    { label: string; pill: string; dot: string }
> = {
    available: {
        label: 'Available',
        pill: 'bg-primary text-primary-foreground',
        dot: 'bg-primary-foreground',
    },
    pilot: {
        label: 'Pilot',
        pill: 'bg-primary/10 text-primary ring-1 ring-primary/25',
        dot: 'bg-primary',
    },
    planned: {
        label: 'Planned',
        pill: 'bg-muted text-muted-foreground ring-1 ring-border',
        dot: 'bg-muted-foreground/60',
    },
    retired: {
        label: 'Retired',
        pill: 'bg-muted text-muted-foreground line-through',
        dot: 'bg-muted-foreground/40',
    },
};

const order: Status[] = ['available', 'pilot', 'planned', 'retired'];

export default function ProductsIndex({ products }: { products: Product[] }) {
    const counts = order
        .map((status) => ({
            status,
            count: products.filter((product) => product.status === status)
                .length,
        }))
        .filter(({ count }) => count > 0);

    return (
        <>
            <Head title="Products" />

            <div className="flex flex-wrap items-end justify-between gap-4">
                <div className="max-w-2xl">
                    <h1 className="text-2xl font-semibold">ERP products</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        What is sold, made of registry applications. An
                        organization holds a product while it is entitled to
                        every required application. Products change through{' '}
                        <code>database/erp-products.json</code> and{' '}
                        <code>php artisan platform:erp-products</code>; sell one
                        with a{' '}
                        <Link href="/plans" className="text-primary underline">
                            plan
                        </Link>{' '}
                        linked to it.
                    </p>
                </div>
                {counts.length > 0 && (
                    <dl className="flex gap-2">
                        {counts.map(({ status, count }) => (
                            <div
                                key={status}
                                className="min-w-20 rounded-lg border bg-card px-3 py-2"
                            >
                                <dt className="text-xs text-muted-foreground">
                                    {statusStyles[status].label}
                                </dt>
                                <dd className="text-xl font-semibold tabular-nums">
                                    {count}
                                </dd>
                            </div>
                        ))}
                    </dl>
                )}
            </div>

            {products.length === 0 ? (
                <div className="mt-6 rounded-lg border border-dashed p-10 text-center text-sm text-muted-foreground">
                    No products yet. Run{' '}
                    <code>php artisan platform:erp-products</code>.
                </div>
            ) : (
                <ul className="mt-6 grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
                    {products.map((product) => (
                        <ProductCard key={product.id} product={product} />
                    ))}
                </ul>
            )}
        </>
    );
}

function ProductCard({ product }: { product: Product }) {
    const Icon = icons[product.code] ?? Package;
    const status = statusStyles[product.status];
    const required = product.applications.filter(
        (application) => application.requirement === 'required',
    );
    const optional = product.applications.filter(
        (application) => application.requirement !== 'required',
    );

    return (
        <li
            className={cn(
                'flex flex-col rounded-xl border bg-card shadow-xs transition-shadow hover:shadow-sm',
                product.status === 'retired' && 'opacity-60',
            )}
        >
            <div className="flex items-start gap-3 p-5">
                <div className="flex size-11 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <Icon className="size-5" aria-hidden />
                </div>
                <div className="min-w-0 flex-1">
                    <div className="flex items-start justify-between gap-2">
                        <h2 className="text-base leading-tight font-semibold">
                            {product.name}
                        </h2>
                        <span
                            className={cn(
                                'inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium',
                                status.pill,
                            )}
                        >
                            <span
                                className={cn(
                                    'size-1.5 rounded-full',
                                    status.dot,
                                )}
                                aria-hidden
                            />
                            {status.label}
                        </span>
                    </div>
                    <code className="mt-0.5 block text-xs text-muted-foreground">
                        {product.code}
                    </code>
                    {product.tagline && (
                        <p className="mt-2 text-sm text-muted-foreground">
                            {product.tagline}
                        </p>
                    )}
                </div>
            </div>

            <div className="grid flex-1 gap-4 border-t p-5 text-sm sm:grid-cols-2">
                <section>
                    <h3 className="text-xs font-medium text-muted-foreground">
                        Applications
                    </h3>
                    <ul className="mt-2 space-y-1.5">
                        {[...required, ...optional].map((application) => (
                            <li
                                key={application.name}
                                className="flex items-center justify-between gap-2"
                            >
                                <span className="min-w-0 truncate">
                                    {application.name}
                                    <span className="ml-1.5 text-xs text-muted-foreground">
                                        {application.status}
                                    </span>
                                </span>
                                <span
                                    className={cn(
                                        'shrink-0 rounded px-1.5 py-0.5 text-[11px] font-medium',
                                        application.requirement === 'required'
                                            ? 'bg-primary/10 text-primary'
                                            : 'bg-muted text-muted-foreground',
                                    )}
                                >
                                    {application.requirement === 'required'
                                        ? 'Required'
                                        : 'Optional'}
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>

                <section>
                    <h3 className="text-xs font-medium text-muted-foreground">
                        Plans
                    </h3>
                    {product.plans.length === 0 ? (
                        <p className="mt-2 text-muted-foreground">
                            No plan, so it is not sold yet.{' '}
                            <Link
                                href="/plans"
                                className="text-primary hover:underline"
                            >
                                Add one
                            </Link>
                        </p>
                    ) : (
                        <ul className="mt-2 space-y-1.5">
                            {product.plans.map((plan) => (
                                <li
                                    key={plan.code}
                                    className="flex items-center justify-between gap-2"
                                >
                                    <span className="min-w-0 truncate">
                                        {plan.name}
                                    </span>
                                    <span className="shrink-0 text-xs text-muted-foreground">
                                        {plan.status}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </li>
    );
}
