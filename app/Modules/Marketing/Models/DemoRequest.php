<?php

namespace App\Modules\Marketing\Models;

use App\Modules\Applications\Models\Application;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A demo request from the public site. Written by SubmitLead only.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string|null $company
 * @property string|null $phone
 * @property string|null $country_code
 * @property string|null $application_id
 * @property string|null $preferred_contact_time
 * @property string|null $message
 * @property string $status new|in_progress|closed|spam
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 * @property-read Application|null $application
 */
class DemoRequest extends Model
{
    use HasUuids;

    /**
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
