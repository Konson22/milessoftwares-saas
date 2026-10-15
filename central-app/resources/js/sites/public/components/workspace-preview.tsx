import { Search } from 'lucide-react';
import AppLogoIcon from '@/shared/components/app-logo-icon';
import { productIcon } from '@/sites/public/components/product';
import type { Product } from '@/sites/public/components/product';

/**
 * A drawing of the workspace for the home hero: the real application list
 * in the sidebar, placeholder bars for content. Decorative only, so it is
 * hidden from assistive technology and carries no invented figures.
 */
export default function WorkspacePreview({
    products,
}: {
    products: Product[];
}) {
    const apps = products.slice(0, 6);
    const active = apps[0];

    return (
        <div aria-hidden className="relative">
            <div className="absolute -inset-x-8 -top-8 -bottom-4 -z-10 rounded-[2rem] bg-primary/20 blur-3xl" />
            <div className="overflow-hidden rounded-2xl border border-white/10 bg-zinc-900/80 shadow-2xl ring-1 shadow-black/50 ring-white/5 backdrop-blur">
                <div className="flex items-center gap-2 border-b border-white/10 px-4 py-3">
                    <span className="size-2.5 rounded-full bg-white/15" />
                    <span className="size-2.5 rounded-full bg-white/15" />
                    <span className="size-2.5 rounded-full bg-white/15" />
                    <div className="mx-auto flex h-6 w-full max-w-xs items-center gap-2 rounded-md bg-white/5 px-2">
                        <Search className="size-3 text-zinc-500" />
                        <span className="h-1.5 w-24 rounded-full bg-white/10" />
                    </div>
                </div>
                <div className="grid grid-cols-[3.5rem_1fr] sm:grid-cols-[13rem_1fr]">
                    <div className="space-y-1 border-r border-white/10 p-2 sm:p-3">
                        <div className="mb-3 flex items-center gap-2 px-1.5 py-1">
                            <AppLogoIcon className="size-6" alt="" />
                            <span className="hidden h-2 w-20 rounded-full bg-white/20 sm:block" />
                        </div>
                        {apps.map((product) => {
                            const Icon = productIcon(product.code);
                            const isActive = product === active;

                            return (
                                <div
                                    key={product.code}
                                    className={`flex items-center gap-2.5 rounded-md px-2 py-1.5 text-xs ${isActive ? 'bg-primary/20 text-white' : 'text-zinc-400'}`}
                                >
                                    <Icon
                                        className={`size-4 shrink-0 ${isActive ? 'text-primary' : ''}`}
                                    />
                                    <span className="hidden truncate sm:block">
                                        {product.name}
                                    </span>
                                </div>
                            );
                        })}
                    </div>
                    <div className="space-y-4 p-4 sm:p-6">
                        <div className="flex items-center justify-between">
                            <div className="space-y-2">
                                <span className="block h-2.5 w-32 rounded-full bg-white/25" />
                                <span className="block h-2 w-48 rounded-full bg-white/10" />
                            </div>
                            <span className="hidden h-7 w-24 rounded-md bg-primary/80 sm:block" />
                        </div>
                        <div className="grid grid-cols-2 gap-3 lg:grid-cols-3">
                            {[0, 1, 2].map((i) => (
                                <div
                                    key={i}
                                    className={`space-y-3 rounded-lg border border-white/10 bg-white/[0.03] p-3 ${i === 2 ? 'hidden lg:block' : ''}`}
                                >
                                    <span className="block h-2 w-16 rounded-full bg-white/15" />
                                    <span className="block h-4 w-20 rounded-full bg-white/30" />
                                    <Spark />
                                </div>
                            ))}
                        </div>
                        <div className="rounded-lg border border-white/10 bg-white/[0.03]">
                            {[0, 1, 2, 3].map((i) => (
                                <div
                                    key={i}
                                    className="flex items-center gap-3 border-b border-white/5 px-3 py-2.5 last:border-0"
                                >
                                    <span className="size-6 rounded-full bg-white/10" />
                                    <span
                                        className="h-2 rounded-full bg-white/15"
                                        style={{ width: `${40 - i * 6}%` }}
                                    />
                                    <span className="ml-auto h-2 w-12 rounded-full bg-white/10" />
                                    <span
                                        className={`h-4 w-14 rounded-full ${i % 2 ? 'bg-emerald-400/20' : 'bg-primary/25'}`}
                                    />
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}

/** A small rising line, the shape of a chart without any numbers. */
function Spark() {
    return (
        <svg viewBox="0 0 100 28" className="h-7 w-full" fill="none">
            <path
                d="M0 22 L15 18 L30 20 L45 12 L60 14 L75 6 L100 4"
                className="stroke-primary"
                strokeWidth="2"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        </svg>
    );
}
