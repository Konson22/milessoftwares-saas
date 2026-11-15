<?php

namespace App\FinanceIntegration;

use RuntimeException;

/**
 * Finance's event arrived before one it depends on (a gap in the request's
 * sequence): answered 503 and not kept, so Finance's outbox retries it.
 */
final class OutcomeNotYet extends RuntimeException {}
