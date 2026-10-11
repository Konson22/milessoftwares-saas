import { Head, Link } from '@inertiajs/react';

export default function Thanks() {
    return (
        <>
            <Head title="Thank you">
                <meta head-key="robots" name="robots" content="noindex" />
            </Head>

            <section className="mx-auto max-w-2xl px-4 py-24 text-center">
                <h1 className="text-3xl font-semibold tracking-tight">
                    Thank you
                </h1>
                <p className="mt-4 text-lg text-muted-foreground">
                    We have your message and will be in touch within one working
                    day.
                </p>
                <Link
                    href="/"
                    className="mt-8 inline-block text-sm font-medium text-primary hover:underline"
                >
                    Back to the home page
                </Link>
            </section>
        </>
    );
}
