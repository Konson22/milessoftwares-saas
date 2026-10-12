import type { ReactNode } from 'react';
import { cn } from '@/shared/lib/utils';

type PageToolbarProps = {
    left?: ReactNode;
    right?: ReactNode;
    className?: string;
};

export function PageToolbar({ left, right, className }: PageToolbarProps) {
    return (
        <div
            className={cn(
                'flex flex-wrap items-center justify-between gap-2 border-b pb-3',
                className,
            )}
        >
            <div className="flex min-w-0 flex-wrap items-center gap-2">
                {left}
            </div>
            {right && (
                <div className="flex min-w-0 flex-wrap items-center gap-2">
                    {right}
                </div>
            )}
        </div>
    );
}
