import { Form, Head, Link } from '@inertiajs/react';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import { useState } from 'react';
import InputError from '@/shared/components/input-error';
import PasswordInput from '@/shared/components/password-input';
import { Button } from '@/shared/components/ui/button';
import { Input } from '@/shared/components/ui/input';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/shared/components/ui/input-otp';
import { Label } from '@/shared/components/ui/label';
import { Spinner } from '@/shared/components/ui/spinner';
import { OTP_MAX_LENGTH } from '@/shared/hooks/use-two-factor-auth';

type Props =
    | { step: 'invalid' }
    | {
          step: 'account';
          token: string;
          email: string;
          name: string | null;
          hasAccount: boolean;
          passwordRules: string;
      }
    | {
          step: 'two-factor';
          token: string;
          email: string;
          qrCodeSvg: string;
          setupKey: string;
          recoveryCodes: string[];
      }
    | { step: 'two-factor-existing'; token: string; email: string };

/**
 * Where a staff invitation leads: a password first (new accounts choose one
 * with their name; existing accounts choose a new one, as a reset would),
 * then two-factor authentication: set up here, or proven with the
 * authenticator the account already has. Admin sign-in needs both, so
 * nobody is made staff before they hold them.
 */
export default function StaffInvitation(props: Props) {
    return (
        <>
            <Head title="Set up admin access" />
            {props.step === 'invalid' && <Invalid />}
            {props.step === 'account' && <Account {...props} />}
            {props.step === 'two-factor' && <TwoFactor {...props} />}
            {props.step === 'two-factor-existing' && (
                <div className="grid gap-6">
                    <p className="text-sm text-muted-foreground">
                        Step 2 of 2: your account already uses an authenticator
                        app. Enter the six-digit code it shows for Miles.
                    </p>
                    <CodeForm token={props.token} />
                </div>
            )}
        </>
    );
}

function Invalid() {
    return (
        <div className="grid gap-4 text-center text-sm">
            <p>
                This invitation link is no longer valid. It may have been used,
                cancelled or replaced by a newer one, or it has expired.
            </p>
            <p className="text-muted-foreground">
                Ask the person who invited you to send a new one.
            </p>
            <Link href="/login" className="font-medium underline">
                Go to admin sign-in
            </Link>
        </div>
    );
}

function Account({
    token,
    email,
    name,
    hasAccount,
    passwordRules,
}: Extract<Props, { step: 'account' }>) {
    return (
        <Form
            action={`/invitation/${token}`}
            method="post"
            resetOnError={['password', 'password_confirmation']}
            className="grid gap-5"
        >
            {({ processing, errors }) => (
                <>
                    <p className="text-sm text-muted-foreground">
                        Step 1 of 2:{' '}
                        {hasAccount
                            ? 'you already have a Miles account with this email. Choose a new password for it; you will use it to sign in everywhere.'
                            : 'choose your name and a password.'}
                    </p>
                    <div className="grid gap-2">
                        <Label htmlFor="email">Email address</Label>
                        <Input
                            id="email"
                            type="email"
                            value={email}
                            readOnly
                            autoComplete="username"
                        />
                    </div>
                    {!hasAccount && (
                        <div className="grid gap-2">
                            <Label htmlFor="name">Your name</Label>
                            <Input
                                id="name"
                                name="name"
                                defaultValue={name ?? ''}
                                required
                                autoFocus
                                autoComplete="name"
                            />
                            <InputError message={errors.name} />
                        </div>
                    )}
                    <div className="grid gap-2">
                        <Label htmlFor="password">
                            {hasAccount ? 'New password' : 'Password'}
                        </Label>
                        <PasswordInput
                            id="password"
                            name="password"
                            required
                            autoFocus={hasAccount}
                            autoComplete="new-password"
                            passwordrules={passwordRules}
                        />
                        <InputError message={errors.password} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="password_confirmation">
                            Confirm password
                        </Label>
                        <PasswordInput
                            id="password_confirmation"
                            name="password_confirmation"
                            required
                            autoComplete="new-password"
                        />
                    </div>
                    <InputError message={errors.invitation} />
                    <Button
                        type="submit"
                        className="w-full"
                        disabled={processing}
                    >
                        {processing && <Spinner />}
                        Continue
                    </Button>
                </>
            )}
        </Form>
    );
}

function TwoFactor({
    token,
    qrCodeSvg,
    setupKey,
    recoveryCodes,
}: Extract<Props, { step: 'two-factor' }>) {
    return (
        <div className="grid gap-6">
            <p className="text-sm text-muted-foreground">
                Step 2 of 2: scan this code with an authenticator app (Google
                Authenticator, Microsoft Authenticator, 1Password…), then enter
                the six-digit code it shows.
            </p>
            <div
                className="mx-auto rounded-lg bg-white p-3 [&_svg]:size-44"
                // Generated by the server from the account's own secret.
                dangerouslySetInnerHTML={{ __html: qrCodeSvg }}
            />
            <div className="grid gap-1 text-center text-xs">
                <span className="text-muted-foreground">
                    Can't scan? Enter this key instead:
                </span>
                <code className="font-mono text-sm break-all select-all">
                    {setupKey}
                </code>
            </div>

            <CodeForm token={token} />

            <details className="rounded-md border p-3 text-sm">
                <summary className="cursor-pointer font-medium">
                    Recovery codes: save these now
                </summary>
                <p className="mt-2 text-muted-foreground">
                    Each one signs you in once if you lose your phone. Keep them
                    somewhere safe.
                </p>
                <ul className="mt-3 grid grid-cols-2 gap-1 font-mono text-xs">
                    {recoveryCodes.map((recovery) => (
                        <li key={recovery}>{recovery}</li>
                    ))}
                </ul>
            </details>
        </div>
    );
}

/** The six-digit code that finishes the invitation. */
function CodeForm({ token }: { token: string }) {
    const [code, setCode] = useState('');

    return (
        <Form
            action={`/invitation/${token}/two-factor`}
            method="post"
            resetOnError
            className="grid gap-4"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid justify-items-center gap-2">
                        <InputOTP
                            name="code"
                            maxLength={OTP_MAX_LENGTH}
                            value={code}
                            onChange={setCode}
                            disabled={processing}
                            pattern={REGEXP_ONLY_DIGITS}
                            aria-label="Code from your authenticator app"
                            autoFocus
                        >
                            <InputOTPGroup>
                                {Array.from(
                                    { length: OTP_MAX_LENGTH },
                                    (_, index) => (
                                        <InputOTPSlot
                                            key={index}
                                            index={index}
                                        />
                                    ),
                                )}
                            </InputOTPGroup>
                        </InputOTP>
                        <InputError message={errors.code} />
                        <InputError message={errors.invitation} />
                    </div>
                    <Button
                        type="submit"
                        className="w-full"
                        disabled={processing || code.length < OTP_MAX_LENGTH}
                    >
                        {processing && <Spinner />}
                        Confirm and sign in
                    </Button>
                </>
            )}
        </Form>
    );
}

StaffInvitation.layout = {
    title: 'Set up your admin access',
    description: 'Miles Global Softwares staff',
};
