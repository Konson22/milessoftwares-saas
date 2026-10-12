import type { LucideIcon } from 'lucide-react';
import { Search } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/shared/lib/utils';

type EmptyStateProps = {
    title: string;
    description?: string;
    icon?: LucideIcon;
    action?: ReactNode;
    className?: string;
};

export function EmptyState({
    title,
    description,
    icon: Icon = Search,
    action,
    className,
}: EmptyStateProps) {
    return (
        <div
            className={cn(
                'flex flex-col items-center justify-center gap-2 px-6 py-12 text-center',
                className,
            )}
        >
            <Icon
                className="size-10 stroke-[1.25] text-muted-foreground/50"
                aria-hidden
            />
            <h3 className="text-sm font-medium text-foreground">{title}</h3>
            {description && (
                <p className="max-w-sm text-xs text-muted-foreground">
                    {description}
                </p>
            )}
            {action && <div className="mt-2">{action}</div>}
        </div>
    );
}
