import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@miles/ui/components/ui/button';
import { ModalButton, useConfirm } from '@/components/dialogs';
import { TextArea } from '@/components/fields';

/**
 * A button that posts, optionally after asking for a reason. Errors show
 * under the button.
 */
export function ActionButton({
    url,
    label,
    reason,
    reasonOptional,
    variant = 'default',
    confirm,
    method = 'post',
    data = {},
}: {
    url: string;
    label: string;
    reason?: string;
    reasonOptional?: boolean;
    variant?: 'default' | 'outline' | 'destructive' | 'secondary';
    confirm?: string;
    method?: 'post' | 'delete';
    /** Sent with the action, such as the revision the page shows. */
    data?: Record<string, string | number>;
}) {
    const [text, setText] = useState('');
    const [busy, setBusy] = useState(false);
    const errors = usePage().props.errors as Record<string, string>;
    const ask = useConfirm();

    const send = (fields: Record<string, string>, after?: () => void) => {
        setBusy(true);
        router.visit(url, {
            method,
            data: { ...data, ...fields },
            preserveScroll: true,
            onSuccess: () => {
                setText('');
                after?.();
            },
            onFinish: () => setBusy(false),
        });
    };

    if (reason) {
        return (
            <ModalButton label={label} variant={variant} maxWidth="lg">
                {(close) => (
                    <div className="grid gap-3">
                        <TextArea
                            label={reason}
                            value={text}
                            onChange={setText}
                            error={errors.reason}
                            rows={3}
                        />
                        <div className="flex justify-end gap-2">
                            <Button variant="outline" onClick={close}>
                                Back
                            </Button>
                            <Button
                                variant={
                                    variant === 'outline' ? 'default' : variant
                                }
                                disabled={
                                    busy ||
                                    (!reasonOptional && text.trim() === '')
                                }
                                onClick={() => send({ reason: text }, close)}
                            >
                                {label}
                            </Button>
                        </div>
                    </div>
                )}
            </ModalButton>
        );
    }

    return (
        <Button
            size="sm"
            variant={variant}
            disabled={busy}
            onClick={async () => {
                if (
                    !confirm ||
                    (await ask({
                        title: confirm,
                        confirmLabel: label,
                        destructive: variant === 'destructive',
                    }))
                ) {
                    send({});
                }
            }}
        >
            {label}
        </Button>
    );
}
