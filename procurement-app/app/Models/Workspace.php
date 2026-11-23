<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * Procurement's settings for one organization (one row per tenant): the
 * workspace currency, separation of duties, quotation and plan rules,
 * method limits and the Finance connection.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $display_name
 * @property string $currency
 * @property string $timezone
 * @property bool $allow_self_approval
 * @property bool $require_plan
 * @property int $min_quotations
 * @property array<string, int>|null $method_limits
 * @property int $invoice_tolerance_percent
 * @property string|null $delivery_address
 * @property string|null $order_terms
 * @property bool $finance_bills_enabled
 * @property string|null $finance_expense_account
 */
class Workspace extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'display_name', 'currency', 'timezone',
        'allow_self_approval', 'require_plan', 'min_quotations', 'method_limits', 'invoice_tolerance_percent',
        'delivery_address', 'order_terms', 'finance_bills_enabled', 'finance_expense_account',
    ];

    protected $attributes = [
        'allow_self_approval' => false,
        'require_plan' => false,
        'min_quotations' => 3,
        'invoice_tolerance_percent' => 0,
        'finance_bills_enabled' => false,
    ];

    protected function casts(): array
    {
        return [
            'allow_self_approval' => 'boolean',
            'require_plan' => 'boolean',
            'min_quotations' => 'integer',
            'method_limits' => 'array',
            'invoice_tolerance_percent' => 'integer',
            'finance_bills_enabled' => 'boolean',
        ];
    }

    /**
     * The current tenant's row (null until the platform has provisioned it).
     */
    public static function current(): ?self
    {
        return static::query()->first();
    }

    /**
     * The current tenant's settings, made with the defaults on first use.
     */
    public static function settings(): self
    {
        $organization = app(CurrentTenant::class)->require();

        return static::query()->firstOrCreate([], [
            'display_name' => $organization->displayName,
            'currency' => $organization->defaultCurrency ?? 'USD',
            'timezone' => $organization->timezone ?? 'UTC',
        ]);
    }

    /** The upper limit for a method, in workspace minor units; null for none. */
    public function methodLimit(string $method): ?int
    {
        $limit = $this->method_limits[$method] ?? null;

        return is_numeric($limit) ? (int) $limit : null;
    }
}
