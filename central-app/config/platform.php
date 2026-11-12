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

    /*
    | Applications that may create a workspace for another organization on
    | behalf of one of their own (Projects: a donor for its grantee). The
    | workspace waits, pending, for the person it was made for to claim it.
    */

    'sponsoring_applications' => ['projects'],

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
    | Contact messages and demo requests are emailed to support_email. A new workspace may start a free trial of one
    | application for trial_days.
    |
    */

    'marketing' => [
        'support_email' => env('PLATFORM_SUPPORT_EMAIL', 'support@milesoftwares.com'),
        'trial_days' => (int) env('PLATFORM_TRIAL_DAYS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | App downloads
    |--------------------------------------------------------------------------
    |
    | Installers the public site offers at /download/{code}: Windows setup
    | files and Android APKs. The files live outside the repository, in
    | `path` (on the server, under the shared storage, so a release keeps
    | them). One that is not there yet is listed without a download; an
    | Android app with a `store` link (Google Play) offers that as well.
    | `product` is the catalog page it belongs to. `releases` are the
    | release notes, newest first; an older one whose own `file` is still
    | on the server can be downloaded at /download/{code}/{version}.
    |
    */

    'downloads' => [
        'path' => env('PLATFORM_DOWNLOADS_PATH', storage_path('app/downloads')),
        'apps' => [
            [
                'code' => 'pharmacy',
                'platform' => 'windows',
                'name' => 'Tatook Pharmacy',
                'summary' => 'Your pharmacy on a Windows PC, two ways. On its own: the whole pharmacy on one PC with its own database, no internet or account needed, free for 90 days and then activated with a licence key. Or as the counter of your Tatook workspace: keeps selling when the internet drops and sends the sales once it is back (shops sign in to Tatook Retail with the same app).',
                'needs' => 'Nothing to start: a 90-day trial on one PC, or a Tatook workspace with Pharmacy or Retail',
                'file' => 'Miles Pharmacy Setup 1.1.0.exe',
                'version' => '1.1.0',
                'product' => 'erp/pharmacy',
                'releases' => [
                    [
                        'version' => '1.1.0',
                        'date' => '2026-10-31',
                        'changes' => [
                            'Offline pharmacies are free for 90 days, then activated with a licence key: online in the app, or by phone with a QR code.',
                            'Settings in tabs: pharmacy, counter, receipt, backups, activation and system.',
                            'Receipt printer setup: a Windows printer or a network thermal printer, with a connection check and a test page.',
                            'Sign out from the profile menu, and a shorter setup for offline pharmacies.',
                        ],
                    ],
                    [
                        'version' => '1.0.0',
                        'date' => '2026-10-25',
                        'file' => 'Miles Pharmacy Setup 1.0.0.exe',
                        'changes' => [
                            'First release: the pharmacy and shop counter for Tatook workspaces, with sales that wait for the internet.',
                            'Barcode scanners, receipt printing and keyboard shortcuts.',
                        ],
                    ],
                ],
            ],
            [
                'code' => 'restaurant',
                'platform' => 'windows',
                'name' => 'Tatook Restaurant',
                'summary' => 'The floor, orders, kitchen screen, shifts and bills of your workspace restaurant on a Windows PC.',
                'needs' => 'A Tatook workspace with Restaurant',
                'file' => 'Miles Restaurant Setup 1.0.0.exe',
                'version' => '1.0.0',
                'product' => 'erp/restaurant',
                'releases' => [
                    [
                        'version' => '1.0.0',
                        'date' => null,
                        'changes' => ['First release: tables, orders, the kitchen screen, shifts and bills.'],
                    ],
                ],
            ],
            [
                'code' => 'pharmacy-android',
                'platform' => 'android',
                'name' => 'Miles Pharma POS',
                'summary' => 'The pharmacy counter on an Android phone or tablet: sell, look up stock and prices, and scan barcodes with the camera.',
                'needs' => 'A Tatook workspace with Pharmacy',
                'file' => 'Miles Pharmacy POS 1.0.0.apk',
                'version' => '1.0.0',
                'product' => 'erp/pharmacy',
                'store' => env('PLATFORM_PLAY_PHARMACY_URL'),
                'releases' => [
                    [
                        'version' => '1.0.0',
                        'date' => null,
                        'changes' => ['First release: sales, stock lookup and camera barcode scanning.'],
                    ],
                ],
            ],
            [
                'code' => 'retail-android',
                'platform' => 'android',
                'name' => 'Tatook Retail',
                'summary' => 'The shop counter on an Android phone or tablet: sales, stock counts and price checks from the shop floor.',
                'needs' => 'A Tatook workspace with Retail & POS',
                'file' => 'Miles Retail 1.0.0.apk',
                'version' => '1.0.0',
                'product' => 'erp/retail',
                'store' => env('PLATFORM_PLAY_RETAIL_URL'),
                'releases' => [
                    [
                        'version' => '1.0.0',
                        'date' => null,
                        'changes' => ['First release: sales, stock counts and price checks.'],
                    ],
                ],
            ],
            [
                'code' => 'restaurant-android',
                'platform' => 'android',
                'name' => 'Mataem Plus',
                'summary' => 'Take orders at the table on an Android phone or tablet and send them straight to the kitchen.',
                'needs' => 'A Tatook workspace with Restaurant',
                'file' => 'Miles Restaurant 1.0.0.apk',
                'version' => '1.0.0',
                'product' => 'erp/restaurant',
                'store' => env('PLATFORM_PLAY_RESTAURANT_URL'),
                'releases' => [
                    [
                        'version' => '1.0.0',
                        'date' => null,
                        'changes' => ['First release: table orders sent to the kitchen.'],
                    ],
                ],
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
    | admin.<root> and every workspace host sign users in through
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

    /*
    |--------------------------------------------------------------------------
    | Desktop licences
    |--------------------------------------------------------------------------
    |
    | Activation codes for the offline desktop pharmacy are Ed25519-signed
    | here and checked in the app with the public half. signing_key is the
    | 32-byte seed, base64 (php artisan licences:keygen makes one). Never in
    | git: anyone holding it can make codes.
    |
    */

    'licences' => [
        'signing_key' => env('PLATFORM_LICENCE_SIGNING_KEY'),
    ],

];
