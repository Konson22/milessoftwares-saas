<?php

namespace App\Support;

use Miles\PlatformClient\PlatformConfig;
use RuntimeException;

/**
 * The industry module being served (ADR-0003): the registry application
 * of this request, "pharmacy" or "retail". Shared modules ask it which
 * items they handle here and record it on what they create, so a retail
 * counter never sells a medicine and a pharmacy never lists a shop's goods.
 * Settings per industry: config/industries.php.
 */
class Industry
{
    public function __construct(private readonly PlatformConfig $platform) {}

    public function code(): string
    {
        return $this->platform->application();
    }

    /**
     * One of this industry's catalog permissions by its name without the
     * application: permission('sales.void') is "retail.sales.void" in retail.
     */
    public function permission(string $name): string
    {
        return "{$this->code()}.{$name}";
    }

    /**
     * This industry's Inertia page for a shared screen: the primary
     * application's pages are at the root ("sales/show"), a hosted one's
     * under its code ("retail/sales/show").
     */
    public function page(string $name): string
    {
        return $this->code() === $this->platform->primary() ? $name : "{$this->code()}/{$name}";
    }

    /**
     * The name of one of this industry's own routes: the primary
     * application's are unprefixed ("home"), a hosted one's carry its code
     * ("retail.home").
     */
    public function route(string $name): string
    {
        return $this->code() === $this->platform->primary() ? $name : "{$this->code()}.{$name}";
    }

    /**
     * The item kinds this industry keeps, buys and sells.
     *
     * @return list<string>
     */
    public function itemKinds(): array
    {
        $kinds = config("industries.{$this->code()}.item_kinds");

        if (! is_array($kinds) || $kinds === []) {
            throw new RuntimeException("No item kinds for [{$this->code()}] in config/industries.php.");
        }

        return array_values(array_map('strval', $kinds));
    }

    /**
     * What this industry calls one of its items, for headings and exports
     * ("Medicine", "Product").
     */
    public function itemLabel(): string
    {
        return (string) config("industries.{$this->code()}.item_label", 'Item');
    }

    /**
     * Whether items of this kind are this industry's to buy, keep and sell.
     */
    public function handles(string $kind): bool
    {
        return in_array($kind, $this->itemKinds(), true);
    }

    /**
     * The most one line may be discounted at this industry's counter, in
     * percent, for a member allowed to give discounts.
     */
    public function maxDiscountPercent(): int
    {
        return max(0, min(100, (int) config("industries.{$this->code()}.max_discount_percent", 0)));
    }
}
