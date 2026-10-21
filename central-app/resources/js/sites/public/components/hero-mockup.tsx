import { BellRing, TrendingUp } from 'lucide-react';
import { productIcon } from '@/sites/public/components/product';

/** Bar heights of the mock sales chart, in percent. */
const bars = [38, 52, 44, 68, 57, 80, 72, 92];

const rows = [
    { item: 'Paracetamol 500 mg × 2', amount: '1,200' },
    { item: 'School fees · Term 3', amount: '45,000' },
    { item: 'Rice 25 kg bag', amount: '18,500' },
];

/**
 * A drawn (not photographed) dashboard for the home hero: a browser
 * window with the application sidebar, today's figures, a sales chart and
 * the latest sales, with two cards floating over its corners. Purely
 * decorative, so hidden from assistive technology; the figures are
 * illustrative.
 */
export default function HeroMockup({ codes }: { codes: string[] }) {
    const apps = codes.slice(0, 5);

    return (
        <div
            aria-hidden
            className="relative mx-auto w-full max-w-xl select-none"
        >
            {/* Glow and ring behind the window. */}
            <div className="site-gradient absolute inset-6 -z-10 rounded-[2.5rem] opacity-30 blur-3xl" />
            <div className="absolute -top-8 -right-6 -z-10 size-40 rounded-full border-2 border-dashed border-site-violet/30" />

            <div className="overflow-hidden rounded-3xl border border-white/70 bg-white shadow-[0_40px_90px_-30px_rgba(37,60,160,0.45)] ring-1 ring-site-line">
                <div className="flex items-center gap-1.5 border-b border-site-line bg-site-soft px-4 py-3">
                    <span className="size-2.5 rounded-full bg-site-coral" />
                    <span className="size-2.5 rounded-full bg-amber-300" />
                    <span className="size-2.5 rounded-full bg-emerald-400" />
                    <span className="ml-3 h-5 flex-1 rounded-full bg-white ring-1 ring-site-line" />
                </div>
                <div className="flex">
                    <div className="flex flex-col items-center gap-2.5 bg-site-night px-2.5 py-4">
                        {apps.map((code, i) => {
                            const Icon = productIcon(code);

                            return (
                                <span
                                    key={code}
                                    className={`inline-flex size-8 items-center justify-center rounded-lg ${i === 0 ? 'site-gradient text-white' : 'text-white/60'}`}
                                >
                                    <Icon className="size-4" />
                                </span>
                            );
                        })}
                    </div>
                    <div className="min-w-0 flex-1 space-y-3 p-4">
                        <div className="grid grid-cols-3 gap-2.5">
                            {[
                                ['Sales today', '84,700'],
                                ['Orders', '126'],
                                ['In stock', '2,340'],
                            ].map(([label, value]) => (
                                <div
                                    key={label}
                                    className="rounded-xl bg-site-soft p-2.5"
                                >
                                    <p className="text-[10px] text-slate-500">
                                        {label}
                                    </p>
                                    <p className="font-public-display text-sm font-semibold text-site-ink">
                                        {value}
                                    </p>
                                </div>
                            ))}
                        </div>
                        <div className="rounded-xl border border-site-line p-3">
                            <div className="flex items-center justify-between">
                                <p className="text-[11px] font-semibold text-site-ink">
                                    Sales this week
                                </p>
                                <span className="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-bold text-emerald-700">
                                    +18%
                                </span>
                            </div>
                            <div className="mt-3 flex h-20 items-end gap-1.5">
                                {bars.map((height, i) => (
                                    <span
                                        key={i}
                                        className={`flex-1 rounded-t-md ${i === bars.length - 1 ? 'site-gradient' : 'bg-site-accent/20'}`}
                                        style={{ height: `${height}%` }}
                                    />
                                ))}
                            </div>
                        </div>
                        <ul className="space-y-1.5">
                            {rows.map((row) => (
                                <li
                                    key={row.item}
                                    className="flex items-center justify-between rounded-lg px-2 py-1.5 text-[11px] odd:bg-site-soft"
                                >
                                    <span className="truncate text-slate-600">
                                        {row.item}
                                    </span>
                                    <span className="font-semibold text-site-ink tabular-nums">
                                        {row.amount}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
            </div>

            {/* Floating cards over the corners. */}
            <div className="site-float absolute -bottom-6 -left-4 flex items-center gap-3 rounded-2xl bg-white p-3 pr-5 shadow-xl ring-1 shadow-site-ink/15 ring-site-line sm:-left-10">
                <span className="site-gradient inline-flex size-10 items-center justify-center rounded-xl text-white">
                    <TrendingUp className="size-5" />
                </span>
                <span>
                    <span className="block text-[11px] text-slate-500">
                        Sale recorded
                    </span>
                    <span className="block font-public-display text-sm font-semibold text-site-ink">
                        + 12,500
                    </span>
                </span>
            </div>
            <div className="site-float-slow absolute -top-5 -right-2 flex items-center gap-2.5 rounded-2xl bg-white p-3 pr-4 shadow-xl ring-1 shadow-site-ink/15 ring-site-line sm:-right-8">
                <span className="inline-flex size-9 items-center justify-center rounded-xl bg-site-coral/15 text-[#c4413b]">
                    <BellRing className="size-4" />
                </span>
                <span>
                    <span className="block text-[11px] text-slate-500">
                        Low stock
                    </span>
                    <span className="block text-xs font-semibold text-site-ink">
                        3 items to reorder
                    </span>
                </span>
            </div>
        </div>
    );
}
