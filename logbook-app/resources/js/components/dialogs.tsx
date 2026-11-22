import { Modal, useModalStack } from '@inertiaui/modal-react';
import type { ReactNode } from 'react';
import { useId, useState } from 'react';
import { Button } from '@miles/ui/components/ui/button';

/**
 * Every dialog in Logbook goes through Inertia UI Modal: local modals
 * (named, opened with `#name`), styled once in app.css and app.tsx.
 * Nothing uses window.confirm.
 */

type ConfirmOptions = {
    title: string;
    message?: ReactNode;
    confirmLabel?: string;
    destructive?: boolean;
};

const CONFIRM = 'logbook-confirm';
let pending: {
    options: ConfirmOptions;
    resolve: (ok: boolean) => void;
} | null = null;
let show: ((options: ConfirmOptions) => void) | null = null;

/**
 * Asks before something that cannot be taken back. Resolves true on the
 * confirm button, false when closed any other way.
 */
export function useConfirm(): (options: ConfirmOptions) => Promise<boolean> {
    const { visitModal } = useModalStack();

    return (options) =>
        new Promise<boolean>((resolve) => {
            pending?.resolve(false);
            pending = { options, resolve };
            show?.(options);
            void visitModal(`#${CONFIRM}`);
        });
}

/** The one confirmation dialog, mounted by the layout. */
export function ConfirmHost() {
    const [options, setOptions] = useState<ConfirmOptions | null>(null);
    show = setOptions;

    const settle = (ok: boolean) => {
        pending?.resolve(ok);
        pending = null;
    };

    return (
        <Modal name={CONFIRM} maxWidth="md" onClose={() => settle(false)}>
            {({ close }: { close: () => void }) => (
                <div className="grid gap-4">
                    <h2 className="text-lg font-semibold">{options?.title}</h2>
                    {options?.message && (
                        <div className="text-sm text-muted-foreground">
                            {options.message}
                        </div>
                    )}
                    <div className="flex justify-end gap-2">
                        <Button variant="outline" onClick={close}>
                            Cancel
                        </Button>
                        <Button
                            variant={
                                options?.destructive ? 'destructive' : 'default'
                            }
                            onClick={() => {
                                settle(true);
                                close();
                            }}
                        >
                            {options?.confirmLabel ?? 'Continue'}
                        </Button>
                    </div>
                </div>
            )}
        </Modal>
    );
}

/**
 * A button that opens its own modal: forms such as giving a task or
 * reporting an exception. `children` gets `close` for when it is done.
 */
export function ModalButton({
    label,
    title,
    children,
    variant = 'default',
    size = 'sm',
    maxWidth = '2xl',
}: {
    label: string;
    title?: string;
    children: (close: () => void) => ReactNode;
    variant?: 'default' | 'outline' | 'destructive' | 'secondary';
    size?: 'sm' | 'default';
    maxWidth?: 'sm' | 'md' | 'lg' | 'xl' | '2xl' | '3xl' | '4xl' | '5xl';
}) {
    const { visitModal } = useModalStack();
    const name = `modal-${useId().replace(/:/g, '')}`;

    return (
        <>
            <Button
                size={size}
                variant={variant}
                onClick={() => void visitModal(`#${name}`)}
            >
                {label}
            </Button>
            <Modal name={name} maxWidth={maxWidth}>
                {({ close }: { close: () => void }) => (
                    <div className="grid gap-4">
                        <h2 className="text-lg font-semibold">
                            {title ?? label}
                        </h2>
                        {children(close)}
                    </div>
                )}
            </Modal>
        </>
    );
}
