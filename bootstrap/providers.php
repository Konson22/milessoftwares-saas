<?php

use App\Modules\Administration\Providers\AdministrationServiceProvider;
use App\Modules\Applications\Providers\ApplicationsServiceProvider;
use App\Modules\Identity\Providers\IdentityServiceProvider;
use App\Modules\Integration\Providers\IntegrationServiceProvider;
use App\Modules\Memberships\Providers\MembershipsServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use OpenIDConnect\Laravel\PassportServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    IdentityServiceProvider::class,
    ApplicationsServiceProvider::class,
    AdministrationServiceProvider::class,
    MembershipsServiceProvider::class,
    IntegrationServiceProvider::class,
    // Passport with OpenID Connect (id_token, nonce); Passport itself is not auto-discovered.
    PassportServiceProvider::class,
];
