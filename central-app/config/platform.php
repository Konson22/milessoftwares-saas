<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Root Domain
    |--------------------------------------------------------------------------
    |
    | The registrable domain every platform host hangs off. Production uses
    | milesoftwares.com; local development uses milesoftwares.localhost,
    | which browsers resolve to 127.0.0.1 without hosts-file entries.
    |
    */

    'root_domain' => env('PLATFORM_ROOT_DOMAIN', 'milesoftwares.localhost'),

    /*
    |--------------------------------------------------------------------------
    | Platform Hosts
    |--------------------------------------------------------------------------
    |
    | First-level labels served by the central platform itself.
    |
    */

    'hosts' => [
        'auth' => 'auth',
        'app' => 'app',
        'admin' => env('PLATFORM_ADMIN_HOST', 'admin'),
        'www' => 'www',
    ],

    /*
    |--------------------------------------------------------------------------
    | Reserved Labels
    |--------------------------------------------------------------------------
    |
    | Labels that can never be claimed as a business slug. Platform hosts above
    | are always reserved in addition to this list.
    |
    */

    'reserved_slugs' => [
        'about', 'account', 'accounts', 'admin', 'administrator', 'api', 'app', 'apps', 'assets',
        'auth', 'billing', 'blog', 'cdn', 'console', 'contact', 'dashboard', 'demo', 'dev',
        'developer', 'developers', 'docs', 'download', 'email', 'files', 'ftp', 'gateway',
        'help', 'id', 'identity', 'images', 'imap', 'info', 'internal', 'legal', 'login',
        'mail', 'manage', 'marketing', 'media', 'miles', 'milesglobal', 'milesoftwares', 'milessoftwares',
        'mx', 'news', 'ns', 'ns1', 'ns2', 'oauth', 'pop', 'portal', 'pricing', 'privacy',
        'register', 'root', 'sandbox', 'secure', 'security', 'signin', 'signup', 'smtp',
        'sso', 'staging', 'static', 'status', 'store', 'support', 'system', 'terms', 'test',
        'webmail', 'www',
    ],

    /*
    |--------------------------------------------------------------------------
    | Workspace Paths
    |--------------------------------------------------------------------------
    |
    | First path segments the platform itself serves on workspace hosts
    | ({slug}.<root>): routes of the workspace shell and folders under
    | public/. A product's route_prefix can never be one of these, or the web
    | server would send the platform's own pages to the product.
    | tests/Feature/Applications/RoutePrefixTest.php keeps this list complete.
    |
    */

    'workspace_paths' => [
        'auth', 'build', 'hot', 'images', 'logout', 'manage', 'members', 'storage', 'up',
    ],

    /*
    |--------------------------------------------------------------------------
    | Slug Rules
    |--------------------------------------------------------------------------
    */

    'slug' => [
        'min_length' => 4,
        'max_length' => 32,
        // Retired slugs redirect to the current primary slug for this many days
        // and stay unavailable for re-registration permanently.
        'redirect_grace_days' => 180,
        // Minimum time between renames, so a slug cannot be churned.
        'rename_cooldown_days' => 30,
        // Brand and abuse terms that cannot appear anywhere in a slug.
        // Admins can still assign one through an override (Phase 5).
        'blocked_terms' => [
            'milesoftwares', 'milessoftwares', 'milesglobal', 'phishing', 'paypal', 'mpesa',
            'mastercard', 'bank-login', 'verify-account',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Public site
    |--------------------------------------------------------------------------
    |
    | Contact messages notify this address (falls back to the mail
    | from-address). A new workspace may start a free trial of one
    | application for trial_days.
    |
    */

    'marketing' => [
        'sales_email' => env('PLATFORM_SALES_EMAIL'),
        'trial_days' => (int) env('PLATFORM_TRIAL_DAYS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Desktop downloads
    |--------------------------------------------------------------------------
    |
    | Installers the public site offers at /download/{code}. The files live
    | outside the repository, in `path` (on the server, under the shared
    | storage, so a release keeps them). One that is not there yet is listed
    | without a download. `product` is the catalog page it belongs to.
    |
    */

    'downloads' => [
        'path' => env('PLATFORM_DOWNLOADS_PATH', storage_path('app/downloads')),
        'apps' => [
            [
                'code' => 'pharmacy',
                'name' => 'Miles Pharmacy',
                'summary' => 'The counter of your workspace pharmacy on a Windows PC: sell, take payment and run your shift. Keeps selling when the internet drops and sends the sales once it is back. Shops sign in to Miles Retail with the same app.',
                'needs' => 'A Miles workspace with Pharmacy or Retail',
                'file' => 'Miles Pharmacy Setup 1.0.0.exe',
                'version' => '1.0.0',
                'product' => 'erp/pharmacy',
            ],
            [
                'code' => 'pharmacy-offline',
                'name' => 'Miles Pharmacy Offline',
                'summary' => 'A standalone pharmacy for one shop on one PC, with its own database. No internet, server or Miles account needed.',
                'needs' => 'A one-time licence: talk to us',
                'file' => 'Miles Pharmacy Offline Setup 1.0.0.exe',
                'version' => '1.0.0',
                'product' => 'erp/pharmacy',
            ],
            [
                'code' => 'restaurant',
                'name' => 'Miles Restaurant',
                'summary' => 'The floor, orders, kitchen screen, shifts and bills of your workspace restaurant on a Windows PC.',
                'needs' => 'A Miles workspace with Restaurant',
                'file' => 'Miles Restaurant Setup 1.0.0.exe',
                'version' => '1.0.0',
                'product' => 'erp/restaurant',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Billing
    |--------------------------------------------------------------------------
    |
    | Invoices are paid outside the platform (bank transfer, mobile money,
    | cash). The billing page shows customers these instructions; one line
    | each, separated by "|" in the environment. Invoices staff issue are due
    | after due_days unless they choose another date.
    |
    */

    'billing' => [
        'payment_instructions' => array_values(array_filter(array_map('trim', explode('|', (string) env('PLATFORM_PAYMENT_INSTRUCTIONS', ''))))),
        'issuer' => env('PLATFORM_BILLING_ISSUER', 'Miles Global Link Co. Ltd'),
        // Printed on invoices and trial confirmations, under the issuer.
        'address' => env('PLATFORM_BILLING_ADDRESS') ?: 'Juba town, former GPOC building, 1st floor, office No 9, Juba, South Sudan',
        'phone' => env('PLATFORM_BILLING_PHONE') ?: '+211 920 079 070',
        'contact_email' => env('PLATFORM_BILLING_EMAIL', env('PLATFORM_SALES_EMAIL')),
        'due_days' => (int) env('PLATFORM_INVOICE_DUE_DAYS', 14),
    ],

    /*
    |--------------------------------------------------------------------------
    | Platform administration (admin.<root>)
    |--------------------------------------------------------------------------
    |
    | Staff sign in afresh for every admin session (prompt=login) and are
    | signed out after this many idle minutes.
    |
    */

    'admin' => [
        'idle_minutes' => (int) env('PLATFORM_ADMIN_IDLE_MINUTES', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | First-party OpenID Connect clients
    |--------------------------------------------------------------------------
    |
    | app.<root>, admin.<root> and every workspace host sign users in through
    | the auth host like any product would. Create the clients with `php artisan platform:oidc-clients`.
    |
    | backchannel_url: where the token endpoint is reached server-to-server.
    | Defaults to the auth host. Locally on Windows, `php artisan serve` handles
    | one request at a time, so `composer dev` runs a second server for this.
    |
    */

    'oidc' => [
        'backchannel_url' => env('PLATFORM_OIDC_BACKCHANNEL_URL'),
        'clients' => [
            'app' => [
                'id' => env('PLATFORM_APP_CLIENT_ID'),
                'secret' => env('PLATFORM_APP_CLIENT_SECRET'),
            ],
            'admin' => [
                'id' => env('PLATFORM_ADMIN_CLIENT_ID'),
                'secret' => env('PLATFORM_ADMIN_CLIENT_SECRET'),
            ],
            // Shared by every {slug}.<root> host; callbacks are registered per organization.
            'workspace' => [
                'id' => env('PLATFORM_WORKSPACE_CLIENT_ID'),
                'secret' => env('PLATFORM_WORKSPACE_CLIENT_SECRET'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Response Security Headers
    |--------------------------------------------------------------------------
    |
    | Applied to every response by App\Http\Middleware\SecurityHeaders.
    | Scripts need the per-request nonce; styles allow inline because the UI
    | components set style attributes. form-action is deliberately absent:
    | browsers apply it to the redirect after a form post, and sign-in ends
    | with a redirect to each product's own callback host.
    |
    | hsts: only sent on HTTPS requests. Turn on once every host has a valid
    | certificate; browsers then refuse plain HTTP for max_age seconds.
    |
    */

    'security_headers' => [
        'csp' => env('PLATFORM_CSP', true),
        'csp_report_only' => env('PLATFORM_CSP_REPORT_ONLY', false),
        'csp_report_uri' => env('PLATFORM_CSP_REPORT_URI'),
        'hsts' => env('PLATFORM_HSTS', false),
        'hsts_max_age' => (int) env('PLATFORM_HSTS_MAX_AGE', 63072000),
        'hsts_include_subdomains' => env('PLATFORM_HSTS_INCLUDE_SUBDOMAINS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Backlog Alert
    |--------------------------------------------------------------------------
    |
    | queue:monitor runs every minute and logs a critical line when the
    | default Redis queue holds more jobs than this.
    |
    */

    'queue_monitor_max' => (int) env('QUEUE_MONITOR_MAX', 500),

];
