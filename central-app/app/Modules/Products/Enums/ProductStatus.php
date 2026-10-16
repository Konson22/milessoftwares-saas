<?php

namespace App\Modules\Products\Enums;

use App\Modules\Applications\Enums\ApplicationStatus;

enum ProductStatus: string
{
    // Announced as coming soon; nothing can be used.
    case Planned = 'planned';
    // In use by selected customers; not generally sold.
    case Pilot = 'pilot';
    case Available = 'available';
    case Retired = 'retired';

    /** Whether organizations can hold and use the product. */
    public function isLive(): bool
    {
        return $this === self::Pilot || $this === self::Available;
    }

    /**
     * Whether a required application is released enough for a product in
     * this status: a pilot runs on beta software, a generally available
     * product only on generally available software.
     */
    public function accepts(ApplicationStatus $application): bool
    {
        return match ($this) {
            self::Planned, self::Retired => true,
            self::Pilot => in_array($application, [ApplicationStatus::Beta, ApplicationStatus::Available], true),
            self::Available => $application === ApplicationStatus::Available,
        };
    }
}
