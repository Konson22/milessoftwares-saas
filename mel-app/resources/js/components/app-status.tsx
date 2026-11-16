import { RefreshCw, WifiOff } from 'lucide-react';
import { useOnline, useUpdate } from '@/lib/pwa';

/**
 * The connection and a waiting new version, above every page. Offline,
 * nothing is saved and nothing is kept in the browser: the banner says so.
 */
export function AppStatus() {
    const online = useOnline();
    const update = useUpdate();

    if (online && !update.ready) {
        return null;
    }

    return (
        <div className="space-y-2 px-4 pt-3 sm:px-6">
            {!online && (
                <div
                    role="status"
                    className="flex items-start gap-2 border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100"
                >
                    <WifiOff className="mt-0.5 size-4 shrink-0" aria-hidden />
                    <span>
                        You are offline. Changes cannot be saved until the
                        connection returns, and nothing is kept in this browser
                        meanwhile.
                    </span>
                </div>
            )}
            {update.ready && (
                <div
                    role="status"
                    className="flex flex-wrap items-center gap-2 border border-sky-300 bg-sky-50 px-3 py-2 text-sm text-sky-900 dark:border-sky-700 dark:bg-sky-950 dark:text-sky-100"
                >
                    <RefreshCw className="size-4 shrink-0" aria-hidden />
                    <span className="flex-1">
                        A new version of M&amp;E is ready.
                    </span>
                    <button
                        type="button"
                        onClick={update.apply}
                        className="font-semibold underline underline-offset-2"
                    >
                        Reload to update
                    </button>
                </div>
            )}
        </div>
    );
}
