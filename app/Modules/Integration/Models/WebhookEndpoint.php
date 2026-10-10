<?php

namespace App\Modules\Integration\Models;

use App\Modules\Applications\Models\Application;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a product receives platform webhooks. The signing secret is stored
 * encrypted and never returned after it is first shown.
 *
 * @property string $id
 * @property string $application_id
 * @property string $url
 * @property string $secret decrypted signing secret
 * @property string $status active|disabled
 * @property-read Application $application
 */
class WebhookEndpoint extends Model
{
    use HasUuids;

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['secret' => 'encrypted'];
    }

    /**
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
