<?php

namespace App\Collaboration\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A report's content as it stood: edited while a draft, frozen once
 * submitted (also by a trigger on PostgreSQL). A correction is a new version.
 *
 * @property string $id
 * @property string $report_id
 * @property int $number
 * @property array<string, mixed> $content App\Collaboration\ReportContent
 * @property string $created_by
 * @property string|null $submitted_by
 * @property CarbonImmutable|null $submitted_at
 * @property-read Report $report
 */
class ReportVersion extends Model
{
    use HasUuids;

    protected $table = 'project_report_versions';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['number' => 'integer', 'content' => 'array', 'submitted_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Report, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function isFrozen(): bool
    {
        return $this->submitted_at !== null;
    }
}
