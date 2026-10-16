import { productIcon } from '@/sites/public/components/product';

/**
 * A small drawing of an application's main screen, for the top of its
 * card: a tinted backdrop, a window with the application's icon, and a
 * layout that hints at what it does. Shapes only, no invented figures, so
 * it is hidden from assistive technology.
 */

type Layout = 'till' | 'chart' | 'table' | 'calendar';

/** Full class names, so Tailwind sees them. */
const themes = {
    blue: {
        wash: 'from-blue-100 to-blue-50',
        solid: 'bg-blue-500',
        soft: 'bg-blue-200',
        text: 'text-blue-600',
    },
    emerald: {
        wash: 'from-emerald-100 to-emerald-50',
        solid: 'bg-emerald-500',
        soft: 'bg-emerald-200',
        text: 'text-emerald-600',
    },
    violet: {
        wash: 'from-violet-100 to-violet-50',
        solid: 'bg-violet-500',
        soft: 'bg-violet-200',
        text: 'text-violet-600',
    },
    amber: {
        wash: 'from-amber-100 to-amber-50',
        solid: 'bg-amber-500',
        soft: 'bg-amber-200',
        text: 'text-amber-600',
    },
    rose: {
        wash: 'from-rose-100 to-rose-50',
        solid: 'bg-rose-500',
        soft: 'bg-rose-200',
        text: 'text-rose-600',
    },
    cyan: {
        wash: 'from-cyan-100 to-cyan-50',
        solid: 'bg-cyan-500',
        soft: 'bg-cyan-200',
        text: 'text-cyan-600',
    },
    indigo: {
        wash: 'from-indigo-100 to-indigo-50',
        solid: 'bg-indigo-500',
        soft: 'bg-indigo-200',
        text: 'text-indigo-600',
    },
    orange: {
        wash: 'from-orange-100 to-orange-50',
        solid: 'bg-orange-500',
        soft: 'bg-orange-200',
        text: 'text-orange-600',
    },
} as const;

type Theme = (typeof themes)[keyof typeof themes];

const known: Record<string, { theme: keyof typeof themes; layout: Layout }> = {
    pharmacy: { theme: 'emerald', layout: 'till' },
    retail: { theme: 'blue', layout: 'till' },
    restaurant: { theme: 'orange', layout: 'till' },
    inventory: { theme: 'amber', layout: 'table' },
    finance: { theme: 'indigo', layout: 'chart' },
    payroll: { theme: 'violet', layout: 'table' },
    crm: { theme: 'cyan', layout: 'chart' },
    hr: { theme: 'rose', layout: 'table' },
    school: { theme: 'indigo', layout: 'calendar' },
    clinic: { theme: 'cyan', layout: 'calendar' },
};

/** Unknown codes still get a stable look, derived from the code. */
function lookFor(code: string): { theme: Theme; layout: Layout } {
    if (known[code]) {
        return { theme: themes[known[code].theme], layout: known[code].layout };
    }

    let sum = 0;
    for (let i = 0; i < code.length; i++) {
        sum += code.charCodeAt(i);
    }
    const names = Object.keys(themes) as (keyof typeof themes)[];
    const layouts: Layout[] = ['till', 'chart', 'table', 'calendar'];

    return {
        theme: themes[names[sum % names.length]],
        layout: layouts[sum % 4],
    };
}

