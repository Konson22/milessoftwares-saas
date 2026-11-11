import { Link } from '@inertiajs/react';
import { useId, useState } from 'react';
import type { CSSProperties } from 'react';
import {
    heroApplications,
    heroDataFlows,
    heroStage,
} from '@/sites/public/components/hero-applications';
import type { HeroApplication } from '@/sites/public/components/hero-applications';
import HeroDashboard from '@/sites/public/components/hero-dashboard';

const {
    width: W,
    height: H,
    center: C,
    rx: RX,
    ry: RY,
    dashboard: D,
    icon: ICON,
    turn: TURN,
} = heroStage;

/** A stage length as a percentage of the stage's width or height. */
const pct = (value: number, total: number) => `${(value / total) * 100}%`;

/**
 * Entrance timing: the dashboard first, then each icon slides out from
 * behind it along its spoke (1s each, see eco-out in app.css), then the
 * lines.
 */
const iconDelay = (index: number) => 200 + index * 85;
const lineDelay = (index: number) => 1250 + index * 60;

/**
 * The home hero's illustration: the Tatook dashboard with the eight
 * application areas slowly circling it, each joined to it by a line that a
 * few dots travel along.
 *
 * How the orbit works, with transforms only. The ring is a circle of
 * radius RX, squashed to an ellipse (scaleY RY/RX) and turned once every
 * TURN seconds. Each icon sits on a spoke of the ring at its starting
 * angle; inside, it undoes the spoke's angle, the ring's turn and the
 * squash, so it stays upright and the right shape wherever it is. The
 * lines are drawn in the ring, so they turn with it. Hovering or focusing
 * the illustration pauses the turn, so the links are easy to use.
 *
 * With reduced motion nothing turns, floats or slides: the icons rest at
 * their starting angles.
 */
