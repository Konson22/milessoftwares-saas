import { Form, Head } from '@inertiajs/react';
import { Monitor } from 'lucide-react';
import { useRef } from 'react';
import SessionController from '@/actions/App/Sites/Central/Http/Controllers/Settings/SessionController';
import Heading from '@/shared/components/heading';
import InputError from '@/shared/components/input-error';
import PasswordInput from '@/shared/components/password-input';
import { Badge } from '@/shared/components/ui/badge';
import { Button } from '@/shared/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/shared/components/ui/dialog';
import { Label } from '@/shared/components/ui/label';
import { index as sessionsIndex } from '@/routes/sessions';

type Session = {
    id: string;
    device: string;
    host: string;
    ip_address: string | null;
    is_current: boolean;
    created_at: string;
    last_seen_at_diff: string;
};

type Props = {
    sessions: Session[];
};

export default function Sessions({ sessions }: Props) {
    const hasOthers = sessions.some((session) => !session.is_current);

    return (
        <>
            <Head title="Sessions" />

            <h1 className="sr-only">Sessions</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Signed-in sessions"
                    description="Browsers currently signed in to your account. Sign out any you do not recognise, then change your password."
                />

                <ul className="divide-y rounded-lg border">
                    {sessions.map((session) => (
                        <li
                            key={session.id}
                            className="flex items-center gap-4 p-4"
                        >
                            <Monitor
                                className="size-5 shrink-0 text-muted-foreground"
                                aria-hidden
                            />
                            <div className="min-w-0 flex-1">
                                <p className="flex items-center gap-2 text-sm font-medium">
                                    {session.device}
                                    {session.is_current && (
                                        <Badge variant="secondary">
                                            This device
                                        </Badge>
                                    )}
                                </p>
                                <p className="truncate text-xs text-muted-foreground">
                                    {session.ip_address ?? 'Unknown IP'} ·{' '}
                                    {session.host} · active{' '}
                                    {session.last_seen_at_diff}
                                </p>
                            </div>
                            {!session.is_current && (
                                <Form
                                    {...SessionController.destroy.form(
                                        session.id,
                                    )}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            size="sm"
                                            disabled={processing}
                                        >
                                            Sign out
                                        </Button>
                                    )}
                                </Form>
                            )}
                        </li>
                    ))}
                </ul>

                {hasOthers && <SignOutOthers />}
            </div>
        </>
    );
}

function SignOutOthers() {
    const passwordInput = useRef<HTMLInputElement>(null);

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="destructive">
                    Sign out all other sessions
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Sign out all other sessions?</DialogTitle>
                <DialogDescription>
                    Every other browser will be signed out and will need to sign
                    in again. Enter your password to confirm.
                </DialogDescription>

                <Form
                    {...SessionController.destroyOthers.form()}
                    options={{ preserveScroll: true }}
                    onError={() => passwordInput.current?.focus()}
                    resetOnSuccess
                    className="space-y-6"
                >
                    {({ resetAndClearErrors, processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="password" className="sr-only">
                                    Password
                                </Label>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    ref={passwordInput}
                                    placeholder="Password"
                                    autoComplete="current-password"
                                />
                                <InputError message={errors.password} />
                            </div>

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button
                                        variant="secondary"
                                        onClick={() => resetAndClearErrors()}
                                    >
                                        Cancel
                                    </Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                >
                                    Sign out other sessions
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

Sessions.layout = {
    breadcrumbs: [
        {
            title: 'Sessions',
            href: sessionsIndex(),
        },
    ],
};
