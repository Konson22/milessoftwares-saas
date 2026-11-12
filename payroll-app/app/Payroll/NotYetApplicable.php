<?php

namespace App\Payroll;

use RuntimeException;

/** The event depends on something that has not arrived yet: the sender retries. */
class NotYetApplicable extends RuntimeException {}
