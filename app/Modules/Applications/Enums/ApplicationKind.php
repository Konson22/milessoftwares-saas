<?php

namespace App\Modules\Applications\Enums;

enum ApplicationKind: string
{
    case Standalone = 'standalone';
    case SharedModule = 'shared_module';
    case Industry = 'industry';
    case Addon = 'addon';
    // Platform-internal (e.g. the workspace shell); never listed in the catalog.
    case Platform = 'platform';
}
