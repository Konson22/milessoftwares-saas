import { Head, usePage } from '@inertiajs/react';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

type Props = {
    settings: { currency: string; timezone: string } | null;
    recordCount: number;
};

export default function Home({ settings, recordCount }: Props) {
    const { product, workspace, user } = usePage().props;

    return (
        <>
            <Head title="Home" />
            <h1 className="text-2xl font-semibold">
                {product.name} for {workspace?.name}
            </h1>
            <p className="mt-1 text-muted-foreground">Welcome, {user?.name}.</p>

            <div className="mt-8 grid gap-4 sm:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle>Workspace settings</CardTitle>
                        <CardDescription>
                            Set up when the platform provisioned this workspace.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="text-sm">
                        {settings ? (
                            <dl className="grid grid-cols-2 gap-2">
                                <dt className="text-muted-foreground">
                                    Currency
                                </dt>
                                <dd>{settings.currency}</dd>
                                <dt className="text-muted-foreground">
                                    Time zone
                                </dt>
                                <dd>{settings.timezone}</dd>
                            </dl>
                        ) : (
                            <p>Not provisioned yet.</p>
                        )}
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>Records</CardTitle>
                        <CardDescription>In this workspace.</CardDescription>
                    </CardHeader>
                    <CardContent className="text-3xl font-semibold">
                        {recordCount}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
