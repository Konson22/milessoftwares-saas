<?php

namespace App\Finance;

use RuntimeException;

/**
 * An event type or schema version Finance does not post.
 */
class UnsupportedEvent extends RuntimeException {}
