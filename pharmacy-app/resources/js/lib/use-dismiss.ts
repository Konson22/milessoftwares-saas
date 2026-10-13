import { useEffect } from 'react';
import type { RefObject } from 'react';

/**
 * Closes a popup on a click outside it or on Escape, while it is open.
 */
export function useDismiss(
    ref: RefObject<HTMLElement | null>,
    open: boolean,
    close: () => void,
): void {
    useEffect(() => {
        if (!open) {
            return;
        }

        const onPointer = (event: PointerEvent) => {
            if (
                ref.current &&
                event.target instanceof Node &&
                !ref.current.contains(event.target)
            ) {
                close();
            }
        };
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                close();
            }
        };

        document.addEventListener('pointerdown', onPointer);
        document.addEventListener('keydown', onKey);

        return () => {
            document.removeEventListener('pointerdown', onPointer);
            document.removeEventListener('keydown', onKey);
        };
    }, [ref, open, close]);
}
