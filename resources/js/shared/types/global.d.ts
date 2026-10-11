import type { Auth } from '@/shared/types/auth';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            platform: {
                host: string;
                isAuthHost: boolean;
                accountUrl: string;
                logoutUrl: string;
            };
            tenant: { displayName: string } | null;
            abilities: {
                manageStructure: boolean;
                manageAccess: boolean;
            } | null;
            staff: { permissions: string[] } | null;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
