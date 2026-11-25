<?php

namespace App\Modules\Identity\Enums;

enum SecurityAlertType: string
{
    case NewSignIn = 'new_sign_in';
    case PasswordChanged = 'password_changed';
    case MfaEnabled = 'mfa_enabled';
    case MfaDisabled = 'mfa_disabled';
    case RecoveryCodeUsed = 'recovery_code_used';
    case PasskeyAdded = 'passkey_added';
    case PasskeyRemoved = 'passkey_removed';

    public function subject(): string
    {
        return match ($this) {
            self::NewSignIn => 'New sign-in to your Miles Global Softwares account',
            self::PasswordChanged => 'Your password was changed',
            self::MfaEnabled => 'Two-factor authentication was turned on',
            self::MfaDisabled => 'Two-factor authentication was turned off',
            self::RecoveryCodeUsed => 'A recovery code was used to sign in',
            self::PasskeyAdded => 'A passkey was added to your account',
            self::PasskeyRemoved => 'A passkey was removed from your account',
        };
    }

    public function summary(): string
    {
        return match ($this) {
            self::NewSignIn => 'Your account was signed in to from a device or network we have not seen recently.',
            self::PasswordChanged => 'The password for your account was changed. Other sessions have been signed out.',
            self::MfaEnabled => 'Two-factor authentication is now required when you sign in.',
            self::MfaDisabled => 'Two-factor authentication is no longer required when you sign in.',
            self::RecoveryCodeUsed => 'One of your recovery codes was used. It cannot be used again.',
            self::PasskeyAdded => 'A new passkey can now be used to sign in to your account.',
            self::PasskeyRemoved => 'A passkey can no longer be used to sign in to your account.',
        };
    }
}
