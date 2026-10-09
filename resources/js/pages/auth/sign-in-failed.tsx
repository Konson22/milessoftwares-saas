import { Head } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

type Props = {
    retryUrl: string;
};

export default function SignInFailed({ retryUrl }: Props) {
    return (
        <>
            <Head title="Sign-in failed" />

            <Button asChild className="w-full">
                <a href={retryUrl}>Try again</a>
            </Button>
        </>
    );
}

SignInFailed.layout = {
    title: 'We could not sign you in',
    description:
        'The sign-in could not be completed, or the link expired. Please try again.',
};
