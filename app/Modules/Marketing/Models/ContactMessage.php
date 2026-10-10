<?php

namespace App\Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A message from the public contact form. Written by SubmitLead only.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string|null $company
 * @property string|null $phone
 * @property string|null $country_code
 * @property string|null $subject
 * @property string|null $message
 * @property string $status new|in_progress|closed|spam
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 */
class ContactMessage extends Model
{
    use HasUuids;
}