export default function HeroEcosystem() {
    const uid = useId().replace(/[^a-zA-Z0-9_-]/g, '');
    const [active, setActive] = useState<string | null>(null);
    const lineId = (id: string) => `${uid}-line-${id}`;

    return (
        <figure
            aria-labelledby={`${uid}-caption`}
            className="eco-orbit @container relative mx-auto aspect-[700/650] w-full max-w-[40rem]"
            style={
                {
                    '--turn': `${TURN}s`,
                    '--squash': RY / RX,
                    '--unsquash': RX / RY,
                } as CSSProperties
            }
        >
            <figcaption id={`${uid}-caption`} className="sr-only">
                The Tatook dashboard with eight areas of a business circling it:
                analytics, finance, human resources, inventory, sales and point
                of sale, projects, scheduling and operations.
            </figcaption>

            {/* A soft halo behind the dashboard. */}
            <div
                aria-hidden
                className="eco-halo pointer-events-none absolute aspect-[1.5] -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-[#72ee8b22] shadow-[0_0_0_16px_#77de7220]"
                style={{
                    left: pct(C.x, W),
                    top: pct(C.y, H),
                    width: pct(D.width * 1.25, W),
                }}
            />

            {/* The orbit: a square around the centre, squashed to an ellipse. */}
            <div
                className="absolute aspect-square [transform:translate(-50%,-50%)_scaleY(var(--squash))]"
                style={{
                    left: pct(C.x, W),
                    top: pct(C.y, H),
                    width: pct(RX * 2, W),
                }}
            >
                <div className="eco-spin @container absolute inset-0">
                    <svg
                        aria-hidden
                        viewBox={`${-RX} ${-RX} ${RX * 2} ${RX * 2}`}
                        fill="none"
                        className="pointer-events-none absolute inset-0 size-full overflow-visible"
                    >
                        {heroApplications.map((app, index) => {
                            const on = active === app.id;
                            const a = (app.angle * Math.PI) / 180;
                            // From just inside the icon's edge to the centre.
                            const r = RX - ICON * 0.42;

                            return (
                                <path
                                    key={app.id}
                                    id={lineId(app.id)}
                                    d={`M ${r * Math.cos(a)} ${r * Math.sin(a)} L 0 0`}
                                    pathLength={1}
                                    vectorEffect="non-scaling-stroke"
                                    stroke={on ? '#A3E635' : '#10B981'}
                                    strokeOpacity={on ? 0.95 : 0.4}
                                    strokeWidth={on ? 2.5 : 1.5}
                                    strokeLinecap="round"
                                    className="eco-line"
                                    style={
                                        {
                                            '--d': `${lineDelay(index)}ms`,
                                        } as CSSProperties
                                    }
                                />
                            );
                        })}
                        {heroDataFlows.map((flow) => (
                            <g
                                key={flow.id}
                                className="eco-particle"
                                opacity={0}
                            >
                                <circle
                                    r={7}
                                    fill="#A3E635"
                                    fillOpacity={0.25}
                                />
                                <circle r={3} fill="#A3E635" />
                                <animateMotion
                                    dur="6s"
                                    begin={`${flow.begin}s`}
                                    repeatCount="indefinite"
                                    calcMode="linear"
                                    keyPoints={
                                        flow.direction === 'in'
                                            ? '0;1;1'
                                            : '1;0;0'
                                    }
                                    keyTimes="0;0.4;1"
                                >
                                    <mpath href={`#${lineId(flow.id)}`} />
                                </animateMotion>
                                <animate
                                    attributeName="opacity"
                                    dur="6s"
                                    begin={`${flow.begin}s`}
                                    repeatCount="indefinite"
                                    values="0;1;1;0;0"
                                    keyTimes="0;0.06;0.34;0.4;1"
                                />
                            </g>
                        ))}
                    </svg>

                    <ul>
                        {heroApplications.map((app, index) => (
                            <li
                                key={app.id}
                                // The spoke: the whole ring, turned to the
                                // icon's starting angle.
                                className="pointer-events-none absolute inset-0"
                                style={{ transform: `rotate(${app.angle}deg)` }}
                            >
                                <div
                                    className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2"
                                    style={{ width: pct(ICON, RX * 2) }}
                                >
                                    {/* Out along the spoke to the rim. */}
                                    <div
                                        className="eco-out"
                                        style={
                                            {
                                                '--d': `${iconDelay(index)}ms`,
                                            } as CSSProperties
                                        }
                                    >
                                        {/* Undo the spoke's angle. */}
                                        <div
                                            style={{
                                                transform: `rotate(${-app.angle}deg)`,
                                            }}
                                        >
                                            {/* Undo the ring's turn and the squash. */}
                                            <div className="eco-upright">
                                                <div
                                                    onMouseEnter={() =>
                                                        setActive(app.id)
                                                    }
                                                    onMouseLeave={() =>
                                                        setActive(null)
                                                    }
                                                    className="eco-float pointer-events-auto"
                                                    style={
                                                        {
                                                            '--f': `${-index * 0.73}s`,
                                                            '--fd': `${4.8 + (index % 3) * 0.7}s`,
                                                        } as CSSProperties
                                                    }
                                                >
                                                    <ApplicationIcon
                                                        app={app}
                                                        onFocus={() =>
                                                            setActive(app.id)
                                                        }
                                                        onBlur={() =>
                                                            setActive(null)
                                                        }
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            </div>

            {/* The dashboard, above the orbit so icons slide out from behind it. */}
            <div
                className="eco-dash absolute z-30 aspect-[1.52] rounded-[5.5%/8.4%] shadow-[0_24px_65px_#011b0eaa,0_0_0_1px_#77dca7,0_0_36px_#30cb6830]"
                style={{
                    left: pct(C.x - D.width / 2, W),
                    top: pct(C.y - D.height / 2, H),
                    width: pct(D.width, W),
                }}
            >
                <HeroDashboard />
            </div>
        </figure>
    );
}

/** One application: its icon and name, a link where the site has a page. */
function ApplicationIcon({
    app,
    onFocus,
    onBlur,
}: {
    app: HeroApplication;
    onFocus: () => void;
    onBlur: () => void;
}) {
    const body = (
        <>
            <img
                src={app.icon}
                alt=""
                width={192}
                height={192}
                decoding="async"
                className="mx-auto block h-auto w-full drop-shadow-[0_6px_10px_rgb(0_0_0/0.25)] motion-safe:transition-transform motion-safe:duration-300 group-hover:scale-110 group-focus-visible:scale-110"
            />
            <span className="absolute top-full left-1/2 mt-[0.5cqw] block w-max -translate-x-1/2 text-center text-[clamp(0.5rem,2.6cqw,0.8125rem)] whitespace-nowrap leading-tight font-semibold text-slate-200 transition-colors group-hover:text-site-highlight group-focus-visible:text-site-highlight">
                {app.name}
            </span>
        </>
    );
    const shared =
        'group relative block rounded-[22%] outline-none focus-visible:ring-2 focus-visible:ring-site-highlight focus-visible:ring-offset-4 focus-visible:ring-offset-[#073517]';

    if (app.href) {
        return (
            <Link
                href={app.href}
                className={shared}
                onFocus={onFocus}
                onBlur={onBlur}
            >
                {body}
            </Link>
        );
    }

    return <div className={shared}>{body}</div>;
}
