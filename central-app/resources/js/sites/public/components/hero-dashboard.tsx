import type { CSSProperties } from 'react';

/** Illustrative only: not live figures, and nothing on it is real data. */
const stats = [
    { label: 'Revenue', value: '$24.8k', trend: '↗ +12%' },
    { label: 'Orders', value: '1,284', trend: '↗ +8%' },
    { label: 'Active', value: '96%', trend: '● Online' },
];

const bars = [40, 55, 47, 73, 65, 91];

const activity = ['#87D83B', '#10B981', '#B1DD3D', '#0E9976'];

/**
 * The dashboard at the centre of the home hero, in HTML so it stays sharp
 * and can move: the bars rise, the trend line draws, the activity rows
 * slide in, the status dot pulses and a sheen crosses it now and then (see
 * the eco-* rules in app.css). With reduced motion it shows its final
 * state. Sizes are in container units, so it scales with its box.
 * Decorative: the whole drawing is hidden from assistive technology.
 */
export default function HeroDashboard() {
    return (
        <div
            aria-hidden
            className="@container relative size-full overflow-hidden rounded-[5.5%/8.4%] border-[1.1cqw] border-[#125B3B] bg-[#F4FFF8] font-public select-none"
        >
            {/* Title bar. */}
            <div className="flex h-[16%] items-center gap-[3cqw] bg-[#075B36] px-[4cqw] text-[2.8cqw] font-bold text-[#F1FFF4]">
                <span className="aspect-square w-[3.6cqw] bg-[#B3ED52] [clip-path:polygon(0_0,100%_0,58%_50%,58%_100%,26%_100%,26%_50%,0_50%)]" />
                <span>Tatook</span>
                <span className="eco-live aspect-square w-[1.5cqw] rounded-full bg-[#7CE644]" />
                <span className="ml-auto flex gap-[0.9cqw]">
                    {[0, 1, 2].map((i) => (
                        <i
                            key={i}
                            className="aspect-square w-[1.1cqw] rounded-full bg-[#9CDEB1]"
                        />
                    ))}
                </span>
            </div>

            <div className="grid h-[84%] grid-cols-[19%_81%]">
                {/* Sidebar. */}
                <div className="flex flex-col gap-[2.6cqw] border-r border-[#C9E7D2] bg-[#E1F6E9] px-[3cqw] py-[4cqw]">
                    {[0, 1, 2, 3, 4].map((i) => (
                        <i
                            key={i}
                            className={`h-[1.3cqw] rounded-full ${i === 0 ? 'w-full bg-[#4DA675]' : 'w-4/5 bg-[#A8D0B6]'}`}
                        />
                    ))}
                </div>

                <div className="flex min-w-0 flex-col gap-[2.6cqw] p-[3cqw]">
                    {/* Summary cards. */}
                    <div className="grid h-[31%] grid-cols-3 gap-[2.2cqw]">
                        {stats.map((stat) => (
                            <div
                                key={stat.label}
                                className="flex min-w-0 flex-col justify-around rounded-[1.3cqw] border border-[#D9EEE1] bg-white px-[2cqw] py-[1.4cqw]"
                            >
                                <span className="text-[2.1cqw] font-semibold whitespace-nowrap text-[#5A7E69]">
                                    {stat.label}
                                </span>
                                <span className="text-[4.2cqw] leading-none font-extrabold tracking-tight text-[#064E3B]">
                                    {stat.value}
                                </span>
                                <span className="text-[2.1cqw] font-bold text-[#1C9D50]">
                                    {stat.trend}
                                </span>
                            </div>
                        ))}
                    </div>

                    <div className="grid min-h-0 flex-1 grid-cols-[1.55fr_1fr] gap-[2.2cqw]">
                        {/* Performance chart. */}
                        <div className="flex min-h-0 min-w-0 flex-col rounded-[1.3cqw] border border-[#D9EEE1] bg-white p-[2.2cqw]">
                            <span className="text-[2.3cqw] font-bold text-[#174D35]">
                                Business performance
                            </span>
                            <div className="relative mt-[1.6cqw] flex min-h-0 flex-1 items-end gap-[2cqw] border-b border-[#D6E9DD] px-[1.6cqw]">
                                {bars.map((height, n) => (
                                    <span
                                        key={n}
                                        className={`eco-bar flex-1 origin-bottom rounded-t-[0.7cqw] ${n % 2 ? 'bg-linear-to-t from-[#047E5A] to-[#38C88D]' : 'bg-linear-to-t from-[#048653] to-[#9CDE33]'}`}
                                        style={
                                            {
                                                height: `${height}%`,
                                                '--n': n,
                                            } as CSSProperties
                                        }
                                    />
                                ))}
                                <svg
                                    viewBox="0 0 150 100"
                                    preserveAspectRatio="none"
                                    className="pointer-events-none absolute inset-x-[3%] top-[8%] h-[75%] w-[94%] overflow-visible"
                                >
                                    <polyline
                                        points="5,79 32,62 58,67 85,40 109,48 145,13"
                                        pathLength={1}
                                        fill="none"
                                        stroke="#C1E344"
                                        strokeWidth={2.5}
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        vectorEffect="non-scaling-stroke"
                                        className="eco-trend"
                                    />
                                </svg>
                            </div>
                        </div>

                        {/* Recent activity. */}
                        <div className="min-h-0 min-w-0 overflow-hidden rounded-[1.3cqw] border border-[#D9EEE1] bg-white p-[2.2cqw]">
                            <span className="text-[2.3cqw] font-bold text-[#174D35]">
                                Activity
                            </span>
                            {activity.map((dot, j) => (
                                <div
                                    key={j}
                                    className="eco-activity mt-[2.2cqw] flex items-center gap-[1.6cqw]"
                                    style={{ '--j': j } as CSSProperties}
                                >
                                    <b
                                        className="aspect-square w-[1.8cqw] shrink-0 rounded-full"
                                        style={{ backgroundColor: dot }}
                                    />
                                    <span className="flex flex-1 flex-col gap-[0.8cqw]">
                                        <i className="h-[0.9cqw] w-[90%] rounded-full bg-[#B8DEC7]" />
                                        <i className="h-[0.9cqw] w-[60%] rounded-full bg-[#DCEEE3]" />
                                    </span>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </div>

            {/* A soft light that crosses the screen now and then. */}
            <span className="eco-sheen pointer-events-none absolute inset-y-0 left-[-45%] w-[20%] bg-linear-to-r from-transparent via-white/60 to-transparent opacity-40" />
        </div>
    );
}
