<?php

namespace App\Http\Controllers;

use App\Logbook\Access;
use App\Logbook\Entries;
use App\Logbook\Fields;
use App\Logbook\Models\Entry;
use App\Logbook\Reports;
use App\Models\Workspace;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Counts, numeric summaries and exports over the filtered entries the
 * member may read (never drafts). Exports carry the same filters and the
 * same limits as the page.
 */
class ReportController extends Controller
{
    public const PDF_LIMIT = 500;

    public function index(Request $request): Response
    {
        $filters = $request->validate(Reports::rules());
        $sheet = isset($filters['sheet']) ? Access::sheets()->whereKey((string) $filters['sheet'])->first() : null;

        return Inertia::render('reports/index', [
            'filters' => $filters,
            'options' => self::filterOptions(),
            'counts' => Reports::counts($filters),
            'numbers' => $sheet !== null ? Reports::numbers($sheet, $filters) : null,
            'sheet' => $sheet === null ? null : ['id' => $sheet->id, 'name' => $sheet->name, 'code' => $sheet->code],
            'entries' => Reports::query($filters)->with(['logbook:id,name', 'sheet:id,name,code', 'creator:id,name', 'version:id,fields'])
                ->latest('occurred_at')->paginate(25)->withQueryString()->through(fn (Entry $e) => self::entryRow($e)),
            'pdfLimit' => self::PDF_LIMIT,
        ]);
    }

    /**
     * The filtered entries as CSV. With one sheet chosen, one column per
     * field the sheet has ever had; otherwise a summary column.
     */
    public function csv(Request $request): StreamedResponse
    {
        $filters = $request->validate(Reports::rules());
        $sheet = isset($filters['sheet']) ? Access::sheets()->whereKey((string) $filters['sheet'])->firstOrFail() : null;
        $columns = $sheet !== null ? Reports::columns($sheet) : [];
        $tz = Workspace::current()->timezone ?? 'UTC';
        $query = Reports::query($filters)->with(['logbook:id,name', 'sheet:id,name,code', 'creator:id,name', 'reviewer:id,name', 'version:id,version,fields', 'attachments:id,log_entry_id,name']);

        $name = 'logbook-entries-'.($sheet !== null ? strtolower($sheet->code).'-' : '').now($tz)->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($query, $columns, $sheet, $tz) {
            $out = fopen('php://output', 'w');
            assert($out !== false);
            fwrite($out, "\xEF\xBB\xBF");
            $head = ['Reference', 'Logbook', 'Log sheet', 'Template version', 'Occurred at', 'Status', 'Review', 'Created by', 'Submitted at', 'Reviewed by', 'Reviewed at'];
            fputcsv($out, array_map(self::safe(...), $sheet !== null ? [...$head, ...array_column($columns, 'label')] : [...$head, 'Summary']), escape: '');

            $query->reorder()->orderBy('occurred_at')->orderBy('id')->chunk(500, function ($entries) use ($out, $columns, $sheet, $tz) {
                foreach ($entries as $e) {
                    /** @var Entry $e */
                    $row = [
                        $e->reference, $e->logbook->name, $e->sheet->name, $e->version->version,
                        $e->occurred_at->setTimezone($tz)->format('Y-m-d H:i'), $e->status,
                        $e->review_required ? ($e->status === 'reviewed' ? 'Reviewed' : 'Required') : 'Not required',
                        $e->creator->name, $e->submitted_at?->setTimezone($tz)->format('Y-m-d H:i'),
                        $e->reviewer?->name, $e->reviewed_at?->setTimezone($tz)->format('Y-m-d H:i'),
                    ];
                    if ($sheet !== null) {
                        $own = Fields::byName($e->version->fields);
                        $people = Entries::people($e->version->fields, $e->values);
                        $files = $e->attachments->pluck('name', 'id')->all();
                        foreach ($columns as $column) {
                            $field = $own[$column['name']] ?? $column;
                            $row[] = Fields::text($field, $e->values[$column['name']] ?? null, $people, $files);
                        }
                    } else {
                        $row[] = self::summary($e);
                    }
                    fputcsv($out, array_map(self::safe(...), $row), escape: '');
                }
            });
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** The filtered entries as a printable PDF listing, up to PDF_LIMIT. */
    public function pdf(Request $request): HttpResponse
    {
        $filters = $request->validate(Reports::rules());
        $sheet = isset($filters['sheet']) ? Access::sheets()->whereKey((string) $filters['sheet'])->firstOrFail() : null;
        $query = Reports::query($filters);
        $total = (clone $query)->count();
        if ($total > self::PDF_LIMIT) {
            throw ValidationException::withMessages(['export' => "{$total} entries match. A PDF holds at most ".self::PDF_LIMIT.'; narrow the filters or export CSV.']);
        }
        $tz = Workspace::current()->timezone ?? 'UTC';
        $entries = $query->with(['logbook:id,name', 'sheet:id,name,code', 'creator:id,name', 'version:id,fields', 'attachments:id,log_entry_id,name'])
            ->reorder()->orderBy('occurred_at')->get();

        return Pdf::loadView('logbook.entries-pdf', [
            'organization' => Workspace::current()?->display_name,
            'sheet' => $sheet,
            'filters' => $filters,
            'timezone' => $tz,
            'printedBy' => $this->user()->name,
            'rows' => $entries->map(fn (Entry $e) => [
                'entry' => $e,
                'answers' => self::answers($e->version->fields, $e->values, $e->attachments),
            ]),
        ])->setPaper('a4', 'landscape')->download('logbook-entries-'.now($tz)->format('Y-m-d').'.pdf');
    }

    /** A cell a spreadsheet will not run as a formula (same rule as M&E exports). */
    public static function safe(mixed $value): string
    {
        $value = $value === null ? '' : (is_scalar($value) ? (string) $value : (string) json_encode($value));

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
