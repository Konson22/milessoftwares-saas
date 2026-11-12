<?php

namespace App\Payroll;

use RuntimeException;

/** An event type or version Payroll does not handle. */
class UnsupportedEvent extends RuntimeException {}
