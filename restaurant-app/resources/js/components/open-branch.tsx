import { SquareArrowOutUpRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@miles/ui/components/ui/button';
import { home } from '@/routes';

/**
 * A branch's workspace, opened from management in a new tab: a plain link,
 * so management stays as it is in its own tab, and the new tab takes its
 * branch from its address alone.
 */
export function OpenBranch({
    branchId,
    name,
    button = false,
    children,
}: {
    branchId: string;
    name: string;
    button?: boolean;
    children?: ReactNode;
}) {
    const link = (
        <a
            href={home.url({ branch: branchId })}
            target="_blank"
            rel="noopener noreferrer"
            aria-label={`Open ${name} in a new tab`}
            className={
                button ? undefined : 'font-medium text-primary hover:underline'
            }
        >
            {children ?? name}
            {button && (
                <SquareArrowOutUpRight className="size-3.5" aria-hidden />
            )}
        </a>
    );

    return button ? (
        <Button asChild size="sm" variant="outline">
            {link}
        </Button>
    ) : (
        link
    );
}
