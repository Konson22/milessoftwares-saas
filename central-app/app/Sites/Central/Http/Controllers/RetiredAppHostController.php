<?php

namespace App\Sites\Central\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Hosts\PlatformHosts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * app.<root> was the customer dashboard. Its pages now live on each
 * business's own host (/, /manage/...) and on the auth host (the list of
 * businesses, new business, trials, invitations), so old links and
 * bookmarks are sent there. A page that belongs to one business goes
 * through the business list (workspaces/continue), which opens it in the
 * only business or lets the member choose.
 */
class RetiredAppHostController extends Controller
{
    /**
     * Old path => where it now lives inside a business (?to=).
     *
     * @var array<string, string>
     */
    private const BUSINESS_PAGES = [
        'organization/settings' => 'manage/settings',
        'organization/subscription' => 'manage/subscription',
        'organization/plans' => 'manage/plans',
        'organization/billing' => 'manage/billing',
        'explore' => 'manage/explore',
    ];

    public function __invoke(Request $request, string $path = ''): RedirectResponse
    {
        $path = trim($path, '/');
        $query = $request->getQueryString();
        $auth = fn (string $to) => PlatformHosts::url(PlatformHosts::auth(), $to).($query ? '?'.$query : '');

        $target = match (true) {
            $path === 'trial' => $auth('trial'),
            $path === 'organizations/create' => $auth('workspaces/create'),
            preg_match('#^invitations/([A-Za-z0-9]{48})$#', $path, $token) === 1 => $auth('invitations/'.$token[1]),
            preg_match('#^organization/billing/invoices/([0-9a-f-]{36})$#', $path, $invoice) === 1 => $this->inBusiness('manage/billing/invoices/'.$invoice[1]),
            isset(self::BUSINESS_PAGES[$path]) => $this->inBusiness(self::BUSINESS_PAGES[$path]),
            default => PlatformHosts::url(PlatformHosts::auth(), 'workspaces/continue'),
        };

        return redirect()->away($target, 301);
    }

    private function inBusiness(string $to): string
    {
        return PlatformHosts::url(PlatformHosts::auth(), 'workspaces/continue').'?'.http_build_query(['to' => $to]);
    }
}
