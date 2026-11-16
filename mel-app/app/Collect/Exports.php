<?php

namespace App\Collect;

use App\Collect\Models\Export;
use App\Collect\Models\Form;
use App\Collect\Models\FormVersion;
use App\Collect\Models\Submission;
use App\Mel\AuditTrail;
use App\Models\User;
use App\Support\Spreadsheet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Exports of collected data (ADR-0012 §12): one row per submission, one
 * column per question name across the versions used (a question whose
 * type changed gets its own column per version, "members__v3"),
 * select-multiple as a joined column plus one 0/1 column per choice, and
 * metadata columns.
 *
 * - Personal questions (and photos and places) are left out for anyone
 *   without mel.collect.personal_data, and a note row says so. Photos
 *   themselves are never exported.
 * - Cells that a spreadsheet would read as a formula are prefixed with '.
 * - Files sit on the private disk, are re-authorized at every download
 *   (the requester, the form still in reach, personal data still allowed)
 *   and expire after 7 days. Creating and downloading are audited.
 * - Built in the request: an MVP limit of 50,000 rows keeps it bounded.
 */
class Exports
{
    public const MAX_ROWS = 50_000;

    public const KEEP_DAYS = 7;

    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * @param  array{statuses?: list<string>, from?: string|null, to?: string|null}  $filters
     */
    public function run(Form $form, Scope $scope, array $filters, string $format, string $answers, User $user): Export
    {
        $personal = $scope->seesPersonalData();
        $statuses = array_values(array_intersect($filters['statuses'] ?? [], Submission::STATUSES)) ?: array_values(array_diff(Submission::STATUSES, [Submission::RECEIVING]));
        $query = $scope->submissions()->where('form_id', $form->id)->whereIn('status', $statuses)
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('completed_at', '>=', CarbonImmutable::parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('completed_at', '<=', CarbonImmutable::parse($to)->endOfDay()));

        $count = (clone $query)->count();
        if ($count > self::MAX_ROWS) {
            throw ValidationException::withMessages(['filters' => 'More than '.number_format(self::MAX_ROWS).' submissions: narrow the dates.']);
        }

        $export = DB::transaction(function () use ($form, $filters, $statuses, $format, $answers, $personal, $user) {
            $export = Export::query()->create([
                'form_id' => $form->id,
                'requested_by' => $user->id,
                'filters' => ['statuses' => $statuses, 'from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null],
                'format' => $format,
                'answers' => $answers,
                'includes_personal_data' => $personal && $form->contains_personal_data,
                'status' => Export::QUEUED,
            ]);
            $this->audit->record('export.requested', $export->setRelation('form', $form), $user, ['format' => $format, 'personal' => $personal ? 'included' : 'left out']);

            return $export;
        });

        try {
            [$columns, $rows, $notes] = $this->table($form, $query->with(['version', 'currentRevision', 'author', 'unit'])->orderBy('completed_at')->get()->values()->all(), $answers, $personal);
            $path = 'collect/'.$form->organization_id.'/exports/'.$export->id.'.'.$format;
            $absolute = Storage::disk('local')->path($path);
            if (! is_dir(dirname($absolute))) {
                mkdir(dirname($absolute), 0770, true);
            }

            if ($format === 'xlsx') {
                Spreadsheet::write($absolute, [['title' => mb_substr($form->name, 0, 31), 'columns' => $columns, 'rows' => $rows, 'notes' => $notes]]);
            } else {
                $handle = fopen($absolute, 'wb');
                if ($handle === false) {
                    throw new \RuntimeException('Cannot write the export.');
                }
                fwrite($handle, "\u{FEFF}");
                foreach ($notes as $note) {
                    fputcsv($handle, [self::safe($note)], escape: '');
                }
                fputcsv($handle, array_map(self::safe(...), $columns), escape: '');
                foreach ($rows as $row) {
                    fputcsv($handle, array_map(self::safe(...), $row), escape: '');
                }
                fclose($handle);
            }

            $export->update(['status' => Export::READY, 'rows' => count($rows), 'path' => $path, 'completed_at' => now(), 'expires_at' => now()->addDays(self::KEEP_DAYS)]);
        } catch (Throwable $e) {
            report($e);
            $export->update(['status' => Export::FAILED, 'error' => 'The export could not be built.', 'completed_at' => now()]);
        }

        return $export;
    }

    /**
     * Re-checks an export at download and records it.
     */
    public function download(Export $export, Scope $scope, User $user): string
    {
        abort_unless($export->requested_by === $user->id, 404);
        abort_unless($scope->forms()->whereKey($export->form_id)->exists(), 404);
        abort_unless($export->isDownloadable(), 410, 'This export has expired. Make a new one.');
        abort_if($export->includes_personal_data && ! $scope->seesPersonalData(), 403, 'This export holds personal data you may no longer see.');

        DB::transaction(function () use ($export, $user) {
            $export->increment('downloads');
            $this->audit->record('export.downloaded', $export->loadMissing('form'), $user);
        });

        return (string) $export->path;
    }

    /**
     * @param  array<int, Submission>  $submissions
     * @return array{0: list<string>, 1: list<list<string>>, 2: list<string>}
     */
    private function table(Form $form, array $submissions, string $answers, bool $personal): array
    {
        $versions = FormVersion::query()->where('form_id', $form->id)->orderByDesc('number')->get();
        $columns = [];  // column → [name, choice]
        $keys = [];     // name → type → column; the newest version's type keeps the plain name
        $leftOut = false;

        foreach ($versions as $version) {
            foreach (Definition::questions($version->definition) as $name => $question) {
                if (Definition::isPersonal($question) && (! $personal || ($question['type'] ?? null) === 'photo')) {
                    $leftOut = $leftOut || ! $personal;

                    continue;
                }
                $type = (string) $question['type'];
                $key = $keys[$name][$type] ??= isset($keys[$name]) ? "{$name}__v{$version->number}" : $name;
                $columns[$key] ??= ['name' => $name, 'choice' => null];
                if ($type === 'select_multiple') {
                    foreach (array_keys(Definition::choices($version->definition, $question)) as $choice) {
                        $columns["{$key}/{$choice}"] ??= ['name' => $name, 'choice' => $choice, 'of' => $key];
                    }
                }
            }
        }

        $meta = ['submission_id', 'form_version', 'status', 'collected_by', 'unit', 'completed_at', 'received_at', 'consent_given', 'revision'];
        $rows = [];
        foreach ($submissions as $submission) {
            $values = $answers === 'original' ? $submission->answers : $submission->currentAnswers();
            $row = [
                $submission->id,
                (string) $submission->version->number,
                $submission->status,
                $submission->author->name,
                (string) $submission->unit?->code,
                $submission->completed_at->toIso8601String(),
                $submission->received_at->toIso8601String(),
                $submission->consent_given === null ? '' : ($submission->consent_given ? 'yes' : 'no'),
                (string) ($answers === 'original' ? 0 : ($submission->currentRevision->number ?? 0)),
            ];
            // Each answer goes to the column of its own version's type.
            $mine = [];
            foreach (Definition::questions($submission->version->definition) as $name => $question) {
                $key = $keys[$name][(string) $question['type']] ?? null;
                if ($key !== null) {
                    $mine[$key] = ['type' => (string) $question['type'], 'value' => $values[$name] ?? null];
                }
            }
            foreach ($columns as $key => $column) {
                if ($column['choice'] !== null) {
                    $value = $mine[$column['of']]['value'] ?? null;
                    $row[] = is_array($value) ? (in_array($column['choice'], $value, true) ? '1' : '0') : '';

                    continue;
                }
                $value = $mine[$key]['value'] ?? null;
                $type = $mine[$key]['type'] ?? null;
                $row[] = match (true) {
                    $value === null => '',
                    $type === 'select_multiple' && is_array($value) => implode(' ', $value),
                    $type === 'geopoint' && is_array($value) => trim(($value['lat'] ?? '').' '.($value['lng'] ?? '').' '.($value['accuracy'] ?? '')),
                    is_scalar($value) => (string) $value,
                    default => (string) json_encode($value),
                };
            }
            $rows[] = $row;
        }

        $notes = [];
        if ($leftOut) {
            $notes[] = 'Personal questions (and places) are left out: you do not have access to personal data.';
        }
        $notes[] = 'Photos are not exported. Answers: '.($answers === 'original' ? 'as collected' : 'with corrections applied').'.';

        return [[...$meta, ...array_keys($columns)], $rows, $notes];
    }

    /**
     * A cell a spreadsheet will not run as a formula.
     */
    public static function safe(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
