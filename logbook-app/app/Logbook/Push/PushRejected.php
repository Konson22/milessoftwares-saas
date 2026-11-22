<?php

namespace App\Logbook\Push;

use RuntimeException;

/** The push service refused the request itself (credentials, format): retrying will not help. */
class PushRejected extends RuntimeException {}
