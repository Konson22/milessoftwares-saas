<?php

namespace App\Modules\Applications\Catalog;

use RuntimeException;

/**
 * A published catalog version was re-imported with different content.
 */
final class CatalogConflict extends RuntimeException {}
