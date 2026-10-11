<?php

namespace App\Modules\Memberships\Actions;

use RuntimeException;

/**
 * An invitation or membership change was refused. The message is safe to
 * show to the person who tried.
 */
final class MembershipActionDenied extends RuntimeException {}
