import { Link } from '@inertiajs/react';
import AppLogo from '@/shared/components/app-logo';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/shared/types';
import { Accent, HeroBackdrop } from '@/sites/public/components/page-hero';
import { display } from '@/sites/public/components/styles';

function BrandLink({
    className,
    inverted = false,
}: {
    className?: string;
    inverted?: boolean;
}) {
    return (
        <Link
            href={home()}
            aria-label="Miles Global Softwares home"
            className={`flex items-center ${className ?? ''}`}
        >
            <AppLogo inverted={inverted} className="h-11 w-auto sm:h-12" />
        </Link>
    );
}

export default function AuthSplitLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const year = new Date().getFullYear();

    return (
        <div className="site-public grid min-h-svh font-public text-site-ink lg:grid-cols-[3fr_2fr]">
            <aside className="site-dark relative isolate hidden min-h-svh flex-col justify-between overflow-hidden bg-site-navy p-10 text-slate-300 lg:flex xl:p-14">
                <HeroBackdrop />

                <BrandLink inverted className="relative z-10" />

                <div className="reveal-up relative z-10 max-w-md space-y-5">
                    <h2
                        className={`text-3xl leading-tight xl:text-4xl ${display}`}
                    >
                        Run your whole business <Accent>in one place</Accent>
                    </h2>
                    <p className="text-base text-pretty text-slate-300 xl:text-lg">
                        Pharmacy, retail, finance and HR, connected under one
                        Miles Global Softwares account.
                    </p>
                </div>

                <p className="relative z-10 text-sm text-slate-500">
                    © {year} Miles Global Link Co. Ltd
                </p>
            </aside>

            <div className="relative flex flex-col justify-center bg-site-soft px-6 py-8 sm:px-10">
                <div className="reveal-up relative z-10 mx-auto flex w-full max-w-[400px] flex-col gap-6">
                    <BrandLink className="lg:hidden" />

                    <div className="space-y-1">
                        <h1 className={`text-2xl sm:text-[1.75rem] ${display}`}>
                            {title}
                        </h1>
                        {description && (
                            <p className="text-sm text-pretty text-slate-600 sm:text-base">
                                {description}
                            </p>
                        )}
                    </div>

                    {children}
                </div>
            </div>
        </div>
    );
}
