/**
 * Code 128 (set B) bar widths, by symbol value: bar, space, bar, space,
 * bar, space, in modules.
 */
const PATTERNS = [
    '212222',
    '222122',
    '222221',
    '121223',
    '121322',
    '131222',
    '122213',
    '122312',
    '132212',
    '221213',
    '221312',
    '231212',
    '112232',
    '122132',
    '122231',
    '113222',
    '123122',
    '123221',
    '223211',
    '221132',
    '221231',
    '213212',
    '223112',
    '312131',
    '311222',
    '321122',
    '321221',
    '312212',
    '322112',
    '322211',
    '212123',
    '212321',
    '232121',
    '111323',
    '131123',
    '131321',
    '112313',
    '132113',
    '132311',
    '211313',
    '231113',
    '231311',
    '112133',
    '112331',
    '132131',
    '113123',
    '113321',
    '133121',
    '313121',
    '211331',
    '231131',
    '213113',
    '213311',
    '213131',
    '311123',
    '311321',
    '331121',
    '312113',
    '312311',
    '332111',
    '314111',
    '221411',
    '431111',
    '111224',
    '111422',
    '121124',
    '121421',
    '141122',
    '141221',
    '112214',
    '112412',
    '122114',
    '122411',
    '142112',
    '142211',
    '241211',
    '221114',
    '413111',
    '241112',
    '134111',
    '111242',
    '121142',
    '121241',
    '114212',
    '124112',
    '124211',
    '411212',
    '421112',
    '421211',
    '212141',
    '214121',
    '412121',
    '111143',
    '111341',
    '131141',
    '114113',
    '114311',
    '411113',
    '411311',
    '113141',
    '114131',
    '311141',
    '411131',
    '211412',
    '211214',
    '211232',
];
const START_B = 104;
const STOP = '2331112';

/**
 * The modules of a Code 128 B symbol for printable ASCII text: start,
 * data, checksum, stop. Characters outside space…tilde are left out.
 */
export function code128(text: string): number[] {
    const values = text
        .split('')
        .map((char) => char.charCodeAt(0) - 32)
        .filter((value) => value >= 0 && value < 95);
    const checksum =
        values.reduce((sum, value, i) => sum + value * (i + 1), START_B) % 103;

    return [START_B, ...values, checksum]
        .flatMap((value) => PATTERNS[value].split('').map(Number))
        .concat(STOP.split('').map(Number));
}

/**
 * A scannable Code 128 barcode of the text, drawn as SVG so it prints
 * sharp on any printer; the text is printed under it.
 */
export function Barcode({
    value,
    height = 40,
    className,
}: {
    value: string;
    height?: number;
    className?: string;
}) {
    const modules = code128(value);
    const quiet = 10;
    const width = modules.reduce((sum, w) => sum + w, 0) + quiet * 2;
    let x = quiet;

    return (
        <figure className={className}>
            <svg
                viewBox={`0 0 ${width} ${height}`}
                preserveAspectRatio="none"
                className="h-10 w-full"
                role="img"
                aria-label={`Barcode ${value}`}
                shapeRendering="crispEdges"
            >
                {modules.map((w, i) => {
                    const bar =
                        i % 2 === 0 ? (
                            <rect
                                key={i}
                                x={x}
                                y={0}
                                width={w}
                                height={height}
                            />
                        ) : null;
                    x += w;

                    return bar;
                })}
            </svg>
            <figcaption className="text-center tracking-widest">
                {value}
            </figcaption>
        </figure>
    );
}
