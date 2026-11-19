import { Head } from '@inertiajs/react';
import { Button } from '@/shared/components/ui/button';
import { problems, startingLine } from '@/sites/central/pages/get-started';
import type { GetStartedProps } from '@/sites/central/pages/get-started';

/**
 * A product chosen on the public site, for someone not signed in: what it
 * is, then create an account or sign in. Either one comes back here.
 */
export default function GuestGetStarted({
    state,
    selection,
    trialDays,
    registerUrl,
    loginUrl,
    chooseAgainUrl,
}: GetStartedProps) {
    if (state !== 'guest' || !selection) {
        return (
            <>
                <Head title="Get started" />
                <div className="space-y-4 text-center">
                    <p className="text-sm">
                        {problems[state] ?? problems.missing}
                    </p>
                    <Button asChild className="w-full">
                        <a href={chooseAgainUrl}>Choose a product</a>
                    </Button>
                </div>
            </>
        );
    }

    return (
        <>
            <Head
                title={`Get started with ${selection.name ?? 'Miles Softwares'}`}
            />
            <div className="space-y-6">
                <div className="rounded-lg border bg-card p-4">
                    <p className="text-sm text-muted-foreground">
                        You're getting started with
                    </p>
                    <p className="mt-1 text-lg font-semibold">
                        {selection.name}
                    </p>
                    {selection.applications.length > 1 && (
                        <p className="mt-1 text-sm text-muted-foreground">
                            Includes {selection.applications.join(', ')}.
                        </p>
                    )}
                    <p className="mt-2 text-sm">
                        {startingLine(selection, trialDays)}
                    </p>
                </div>
                <div className="grid gap-2">
                    <Button asChild className="w-full">
                        <a href={registerUrl}>Create your account</a>
                    </Button>
                    <Button asChild variant="outline" className="w-full">
                        <a href={loginUrl}>I already have an account</a>
                    </Button>
                </div>
                <p className="text-center text-sm">
                    <a
                        href={chooseAgainUrl}
                        className="underline underline-offset-4"
                    >
                        Choose something else
                    </a>
                </p>
            </div>
        </>
    );
}

GuestGetStarted.layout = {
    title: 'Get started',
    description: 'Create an account, or sign in, to continue',
};
