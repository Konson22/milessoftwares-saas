<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $code
 * @property string $name
 * @property string $kind
 * @property bool $active
 */
class PaymentMethod extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const CASH = 'cash';

    public const MOBILE = 'mobile_money';

    public const BANK = 'bank_transfer';

    public const CREDIT = 'credit';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function isCredit(): bool
    {
        return $this->kind === self::CREDIT;
    }

    /**
     * Finance's payment method, for tenders it knows. Credit is not one.
     */
    public function financeMethod(): ?string
    {
        return match ($this->kind) {
            self::CASH => 'cash',
            self::MOBILE => 'mobile_money',
            self::BANK => 'bank_transfer',
            default => null,
        };
    }
}
