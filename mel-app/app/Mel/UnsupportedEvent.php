<?php

namespace App\Mel;

use RuntimeException;

/** An event type or version M&E does not handle. */
class UnsupportedEvent extends RuntimeException {}