export default function ProductArt({ code }: { code: string }) {
    const { theme, layout } = lookFor(code);
    const Icon = productIcon(code);

    return (
        <div
            aria-hidden
            className={`relative flex h-44 items-end justify-center overflow-hidden bg-linear-to-br px-6 pt-6 ${theme.wash}`}
        >
            {/* Soft rings in the corner, behind the window. */}
            <span className="absolute -top-10 -right-10 size-32 rounded-full border-[18px] border-white/50" />
            <div className="relative w-full max-w-64 translate-y-2 rounded-t-xl bg-white shadow-xl ring-1 shadow-slate-900/10 ring-slate-900/5 transition-transform duration-300 group-hover:translate-y-0">
                <div className="flex items-center gap-1.5 border-b border-slate-100 px-3 py-2">
                    <span className="size-1.5 rounded-full bg-slate-200" />
                    <span className="size-1.5 rounded-full bg-slate-200" />
                    <span className="size-1.5 rounded-full bg-slate-200" />
                    <span
                        className={`ml-auto inline-flex size-5 items-center justify-center rounded-md text-white ${theme.solid}`}
                    >
                        <Icon className="size-3" />
                    </span>
                </div>
                <div className="h-28 p-3">
                    {layout === 'till' && <Till theme={theme} />}
                    {layout === 'chart' && <Chart theme={theme} />}
                    {layout === 'table' && <Table theme={theme} />}
                    {layout === 'calendar' && <Calendar theme={theme} />}
                </div>
            </div>
        </div>
    );
}

/** Item tiles on the left, a receipt with a pay button on the right. */
function Till({ theme }: { theme: Theme }) {
    return (
        <div className="grid h-full grid-cols-[1fr_4.5rem] gap-2">
            <div className="grid grid-cols-3 gap-1.5">
                {Array.from({ length: 6 }, (_, i) => (
                    <span
                        key={i}
                        className={`rounded-md ${i === 1 ? theme.soft : 'bg-slate-100'}`}
                    />
                ))}
            </div>
            <div className="flex flex-col gap-1.5 rounded-md bg-slate-50 p-1.5">
                <span className="h-1.5 w-full rounded-full bg-slate-200" />
                <span className="h-1.5 w-3/4 rounded-full bg-slate-200" />
                <span className="h-1.5 w-5/6 rounded-full bg-slate-200" />
                <span className={`mt-auto h-4 rounded ${theme.solid}`} />
            </div>
        </div>
    );
}

/** Two summary tiles over a bar chart. */
function Chart({ theme }: { theme: Theme }) {
    const bars = [40, 65, 50, 80, 60, 95, 75];

    return (
        <div className="flex h-full flex-col gap-2">
            <div className="grid grid-cols-2 gap-1.5">
                <span className="h-5 rounded-md bg-slate-100" />
                <span className={`h-5 rounded-md ${theme.soft}`} />
            </div>
            <div className="flex flex-1 items-end gap-1">
                {bars.map((height, i) => (
                    <span
                        key={i}
                        className={`flex-1 rounded-sm ${i % 2 ? theme.solid : theme.soft}`}
                        style={{ height: `${height}%` }}
                    />
                ))}
            </div>
        </div>
    );
}

/** Rows with an avatar, a name bar and a status pill. */
function Table({ theme }: { theme: Theme }) {
    return (
        <div className="flex h-full flex-col justify-between">
            {[0, 1, 2, 3].map((i) => (
                <div key={i} className="flex items-center gap-2">
                    <span
                        className={`size-4 rounded-full ${i === 0 ? theme.soft : 'bg-slate-100'}`}
                    />
                    <span
                        className="h-1.5 rounded-full bg-slate-200"
                        style={{ width: `${45 - i * 7}%` }}
                    />
                    <span
                        className={`ml-auto h-3 w-8 rounded-full ${i % 2 ? 'bg-slate-100' : theme.soft}`}
                    />
                </div>
            ))}
        </div>
    );
}

/** A month grid with a few booked days. */
function Calendar({ theme }: { theme: Theme }) {
    const booked = new Set([3, 8, 9, 15, 19]);

    return (
        <div className="grid h-full grid-cols-7 gap-1">
            {Array.from({ length: 21 }, (_, i) => (
                <span
                    key={i}
                    className={`rounded-sm ${i === 9 ? theme.solid : booked.has(i) ? theme.soft : 'bg-slate-100'}`}
                />
            ))}
        </div>
    );
}
