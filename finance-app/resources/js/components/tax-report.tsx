import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { Amount } from '@/components/amount';

export type TaxReportData = {
    from: string;
    to: string;
    rows: {
        kind: 'output' | 'input' | null;
        code: string | null;
        base_minor: number | null;
        tax_minor: number;
    }[];
    output_minor: number;
    input_minor: number;
    adjustments_minor: number;
    net_minor: number;
};

const KINDS = {
    output: 'Output tax (sales)',
    input: 'Input tax (purchases)',
    adjustments: 'Adjustments',
} as const;

/**
 * A tax return's figures: tax per code under output, input and
 * adjustments, then what is owed.
 */
export function TaxReport({ report }: { report: TaxReportData }) {
    const sections = [
        {
            key: 'output',
            rows: report.rows.filter((row) => row.kind === 'output'),
            total: report.output_minor,
        },
        {
            key: 'input',
            rows: report.rows.filter((row) => row.kind === 'input'),
            total: report.input_minor,
        },
        {
            key: 'adjustments',
            rows: report.rows.filter((row) => row.kind === null),
            total: report.adjustments_minor,
        },
    ] as const;

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>Tax code</TableHead>
                    <TableHead className="text-right">Net amount</TableHead>
                    <TableHead className="text-right">Tax</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {report.rows.length === 0 && (
                    <TableEmpty colSpan={3}>
                        Nothing on the tax account in this period.
                    </TableEmpty>
                )}
                {sections.map(
                    (section) =>
                        section.rows.length > 0 && [
                            <TableRow key={section.key}>
                                <TableCell
                                    colSpan={3}
                                    className="pt-4 font-semibold"
                                >
                                    {KINDS[section.key]}
                                </TableCell>
                            </TableRow>,
                            ...section.rows.map((row, index) => (
                                <TableRow key={`${section.key}-${index}`}>
                                    <TableCell className="pl-6">
                                        {row.code ??
                                            (section.key === 'adjustments'
                                                ? 'Manual entries and tax without a code'
                                                : 'Without a code')}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {row.base_minor !== null && (
                                            <Amount minor={row.base_minor} />
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount minor={row.tax_minor} />
                                    </TableCell>
                                </TableRow>
                            )),
                            <TableRow key={`${section.key}-total`}>
                                <TableCell className="pl-6 text-muted-foreground">
                                    Total {KINDS[section.key].toLowerCase()}
                                </TableCell>
                                <TableCell />
                                <TableCell className="text-right font-medium">
                                    <Amount minor={section.total} />
                                </TableCell>
                            </TableRow>,
                        ],
                )}
                <TableRow className="border-t-2">
                    <TableCell className="font-semibold">
                        {report.net_minor >= 0
                            ? 'Owed to the tax authority'
                            : 'Owed back by the tax authority'}
                    </TableCell>
                    <TableCell />
                    <TableCell className="text-right font-semibold">
                        <Amount minor={Math.abs(report.net_minor)} />
                    </TableCell>
                </TableRow>
            </TableBody>
        </Table>
    );
}
