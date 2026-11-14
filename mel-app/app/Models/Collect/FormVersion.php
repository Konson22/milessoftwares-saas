<?php

namespace App\Models\Collect;

use App\Models\Concerns\TenantModel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A published version of a form: the definition submissions were filled
 * in on. Never changed and never deleted (PostgreSQL refuses both).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $form_id
 * @property int $number
 * @property array<string, mixed> $definition
 * @property string $sha256
 * @property string|null $note
 * @property string $published_by
 * @property CarbonImmutable $published_at
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $accept_until
 * @property-read Form $form
 * @property-read User $publisher
 */
class FormVersion extends Model
{
    use TenantModel;

    public $timestamps = false;

    protected $table = 'collect_form_versions';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'number' => 'integer',
            'published_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'accept_until' => 'immutable_datetime',
        ];
    }

    /**
     * The same definition always hashes the same, whatever its key order
     * in storage: a draft equal to a version is "no changes".
     *
     * @param  array<string, mixed>  $definition
     */
    public static function hash(array $definition): string
    {
        return hash('sha256', (string) json_encode(self::canonical($definition), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * Whether a submission filled in on this version is still accepted.
     */
    public function acceptsSubmissions(?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        return $this->closed_at === null || ($this->accept_until !== null && $this->accept_until->greaterThan($now));
    }

    /**
     * @return BelongsTo<Form, $this>
     */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }
}
