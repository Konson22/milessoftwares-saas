<?php

namespace App\Modules\Licences\Actions;

use RuntimeException;

/**
 * Why a key does not activate: answered to the app (and the activation page)
 * as the field it concerns and a message a customer can act on.
 */
final class LicenceDenied extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly string $field, string $message)
    {
        parent::__construct($message);
    }

    public static function unknownKey(): self
    {
        return new self('unknown_key', 'key', 'No licence key like this. Check it against the email or card, letter by letter.');
    }

    public static function revoked(): self
    {
        return new self('revoked', 'key', 'This licence key has been cancelled. Contact Miles Softwares.');
    }

    public static function inUse(): self
    {
        return new self('in_use', 'key', 'This licence key is already in use on another PC. To move it to this one, contact Miles Softwares.');
    }

    public static function badDevice(): self
    {
        return new self('bad_device', 'device', 'That is not a device code. It reads like 7KQ2-M9XD-4FTA, under Settings, Activation.');
    }

    public static function badPharmacy(): self
    {
        return new self('bad_pharmacy', 'pharmacy', 'That is not a pharmacy ID. It reads like AB12-CD34, under Settings, Activation.');
    }

    public static function notConfigured(): self
    {
        return new self('not_configured', 'key', 'Activation is not available right now. Contact Miles Softwares.');
    }
}
