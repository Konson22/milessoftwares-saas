import { Search } from 'lucide-react';
import AppLogoIcon from '@/shared/components/app-logo-icon';
import { productIcon } from '@/sites/public/components/product';
import type { Product } from '@/sites/public/components/product';

/**
 * A drawing of the workspace for the home hero: the real application list
 * in the sidebar, placeholder bars for content, monochrome with the
 * accent.
 * Decorative only, so it is hidden from assistive technology and carries
 * no invented figures.
 */
export default function WorkspacePreview({
    products,
}: {
    products: Product[];
}) {
    const apps = products.slice(0, 6);
    const active = apps[0];

    return (
        <div
            aria-hidden
            className="overflow-hidden rounded-2xl border border-site-line bg-white text-left shadow-2xl ring-4 shadow-site-night/40 ring-white/10 sm:ring-8"
        >
            <div className="flex items-center gap-2 border-b border-slate-100 px-4 py-3">
                <span className="size-2.5 rounded-full bg-slate-200" />
                <span className="size-2.5 rounded-full bg-slate-200" />
                <span className="size-2.5 rounded-full bg-slate-200" />
                <div className="mx-auto flex h-6 w-full max-w-xs items-center gap-2 rounded-full bg-slate-100 px-3">
                    <Search className="size-3 text-slate-400" />
                    <span className="h-1.5 w-24 rounded-full bg-slate-200" />
                </div>
            </div>
            <div className="grid grid-cols-[3.5rem_1fr] sm:grid-cols-[13rem_1fr]">
                <div className="space-y-1 bg-site-black p-2 sm:p-3">
                    <div className="mb-3 flex items-center gap-2 px-1.5 py-1">
                        <AppLogoIcon className="size-6" alt="" />
                        <span className="hidden h-2 w-20 rounded-full bg-white/25 sm:block" />
                    </div>
                    {apps.map((product) => {
                        const Icon = productIcon(product.code);
                        const isActive = product === active;

                        return (
                            <div
                                key={product.code}
                                className={`flex items-center gap-2.5 rounded-lg px-2 py-1.5 text-xs ${isActive ? 'bg-brand-blue font-medium text-white' : 'text-slate-300'}`}
                            >
                                <Icon className="size-4 shrink-0" />
                                <span className="hidden truncate sm:block">
                                    {product.name}
                                </span>
                            </div>
                        );
                    })}
                </div>
                <div className="space-y-4 bg-slate-50 p-4 sm:p-6">
                    <div className="flex items-center justify-between">
                        <div className="space-y-2">
                            <span className="block h-2.5 w-32 rounded-full bg-slate-300" />
                            <span className="block h-2 w-48 rounded-full bg-slate-200" />
                        </div>
                        <span className="hidden h-7 w-24 rounded-full bg-brand-blue sm:block" />
                    </div>
                    <div className="grid grid-cols-2 gap-3 lg:grid-cols-3">
                        {(
                            [
                                'bg-brand-blue',
                                'bg-neutral-800',
                                'bg-neutral-400',
                            ] as const
                        ).map((color, i) => (
                            <div
                                key={color}
                                className={`space-y-3 rounded-xl border border-slate-100 bg-white p-3 shadow-sm ${i === 2 ? 'hidden lg:block' : ''}`}
                            >
                                <span className="block h-2 w-16 rounded-full bg-slate-200" />
                                <span className="block h-4 w-20 rounded-full bg-slate-300" />
                                <Bars seed={i} color={color} />
                            </div>
                        ))}
                    </div>
                    <div className="rounded-xl border border-slate-100 bg-white shadow-sm">
                        {[0, 1, 2, 3].map((i) => (
                            <div
                                key={i}
                                className="flex items-center gap-3 border-b border-slate-100 px-3 py-2.5 last:border-0"
                            >
                                <span className="size-6 rounded-full bg-slate-100" />
                                <span
                                    className="h-2 rounded-full bg-slate-200"
                                    style={{ width: `${40 - i * 6}%` }}
                                />
                                <span className="ml-auto h-2 w-12 rounded-full bg-slate-100" />
                                <span
                                    className={`h-4 w-14 rounded-full ${i % 2 ? 'bg-neutral-200' : 'bg-brand-blue/15'}`}
                                />
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </div>
    );
}

/** A small bar chart shape without any numbers. */
function Bars({ seed, color }: { seed: number; color: string }) {
    const heights = [40, 65, 50, 80, 60, 95, 70].map(
        (height, i) => ((height + seed * 13 + i * 7) % 70) + 30,
    );

    return (
        <div className="flex h-10 items-end gap-1">
            {heights.map((height, i) => (
                <span
                    key={i}
                    className={`flex-1 rounded-sm ${color} ${i % 2 ? 'opacity-50' : ''}`}
                    style={{ height: `${height}%` }}
                />
            ))}
        </div>
    );
}
