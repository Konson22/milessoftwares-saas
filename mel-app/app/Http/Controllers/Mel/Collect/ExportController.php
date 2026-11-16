<?php

namespace App\Http\Controllers\Mel\Collect;

use App\Collect\Exports;
use App\Collect\Models\Export;
use App\Collect\Models\Form;
use App\Collect\Models\Submission;
use App\Http\Controllers\Mel\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports of collected data: the member's own, of forms they still reach,
 * each re-authorized at download (Exports::download).
 */
class ExportController extends Controller
{
    public function __construct(private readonly Exports $exports) {}

    public function index(): Response
    {
        $exports = $this->scope()->exports()->with('form')->latest()->limit(100)->get();

        return Inertia::render('mel/collect/exports/index', [
            'exports' => $exports->map(fn (Export $export) => [
                'id' => $export->id,
                'form' => $export->form->name,
                'format' => $export->format,
                'answers' => $export->getAttribute('answers'),
                'status' => $export->isDownloadable() ? $export->status : ($export->status === Export::READY ? Export::EXPIRED : $export->status),
                'rows' => $export->rows,
                'personal' => $export->includes_personal_data,
                'filters' => $export->filters,
                'createdAt' => $export->created_at->toIso8601String(),
                'expiresAt' => $export->expires_at?->toIso8601String(),
                'downloads' => $export->downloads,
            ])->values(),
            'forms' => $this->scope()->forms()->whereNotNull('current_version_id')->orderBy('name')->get(['id', 'name'])
                ->map(fn (Form $form) => ['value' => $form->id, 'label' => $form->name])->values(),
            'statuses' => array_values(array_diff(Submission::STATUSES, [Submission::RECEIVING])),
            'personal' => $this->scope()->seesPersonalData(),
        ]);
    }

    public function store(Request $request, string $form): RedirectResponse
    {
        $model = $this->scope()->form($form);
        $data = $request->validate([
            'format' => ['required', Rule::in(Export::FORMATS)],
            'answers' => ['nullable', Rule::in(['current', 'original'])],
            'statuses' => ['nullable', 'array'],
            'statuses.*' => [Rule::in(Submission::STATUSES)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $export = $this->exports->run($model, $this->scope(), [
            'statuses' => array_values($data['statuses'] ?? []),
            'from' => $data['from'] ?? null,
            'to' => $data['to'] ?? null,
        ], $data['format'], $data['answers'] ?? 'current', $this->user());

        return to_route('mel.collect.exports.index')->with(
            $export->status === Export::READY ? 'success' : 'error',
            $export->status === Export::READY ? "Export ready: {$export->rows} rows. It can be downloaded for 7 days." : 'The export could not be built.',
        );
    }

    public function download(string $export): StreamedResponse
    {
        $model = Export::query()->with('form')->findOrFail($export);
        $path = $this->exports->download($model, $this->scope(), $this->user());

        return Storage::disk('local')->download($path, Str::slug($model->form->name).'-'.$model->created_at->format('Ymd-His').'.'.$model->format, [
            'Content-Type' => $model->format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
