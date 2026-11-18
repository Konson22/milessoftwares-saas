import {
    ArcElement,
    BarElement,
    CategoryScale,
    Chart as ChartJS,
    Legend,
    LinearScale,
    Tooltip,
} from 'chart.js';
import type { ChartOptions, TooltipItem } from 'chart.js';
import { useEffect, useState } from 'react';
import { Bar, Doughnut } from 'react-chartjs-2';

ChartJS.register(
    ArcElement,
    BarElement,
    CategoryScale,
    LinearScale,
    Tooltip,
    Legend,
);

export type Palette = {
    series: string[];
    text: string;
    muted: string;
    grid: string;
    card: string;
};

function read(): Palette {
    const style = getComputedStyle(document.documentElement);
    const get = (name: string, fallback: string) =>
        style.getPropertyValue(name).trim() || fallback;

    return {
        series: [
            get('--chart-1', '#0058f5'),
            get('--chart-2', '#03d5cd'),
            get('--chart-3', '#0a2260'),
            get('--chart-4', '#00a2f5'),
            get('--chart-5', '#515f6d'),
            '#f59e0b',
            '#16a34a',
            '#dc2626',
        ],
        text: get('--foreground', '#1f2937'),
        muted: get('--muted-foreground', '#6b7280'),
        grid: get('--border', '#e5e7eb'),
        card: get('--card', '#ffffff'),
    };
}

/** The theme's chart colours, read again when the page turns dark or light. */
export function usePalette(): Palette {
    const [palette, setPalette] = useState(read);

    useEffect(() => {
        const observer = new MutationObserver(() => setPalette(read()));
        observer.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class', 'style', 'data-theme'],
        });

        return () => observer.disconnect();
    }, []);

    return palette;
}

/**
 * Bars of money (one series), with amounts in the axis and tooltips.
 */
export function MoneyBars({
    labels,
    values,
    format,
    axisFormat,
    horizontal = false,
    color,
    tooltipNote,
    height = 260,
    label,
}: {
    labels: string[];
    values: number[];
    format: (value: number) => string;
    axisFormat?: (value: number) => string;
    horizontal?: boolean;
    color?: string;
    tooltipNote?: (index: number) => string | undefined;
    height?: number;
    label: string;
}) {
    const palette = usePalette();
    const fill = color ?? palette.series[0];
    const valueAxis = {
        beginAtZero: true,
        grid: { color: palette.grid },
        border: { display: false },
        ticks: {
            color: palette.muted,
            callback: (value: string | number) =>
                (axisFormat ?? format)(Number(value)),
            maxTicksLimit: 6,
        },
    };
    const categoryAxis = {
        grid: { display: false },
        border: { color: palette.grid },
        ticks: { color: palette.muted, autoSkip: true, maxRotation: 0 },
    };
    const options: ChartOptions<'bar'> = {
        indexAxis: horizontal ? 'y' : 'x',
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 300 },
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: palette.text,
                titleColor: palette.card,
                bodyColor: palette.card,
                cornerRadius: 0,
                displayColors: false,
                callbacks: {
                    label: (item: TooltipItem<'bar'>) => {
                        const note = tooltipNote?.(item.dataIndex);

                        return [
                            format(Number(item.raw)),
                            ...(note ? [note] : []),
                        ];
                    },
                },
            },
        },
        scales: horizontal
            ? { x: valueAxis, y: categoryAxis }
            : { x: categoryAxis, y: valueAxis },
    };

    return (
        <div style={{ height }}>
            <Bar
                aria-label={label}
                role="img"
                options={options}
                data={{
                    labels,
                    datasets: [
                        {
                            label,
                            data: values,
                            backgroundColor: values.map((value) =>
                                value < 0 ? '#dc2626' : fill,
                            ),
                            hoverBackgroundColor: palette.series[2],
                            borderRadius: 0,
                            maxBarThickness: horizontal ? 22 : 36,
                        },
                    ],
                }}
            />
        </div>
    );
}

/**
 * Parts of a whole as a ring, with the total in the middle. The legend is
 * left to the page, so it can show amounts beside each part.
 */
export function Ring({
    labels,
    values,
    colors,
    format,
    centre,
    centreNote,
    label,
    size = 200,
}: {
    labels: string[];
    values: number[];
    colors: string[];
    format: (value: number) => string;
    centre: string;
    centreNote?: string;
    label: string;
    size?: number;
}) {
    const palette = usePalette();
    const options: ChartOptions<'doughnut'> = {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '72%',
        animation: { duration: 300 },
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: palette.text,
                titleColor: palette.card,
                bodyColor: palette.card,
                cornerRadius: 0,
                callbacks: {
                    label: (item: TooltipItem<'doughnut'>) =>
                        `${item.label}: ${format(Number(item.raw))}`,
                },
            },
        },
    };

    return (
        <div className="relative mx-auto" style={{ height: size, width: size }}>
            <Doughnut
                aria-label={label}
                role="img"
                options={options}
                data={{
                    labels,
                    datasets: [
                        {
                            data: values,
                            backgroundColor: colors,
                            borderColor: palette.card,
                            borderWidth: 2,
                            hoverOffset: 4,
                        },
                    ],
                }}
            />
            <div className="pointer-events-none absolute inset-0 grid place-items-center text-center">
                <div>
                    <p className="text-lg font-semibold tabular-nums">
                        {centre}
                    </p>
                    {centreNote && (
                        <p className="text-xs text-muted-foreground">
                            {centreNote}
                        </p>
                    )}
                </div>
            </div>
        </div>
    );
}
