<?php

use App\Modules\Identity\Providers\IdentityServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use OpenIDConnect\Laravel\PassportServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    IdentityServiceProvider::class,
    // Passport with OpenID Connect (id_token, nonce); Passport itself is not auto-discovered.
    PassportServiceProvider::class,
];
