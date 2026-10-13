<?php

namespace App\Finance;

use RuntimeException;

/**
 * The event depends on one that has not arrived yet (a void before its
 * invoice). Nothing is stored; the product's retry will succeed later.
 */
class NotYetPostable extends RuntimeException {}
