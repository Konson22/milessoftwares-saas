<?php

namespace App\Http\Controllers\Mel;

use App\Mel\Import;
use App\Mel\Models\ImportBatch;
use App\Mel\Models\ReportingPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Spreadsheet import (mel.data.import): the template, checking a file and
 * applying it.
 */
class ImportController extends Controller
{
    public function __construct(private readonly Import $import) {}

    public function show(Request $request, string $programme): Response
    {
        $programme = $this->programme($programme);
        $access = $this->access();
        $periods = $programme->periods()->where('status', ReportingPeriod::OPEN)->get()
            ->filter(fn (ReportingPeriod $period) => $access->reachesPeriod($programme, $period->starts_on, $period->ends_on))->values();

        return Inertia::render('mel/data/import', [
            'programme' => ['id' => $programme->id, 'code' => $programme->code, 'name' => $programme->name],
            'periods' => $periods->map(fn (ReportingPeriod $period) => ['value' => $period->id, 'label' => "{$period->label} ({$period->frequency})"]),
            'selectedPeriod' => $request->string('period')->toString() ?: $periods->first()?->id,
            'batches' => ImportBatch::query()->where('programme_id', $programme->id)->where('created_by', $this->user()->id)->with(['period', 'creator'])->latest()->limit(10)->get()
                ->map(fn (ImportBatch $batch) => [
                    'id' => $batch->id,
                    'fileName' => $batch->file_name,
                    'period' => $batch->period->label,
                    'status' => $batch->status,
                    'rowsTotal' => $batch->rows_total,
                    'rowsReady' => $batch->rows_ready,
                    'rowsSkipped' => $batch->rows_skipped,
                    'errors' => $batch->errors ?? [],
                    'by' => $batch->creator->name,
                    'createdAt' => $batch->created_at->toIso8601String(),
                    'mine' => $batch->created_by === $this->user()->id,
                ]),
        ]);
    }

    public function template(string $programme, string $period): StreamedResponse
    {
        $programme = $this->programme($programme);
        $period = $programme->periods()->findOrFail($period);

        return $this->import->template($programme, $period);
    }

    public function store(Request $request, string $programme): RedirectResponse
    {
        $programme = $this->programme($programme);
        $data = $request->validate([
            'period_id' => ['required', 'uuid'],
            'file' => ['required', 'file', 'max:5120'],
        ]);
        $period = $programme->periods()->where('status', ReportingPeriod::OPEN)->whereKey($data['period_id'])->firstOrFail();
        $file = $request->file('file');
        assert($file instanceof UploadedFile);

        $batch = $this->import->validate($programme, $period, $file, $this->user());

        return back()->with('success', $batch->status === ImportBatch::VALIDATED
            ? "{$batch->rows_ready} rows ready to import".($batch->rows_skipped > 0 ? ", {$batch->rows_skipped} empty or already imported" : '').'. Check and apply below.'
            : count($batch->errors ?? []).' rows need fixing. Nothing was imported.');
    }

    public function apply(Request $request, string $programme, string $batch): RedirectResponse
    {
        $programme = $this->programme($programme);
        $batch = ImportBatch::query()->where('programme_id', $programme->id)->where('created_by', $this->user()->id)->findOrFail($batch);
        $submit = $request->validate(['submit' => ['boolean']])['submit'] ?? false;

        $count = $this->import->apply($batch, (bool) $submit, $this->user());

        return to_route('mel.data.index', ['programme' => $programme->id, 'period' => $batch->reporting_period_id])
            ->with('success', "{$count} results imported".($submit ? ' and submitted for review.' : ' as drafts.'));
    }
}
