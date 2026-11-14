<?php

namespace App\Mel;

use RuntimeException;

/** The event depends on something that has not arrived yet: the sender retries. */
class NotYetApplicable extends RuntimeException {}
