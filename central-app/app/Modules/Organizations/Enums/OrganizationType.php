<?php

namespace App\Modules\Organizations\Enums;

/**
 * What an organization says it is at sign-up, for the products that work
 * differently for each (Projects: a donor's menu is about the organizations it
 * funds). Null for a business that was never asked.
 */
enum OrganizationType: string
{
    /** Funds the work of other organizations. */
    case Donor = 'donor';

    /** Runs projects and programmes itself (an NGO, an implementing partner...). */
    case Organization = 'organization';
}
