<?php

namespace App\Modules\Applications\Enums;

enum ApplicationStatus: string
{
    case Planned = 'planned';
    case Beta = 'beta';
    case Available = 'available';
    case Deprecated = 'deprecated';
    case Retired = 'retired';
}
