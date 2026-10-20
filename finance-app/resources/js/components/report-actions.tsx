import { Printer } from 'lucide-react';
import { Button } from '@miles/ui/components/ui/button';
import type { RouteDefinition, RouteQueryOptions } from '@/wayfinder';

type ReportRoute = (options?: RouteQueryOptions) => RouteDefinition<'get'>;

/**
 * Print and CSV downloads for a report (?export=<table>), keeping its
 * filters. Hidden when printing.
 */
export function ReportActions({
    route,
    query,
    exports,
}: {
    route: ReportRoute;
    query: Record<string, string | null | undefined>;
    exports: { key: string; title: string }[];
}) {
    const kept = Object.fromEntries(
        Object.entries(query).filter(
            (entry): entry is [string, string] =>
                entry[1] !== null && entry[1] !== undefined && entry[1] !== '',
        ),
    );

    return (
        <span className="ml-auto flex flex-wrap items-center gap-1 text-sm print:hidden">
            <span className="text-muted-foreground">CSV:</span>
            {exports.map((table) => (
                <Button key={table.key} variant="link" size="sm" asChild>
                    <a
                        href={
                            route({ query: { ...kept, export: table.key } }).url
                        }
                        download
                    >
                        {table.title}
                    </a>
                </Button>
            ))}
            <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={() => window.print()}
            >
                <Printer className="size-4" aria-hidden />
                Print
            </Button>
        </span>
    );
}
