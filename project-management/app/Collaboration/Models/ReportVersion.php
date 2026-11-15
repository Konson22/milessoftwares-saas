<?php

namespace App\Collaboration\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

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

    /**
     * A submitted version is frozen: refused here on every database, in
     * addition to the PostgreSQL trigger. Submitting itself (setting
     * submitted_at on an open version) is the last change it takes.
     */
    protected static function booted(): void
    {
        static::updating(function (self $version) {
            if ($version->getOriginal('submitted_at') !== null) {
                throw new LogicException('A submitted report version cannot be changed; make a new version.');
            }
        });
        static::deleting(fn () => throw new LogicException('Report versions are kept: a version cannot be deleted.'));
    }

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
