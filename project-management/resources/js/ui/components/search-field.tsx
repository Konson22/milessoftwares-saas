import { Search } from 'lucide-react';
import type { ComponentProps } from 'react';
import { Input } from './ui/input';
import { cn } from '../lib/utils';

/**
 * A text input with a search icon, for toolbars.
 */
export function SearchField({ className, ...props }: ComponentProps<'input'>) {
    return (
        <div className={cn('relative w-full max-w-xs', className)}>
            <Search
                className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                aria-hidden
            />
            <Input type="search" className="pl-8" {...props} />
        </div>
    );
}
