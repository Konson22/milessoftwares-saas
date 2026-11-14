<?php

namespace App\Mel;

use App\Models\ImportBatch;
use App\Models\Indicator;
use App\Models\Programme;
use App\Models\ProgrammeUnit;
use App\Models\ReportingPeriod;
use App\Models\Submission;
use App\Models\User;
use App\Support\Spreadsheet;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Results from a spreadsheet, for one programme and period, in two steps:
 *
 * 1. validate: every row is checked as the entry form would and the batch
 *    is kept with its errors, changing nothing;
 * 2. apply: the rows are checked again and saved as drafts (optionally
 *    submitted) in one transaction, all or none.
 *
 * Each row's source reference is the file's SHA-256 and the row number, so
 * importing the same file again changes nothing. Totals only: disaggregated
 * values are entered in the form. Kobo and ODK adapters (Phase 2) will feed
 * the same rows through validateRows().
 */
class Import
{
    public const COLUMNS = ['Indicator code', 'Indicator', 'Unit code', 'Unit', 'Value', 'Numerator', 'Denominator', 'Narrative'];

    public function __construct(
        private readonly Submissions $submissions,
        private readonly AuditTrail $audit,
        private readonly Access $access,
    ) {}

    /**
     * The sheet to fill in: one row per active indicator and unit.
     */
    public function template(Programme $programme, ReportingPeriod $period): StreamedResponse
    {
        $rows = [];
        foreach ($this->indicators($programme, $period) as $indicator) {
            foreach ($this->units($programme) as $unit) {
                $rows[] = [$indicator->code, $indicator->currentVersion->name ?? '', $unit->code, $unit->name, '', '', '', ''];
            }
        }

        return Spreadsheet::download(Str::slug("{$programme->code} {$period->label} results").'.xlsx', [[
            'title' => $period->label,
            'notes' => [
                "{$programme->label()} · {$period->label} ({$period->starts_on->toDateString()} to {$period->ends_on->toDateString()})",
                'Fill Value for counts, numbers, amounts and yes/no (1 or 0); Numerator and Denominator for percentages, rates and ratios. Leave a row empty to skip it.',
            ],
            'columns' => self::COLUMNS,
            'rows' => $rows,
        ]]);
    }

    public function validate(Programme $programme, ReportingPeriod $period, UploadedFile $file, User $user): ImportBatch
    {
        $extension = Str::lower($file->getClientOriginalExtension());
        if (! in_array($extension, ['xlsx', 'csv'], true)) {
            throw ValidationException::withMessages(['file' => 'Upload the .xlsx template (or a .csv with the same columns).']);
        }

        $sha256 = (string) hash_file('sha256', $file->getRealPath());
        $path = $file->storeAs('imports/'.Str::uuid7()->toString(), 'results.'.$extension, 'local');
        if ($path === false) {
            throw ValidationException::withMessages(['file' => 'The file could not be stored. Try again.']);
        }

        [$ready, $skipped, $errors] = $this->check($programme, $period, $this->rows(Storage::disk('local')->path($path), $extension), $sha256);

        return DB::transaction(function () use ($programme, $period, $file, $sha256, $path, $ready, $skipped, $errors, $user) {
            $batch = ImportBatch::query()->create([
                'programme_id' => $programme->id,
                'reporting_period_id' => $period->id,
                'file_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'sha256' => $sha256,
                'path' => $path,
                'status' => $errors === [] ? ImportBatch::VALIDATED : ImportBatch::FAILED,
                'rows_total' => count($ready) + $skipped + count($errors),
                'rows_ready' => count($ready),
                'rows_skipped' => $skipped,
                'errors' => $errors === [] ? null : $errors,
                'created_by' => $user->id,
            ]);
            $this->audit->record('import.validated', $batch, $user, ['rows' => count($ready), 'errors' => count($errors)]);

            return $batch;
        });
    }

    /**
     * @return int the rows saved
     */
    public function apply(ImportBatch $batch, bool $submit, User $user): int
    {
        $period = $batch->period;
        if ($batch->status !== ImportBatch::VALIDATED || $batch->path === null) {
            throw ValidationException::withMessages(['batch' => 'Only a validated file that has not been applied can be applied.']);
        }

        $programme = $batch->programme;
        $extension = pathinfo($batch->path, PATHINFO_EXTENSION);
        [$ready, , $errors] = $this->check($programme, $period, $this->rows(Storage::disk('local')->path($batch->path), $extension), $batch->sha256);

        if ($errors !== []) {
            $batch->update(['status' => ImportBatch::FAILED, 'errors' => $errors]);

            throw ValidationException::withMessages(['batch' => 'Results changed since the file was checked: '.count($errors).' rows no longer fit. Check the file again.']);
        }

        DB::transaction(function () use ($batch, $period, $ready, $submit, $user) {
            ImportBatch::query()->whereKey($batch->id)->where('status', ImportBatch::VALIDATED)->lockForUpdate()->firstOrFail();

            foreach ($ready as $row) {
                $submission = $this->submissions->save($row['indicator'], $period, $row['unit'], $row['figure'] + ['narrative' => $row['narrative']], $user, [
                    'source' => 'import',
                    'source_reference' => $row['reference'],
                    'batch' => $batch,
                ]);

                if ($submit) {
                    $this->submissions->submit($submission->fresh(['programme', 'period', 'version.breakdowns']) ?? $submission, $user);
                }
            }

            $batch->update(['status' => ImportBatch::APPLIED, 'applied_at' => now()]);
            $this->audit->record('import.applied', $batch, $user, ['rows' => count($ready), 'submitted' => $submit]);
        });

        Storage::disk('local')->delete($batch->path);
        $batch->update(['path' => null]);

        return count($ready);
    }

