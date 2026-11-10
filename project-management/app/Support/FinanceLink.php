<?php

namespace App\Support;

use App\Models\Organization;
use App\Tenancy\Hosts;
use Illuminate\Support\Facades\Cache;
use Miles\PlatformClient\Api\NotVisible;
use Miles\PlatformClient\Api\PlatformApi;
use Miles\PlatformClient\Api\PlatformUnavailable;

/**
 * Whether the organization has Finance (the shared module), which then keeps
 * its petty cash: Projects' own boxes are moved into Finance funds
 * (App\Actions\PettyCash::moveToFinance) and no new ones are opened here.
 * The platform says so by issuing an event token for Finance, or not.
 */
final class FinanceLink
{
    private const CACHE_SECONDS = 300;

    public function __construct(private readonly PlatformApi $api) {}

    /**
     * With the platform down, the answer is "yes": nothing is paid from a box
     * Finance might already keep. It is not remembered, so it is asked again.
     */
    public function keepsPettyCash(Organization $organization): bool
    {
        $key = "finance-link:{$organization->id}";
        $known = Cache::get($key);
        if (is_bool($known)) {
            return $known;
        }

        try {
            $this->api->eventToken($organization->id, FinanceEvents::AUDIENCE);
            $has = true;
        } catch (NotVisible) {
            $has = false;
        } catch (PlatformUnavailable) {
            return true;
        }

        Cache::put($key, $has, self::CACHE_SECONDS);

        return $has;
    }

    public static function pettyCashUrl(Organization $organization): string
    {
        return Hosts::organization($organization, '/finance/petty-cash');
    }
}
