import { Form, Head } from '@inertiajs/react';
import { Camera, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import GoogleSignInButton from '@/shared/components/google-sign-in-button';
import InputError from '@/shared/components/input-error';
import PasswordInput from '@/shared/components/password-input';
import { Button } from '@/shared/components/ui/button';
import { Input } from '@/shared/components/ui/input';
import { Label } from '@/shared/components/ui/label';
import { Spinner } from '@/shared/components/ui/spinner';

type Props =
    | {
          status: 'new';
          token: string;
          email: string;
          gmail: boolean;
          organization: string | null;
          passwordRules: string;
          canJoinWithGoogle: boolean;
      }
    | { status: 'invalid' | 'accepted' | 'revoked' | 'expired' };

const unavailable: Record<string, string> = {
    invalid: 'This invitation link is not valid.',
    accepted: 'This invitation has already been used.',
    revoked: 'This invitation was withdrawn.',
    expired:
        'This invitation has expired. Ask the person who invited you for a new one.',
};

export default function Join(props: Props) {
    if (props.status !== 'new') {
        return (
            <>
                <Head title="Invitation" />
                <p className="text-center text-sm text-muted-foreground">
                    {unavailable[props.status]}
                </p>
            </>
        );
    }

    const {
        token,
        email,
        gmail,
        organization,
        passwordRules,
        canJoinWithGoogle,
    } = props;

    if (gmail) {
        return (
            <AcceptOnly
                token={token}
                email={email}
                organization={organization}
            />
        );
    }

    return (
        <>
            <Head title="Join" />
            <p className="mb-6 text-center text-sm text-muted-foreground">
                {organization
                    ? `You were invited to ${organization}. Choose your name and password to join.`
                    : 'Choose your name and password to join.'}
            </p>
            {canJoinWithGoogle && (
                <>
                    <GoogleSignInButton
                        href={`/join/${token}/google`}
                        label="Join with Google"
                    />
                    <p className="-mt-1 mb-2 text-center text-xs text-muted-foreground">
                        Use the Google account for {email}. No password needed.
                    </p>
                    <div className="my-4 flex items-center gap-3 text-xs text-muted-foreground uppercase">
                        <span className="h-px flex-1 bg-border" />
                        or
                        <span className="h-px flex-1 bg-border" />
                    </div>
                </>
            )}
            <Form action={`/join/${token}`} method="post">
                {({ processing, errors }) => (
                    <div className="grid gap-6">
                        <div className="grid gap-2">
                            <Label htmlFor="email">Email</Label>
                            <Input id="email" value={email} readOnly />
                        </div>
                        <AvatarPicker error={errors.avatar} />
                        <div className="grid gap-2">
                            <Label htmlFor="name">Your name</Label>
                            <Input
                                id="name"
                                name="name"
                                autoComplete="name"
                                autoFocus
                                required
                            />
                            <InputError message={errors.name} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="password">Password</Label>
                            <PasswordInput
                                id="password"
                                name="password"
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
                                autoComplete="new-password"
                                passwordrules={passwordRules}
                            />
                        </div>
                        <InputError message={errors.invitation} />
                        <Button
                            type="submit"
                            className="w-full"
                            disabled={processing}
                        >
                            {processing && <Spinner />}
                            Join
                        </Button>
                    </div>
                )}
            </Form>
        </>
    );
}

/**
 * A Gmail address joins with one button: the link proves the inbox, and
 * they sign in later with "Continue with Google", so there is nothing to
 * fill in.
 */
function AcceptOnly({
    token,
    email,
    organization,
}: {
    token: string;
    email: string;
    organization: string | null;
}) {
    return (
        <>
            <Head title="Join" />
            <p className="mb-6 text-center text-sm text-muted-foreground">
                {organization
                    ? `You were invited to ${organization}.`
                    : 'You were invited to a workspace.'}
            </p>
            <Form action={`/join/${token}`} method="post">
                {({ processing, errors }) => (
                    <div className="grid gap-6">
                        <div className="grid gap-2">
                            <Label htmlFor="email">Email</Label>
                            <Input id="email" value={email} readOnly />
                        </div>
                        <InputError message={errors.invitation} />
                        <Button
                            type="submit"
                            className="w-full"
                            disabled={processing}
                        >
                            {processing && <Spinner />}
                            Accept invitation
                        </Button>
                        <p className="text-center text-xs text-muted-foreground">
                            No password needed. Next time, sign in with{' '}
                            <span className="font-medium text-foreground">
                                Continue with Google
                            </span>{' '}
                            using {email}.
                        </p>
                    </div>
                )}
            </Form>
        </>
    );
}

/** Optional profile picture, previewed before it is sent. */
function AvatarPicker({ error }: { error?: string }) {
    const input = useRef<HTMLInputElement>(null);
    const [preview, setPreview] = useState<string | null>(null);

    useEffect(
        () => () => {
            if (preview) URL.revokeObjectURL(preview);
        },
        [preview],
    );

    const clear = () => {
        if (input.current) input.current.value = '';
        setPreview(null);
    };

    return (
        <div className="grid gap-2">
            <Label htmlFor="avatar">
                Profile picture{' '}
                <span className="font-normal text-muted-foreground">
                    (optional)
                </span>
            </Label>
            <div className="flex items-center gap-4">
                <button
                    type="button"
                    onClick={() => input.current?.click()}
                    className="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-full border border-dashed border-border bg-muted text-muted-foreground hover:bg-accent"
                    aria-label="Choose a profile picture"
                >
                    {preview ? (
                        <img
                            src={preview}
                            alt=""
                            className="size-full object-cover"
                        />
                    ) : (
                        <Camera className="size-6" />
                    )}
                </button>
                <div className="flex flex-col items-start gap-1">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => input.current?.click()}
                    >
                        {preview ? 'Change picture' : 'Choose picture'}
                    </Button>
                    {preview ? (
                        <button
                            type="button"
                            onClick={clear}
                            className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                        >
                            <X className="size-3" /> Remove
                        </button>
                    ) : (
                        <span className="text-xs text-muted-foreground">
                            JPEG, PNG or WebP, up to 5 MB
                        </span>
                    )}
                </div>
                <input
                    ref={input}
                    id="avatar"
                    name="avatar"
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    className="hidden"
                    onChange={(e) => {
                        const file = e.target.files?.[0];
                        setPreview(file ? URL.createObjectURL(file) : null);
                    }}
                />
            </div>
            <InputError message={error} />
        </div>
    );
}

Join.layout = { title: 'Join your team', description: '' };