    /**
     * Checks parsed rows. Rows already imported from this same file are
     * skipped, not errors.
     *
     * @param  list<array{row: int, indicator: string, unit: string, value: string, numerator: string, denominator: string, narrative: string}>  $rows
     * @return array{0: list<array{indicator: Indicator, unit: ProgrammeUnit, figure: array{value: string|null, numerator: string|null, denominator: string|null}, narrative: string|null, reference: string}>, 1: int, 2: list<array{row: int, message: string}>}
     */
    public function check(Programme $programme, ReportingPeriod $period, array $rows, string $fileHash): array
    {
        $indicators = $this->indicators($programme, $period)->keyBy(fn (Indicator $indicator) => strtoupper($indicator->code));
        $units = $this->units($programme)->keyBy(fn (ProgrammeUnit $unit) => strtoupper($unit->code));
        $ready = [];
        $errors = [];
        $skipped = 0;
        $seen = [];

        foreach ($rows as $row) {
            $error = function (string $message) use (&$errors, $row): void {
                $errors[] = ['row' => $row['row'], 'message' => $message];
            };
            $reference = 'file:'.$fileHash.':'.$row['row'];

            if ($row['value'] === '' && $row['numerator'] === '' && $row['denominator'] === '') {
                $skipped++;

                continue;
            }

            if (Submission::query()->where('source', 'import')->where('source_reference', $reference)->exists()) {
                $skipped++;

                continue;
            }

            $indicator = $indicators->get(strtoupper($row['indicator']));
            $unit = $units->get(strtoupper($row['unit']));
            if ($indicator === null) {
                $error("No active {$period->frequency} indicator \"{$row['indicator']}\" in this programme.");

                continue;
            }
            if ($unit === null) {
                $error("No unit \"{$row['unit']}\" you can report for.");

                continue;
            }

            $key = $indicator->id.'|'.$unit->id;
            if (isset($seen[$key])) {
                $error("{$indicator->code} for {$unit->code} appears twice (rows {$seen[$key]} and {$row['row']}).");

                continue;
            }
            $seen[$key] = $row['row'];

            $open = $this->submissions->open($indicator, $period, $unit);
            if ($open !== null && ! $open->isEditable()) {
                $error("{$indicator->code} for {$unit->code} is already submitted and waiting for review.");

                continue;
            }

            try {
                $version = $indicator->currentVersion;
                assert($version !== null);
                $figure = Figures::from($version, $row);
            } catch (ValidationException $exception) {
                $error("{$indicator->code} for {$unit->code}: ".collect($exception->errors())->flatten()->implode(' '));

                continue;
            }

            $ready[] = [
                'indicator' => $indicator,
                'unit' => $unit,
                'figure' => $figure,
                'narrative' => $row['narrative'] === '' ? null : mb_substr($row['narrative'], 0, 10000),
                'reference' => $reference,
            ];
        }

        return [$ready, $skipped, $errors];
    }

    /**
     * The sheet's rows, by heading (the template's notes above the heading
     * row are skipped).
     *
     * @return list<array{row: int, indicator: string, unit: string, value: string, numerator: string, denominator: string, narrative: string}>
     */
    private function rows(string $path, string $extension): array
    {
        $sheet = Spreadsheet::read($path, $extension);
        $headerAt = null;
        $columns = [];

        foreach ($sheet as $i => $cells) {
            $lower = array_map(fn (string $cell) => Str::lower($cell), $cells);
            if (in_array('indicator code', $lower, true) && in_array('unit code', $lower, true)) {
                $headerAt = $i;
                $columns = array_flip($lower);
                break;
            }
        }

        if ($headerAt === null) {
            throw ValidationException::withMessages(['file' => 'The sheet needs the template\'s headings: '.implode(', ', self::COLUMNS).'.']);
        }

        $cell = fn (array $cells, string $heading) => isset($columns[$heading]) ? ($cells[$columns[$heading]] ?? '') : '';
        $rows = [];

        foreach (array_slice($sheet, $headerAt + 1, null, true) as $i => $cells) {
            if (implode('', $cells) === '') {
                continue;
            }

            $rows[] = [
                'row' => $i + 1,
                'indicator' => $cell($cells, 'indicator code'),
                'unit' => $cell($cells, 'unit code'),
                'value' => $cell($cells, 'value'),
                'numerator' => $cell($cells, 'numerator'),
                'denominator' => $cell($cells, 'denominator'),
                'narrative' => $cell($cells, 'narrative'),
            ];
        }

        return $rows;
    }

    /**
     * @return Collection<int, Indicator>
     */
    private function indicators(Programme $programme, ReportingPeriod $period): Collection
    {
        return $programme->indicators()->with('currentVersion.breakdowns')
            ->where('status', Indicator::ACTIVE)->where('frequency', $period->frequency)->orderBy('code')->get();
    }

    /**
     * @return Collection<int, ProgrammeUnit>
     */
    private function units(Programme $programme): Collection
    {
        $ids = $this->access->unitIds($programme);

        return $programme->units()->whereNull('archived_at')
            ->when($ids !== null, fn ($query) => $query->whereIn('id', $ids ?? []))->get();
    }
}
