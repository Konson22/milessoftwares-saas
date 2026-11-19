import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@miles/ui/components/ui/button';
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
}: {
    url: string;
    label: string;
    reason?: string;
    reasonOptional?: boolean;
    variant?: 'default' | 'outline' | 'destructive' | 'secondary';
    confirm?: string;
    method?: 'post' | 'delete';
}) {
    const [open, setOpen] = useState(false);
    const [text, setText] = useState('');
    const [busy, setBusy] = useState(false);
    const errors = usePage().props.errors as Record<string, string>;

    const send = (data: Record<string, string>) => {
        setBusy(true);
        router.visit(url, {
            method,
            data,
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                setText('');
            },
            onFinish: () => setBusy(false),
        });
    };

    if (reason && open) {
        return (
            <div className="grid w-full gap-2 rounded-md border p-3">
                <TextArea
                    label={reason}
                    value={text}
                    onChange={setText}
                    error={errors.reason}
                    rows={2}
                />
                <div className="flex gap-2">
                    <Button
                        size="sm"
                        variant={variant}
                        disabled={
                            busy || (!reasonOptional && text.trim() === '')
                        }
                        onClick={() => send({ reason: text })}
                    >
                        {label}
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setOpen(false)}
                    >
                        Back
                    </Button>
                </div>
            </div>
        );
    }

    return (
        <Button
            size="sm"
            variant={variant}
            disabled={busy}
            onClick={() => {
                if (reason) {
                    setOpen(true);
                } else if (!confirm || window.confirm(confirm)) {
                    send({});
                }
            }}
        >
            {label}
        </Button>
    );
}
