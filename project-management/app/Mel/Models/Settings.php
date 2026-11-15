<?php

namespace App\Mel\Models;

use App\Mel\Models\Concerns\TenantModel;
use App\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * M&E's settings for one organization (one row per organization), made the
 * first time M&E is opened, with the organization's name, currency and
 * time zone.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $display_name
 * @property string $currency
 * @property string $timezone
 * @property bool $verifier_must_differ
 * @property bool $publisher_must_differ
 * @property int $on_track_percent
 * @property int $attention_percent
 * @property bool $collect_reviewer_must_differ
 * @property int $collect_photo_max_kb
 * @property int $collect_keep_on_device_days
 * @property int|null $collect_retention_months
 */
class Settings extends Model
{
    use TenantModel;

    protected $table = 'mel_settings';

    protected $fillable = [
        'display_name', 'currency', 'timezone',
        'verifier_must_differ', 'publisher_must_differ', 'on_track_percent', 'attention_percent',
        'collect_reviewer_must_differ', 'collect_photo_max_kb', 'collect_keep_on_device_days', 'collect_retention_months',
    ];

    protected $attributes = [
        'verifier_must_differ' => true,
        'publisher_must_differ' => false,
        'on_track_percent' => 90,
        'attention_percent' => 70,
        'collect_reviewer_must_differ' => true,
        'collect_photo_max_kb' => 800,
        'collect_keep_on_device_days' => 30,
    ];

    protected function casts(): array
    {
        return [
            'verifier_must_differ' => 'boolean',
            'publisher_must_differ' => 'boolean',
            'on_track_percent' => 'integer',
            'attention_percent' => 'integer',
            'collect_reviewer_must_differ' => 'boolean',
            'collect_photo_max_kb' => 'integer',
            'collect_keep_on_device_days' => 'integer',
            'collect_retention_months' => 'integer',
        ];
    }

    /**
     * The current organization's row, made with the defaults on first use.
     */
    public static function current(): self
    {
        $organization = app(CurrentOrganization::class)->require();

        return static::query()->firstOrCreate([], [
            'display_name' => $organization->name,
            'currency' => $organization->currency ?: 'USD',
            'timezone' => $organization->timezone ?: 'UTC',
        ]);
    }

    /**
     * The current organization's settings.
     */
    public static function settings(): self
    {
        return static::current();
    }
}
