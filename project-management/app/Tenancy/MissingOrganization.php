<?php

namespace App\Tenancy;

use LogicException;

/**
 * An organization-owned model was used without a current organization.
 * Fail closed: never fall back to "all organizations".
 */
final class MissingOrganization extends LogicException {}
