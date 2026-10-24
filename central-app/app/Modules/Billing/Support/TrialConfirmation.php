<?php

namespace App\Modules\Billing\Support;

use App\Modules\Applications\Models\Application;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Marketing\Support\MarketingCatalog;
use App\Modules\Organizations\Models\Organization;
use App\Support\Hosts\PlatformHosts;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * The invoice-style confirmation a business gets when it starts a free
 * trial: the trial at zero, and the plans that apply once it ends. It is a
 * document, not an Invoice: nothing is owed and nothing is recorded in
 * Billing. Built as plain data so a queued email can carry it.
 */
final class TrialConfirmation
{
    /**
     * @param  list<string>  $alongside  shared modules tried with it (Finance)
     * @return array<string, mixed>
     */
    public static function data(Organization $organization, Application $application, Entitlement $trial, array $alongside, string $recipientEmail): array
    {
        $plans = app(MarketingCatalog::class)->plans($application->code);
        $currency = strtoupper($organization->default_currency ?: 'USD');
        $start = $trial->valid_from;
        $end = $trial->valid_until ?? now();
        $period = $start->toFormattedDateString().' – '.$end->toFormattedDateString();

        $lines = [[
            'description' => "{$application->name}: free trial",
            'detail' => $period,
            'amount' => Money::format(0, $currency),
        ]];

        foreach ($alongside as $name) {
            $lines[] = [
                'description' => "{$name}: included with the trial",
                'detail' => $period,
                'amount' => Money::format(0, $currency),
            ];
        }

        return [
            'number' => 'TRL-'.$start->format('Y').'-'.strtoupper(substr(str_replace('-', '', $trial->id), -8)),
            'issuedOn' => now()->toFormattedDateString(),
            'trialStart' => $start->toFormattedDateString(),
            'trialEnd' => $end->toFormattedDateString(),
            'days' => (int) round($start->diffInDays($end)),
            'application' => $application->name,
            'currency' => $currency,
            'total' => Money::format(0, $currency),
            'lines' => $lines,
            'plans' => array_slice(array_map(fn (array $plan) => [
                'name' => $plan['name'],
                'price' => $plan['price'],
                'interval' => $plan['interval'],
            ], $plans), 0, 4),
            'billTo' => array_values(array_filter([
                $organization->legal_name ?: $organization->display_name,
                $organization->billing_address ?: $organization->address,
                $organization->billing_email ?: $recipientEmail,
                $organization->tax_id ? 'Tax ID: '.$organization->tax_id : null,
            ])),
            'from' => [
                'name' => 'Miles Global Softwares',
                'issuer' => (string) config('platform.billing.issuer'),
                'address' => (string) config('platform.billing.address'),
                'phone' => (string) config('platform.billing.phone'),
                'email' => (string) (config('platform.billing.contact_email') ?: config('mail.from.address')),
                'website' => PlatformHosts::root(),
            ],
            'plansUrl' => PlatformHosts::url(PlatformHosts::root(), 'pricing'),
            'dashboardUrl' => PlatformHosts::url(PlatformHosts::app()),
        ];
    }

    /**
     * The confirmation as a PDF, with the logo.
     *
     * @param  array<string, mixed>  $data  from data()
     */
    public static function pdf(array $data): string
    {
        $logo = public_path('images/logo-with-text.png');

        return Pdf::loadView('billing.trial-confirmation', $data + [
            'logo' => is_file($logo) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($logo)) : null,
        ])->setPaper('a4')
            // Embed only the glyphs used: the file stays small enough to email.
            ->setOption('isFontSubsettingEnabled', true)
            ->output();
    }
}
