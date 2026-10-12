import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/shared/lib/utils';

type PageHeaderProps = {
    title: string;
    description?: string;
    icon?: LucideIcon;
    actions?: ReactNode;
    className?: string;
};

export function PageHeader({
    title,
    description,
    icon: Icon,
    actions,
    className,
}: PageHeaderProps) {
    return (
        <div
            className={cn(
                'flex flex-wrap items-start justify-between gap-3',
                className,
            )}
        >
            <div className="min-w-0 space-y-1">
                <div className="flex items-center gap-2">
                    {Icon && (
                        <Icon
                            className="size-5 shrink-0 text-primary"
                            aria-hidden
                        />
                    )}
                    <h1 className="truncate text-xl font-semibold tracking-tight">
                        {title}
                    </h1>
                </div>
                {description && (
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {actions && (
                <div className="flex shrink-0 flex-wrap items-center gap-2">
                    {actions}
                </div>
            )}
        </div>
    );
}
