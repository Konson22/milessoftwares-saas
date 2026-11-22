<?php

namespace App\Logbook\Push;

use RuntimeException;

/** The push service could not be reached or is overloaded: try again later. */
class PushUnavailable extends RuntimeException {}
