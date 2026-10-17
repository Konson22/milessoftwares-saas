import { Form, Head, Link } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Label } from '@miles/ui/components/ui/label';
import { index, store } from '@/routes/transfers';

type Props = {
    branches: { id: string; name: string }[];
    batches: { id: string; label: string; onHand: number }[];
};

const selectClass = 'h-9 w-full rounded-md border bg-transparent px-3 text-sm';

/**
 * Sending stock to another branch, batch by batch.
 */
export default function SendStock({ branches, batches }: Props) {
    const [lines, setLines] = useState<number[]>([0]);
    const [nextKey, setNextKey] = useState(1);
    // The batch chosen on each line (by key), for its quantity on hand.
    const [chosen, setChosen] = useState<Record<number, string>>({});
    const batchOf = (key: number) =>
        batches.find((b) => b.id === (chosen[key] ?? batches[0]?.id));

    return (
        <>
            <Head title="Send stock" />
            <PageHeader title="Send stock to another branch" />

            <Panel className="max-w-3xl">
                <PanelBody>
                    {branches.length === 0 || batches.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {branches.length === 0
                                ? 'There is no other branch to send to.'
                                : 'Nothing in this branch can be sent: no batch in date, off hold and in stock.'}
                        </p>
                    ) : (
                        <Form {...store.form()} className="grid gap-4">
                            {({ errors, processing }) => (
                                <>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="to_branch_id">
                                                Send to
                                            </Label>
                                            <select
                                                id="to_branch_id"
                                                name="to_branch_id"
                                                className={selectClass}
                                            >
                                                {branches.map((branch) => (
                                                    <option
                                                        key={branch.id}
                                                        value={branch.id}
                                                    >
                                                        {branch.name}
                                                    </option>
                                                ))}
                                            </select>
                                            {errors.to_branch_id && (
                                                <p className="text-sm text-destructive">
                                                    {errors.to_branch_id}
                                                </p>
                                            )}
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="note">Note</Label>
                                            <Input
                                                id="note"
                                                name="note"
                                                maxLength={255}
                                                placeholder="Driver, reason…"
                                            />
                                        </div>
                                    </div>

                                    <fieldset className="grid gap-3">
                                        <legend className="mb-1 text-sm font-medium">
                                            What goes
                                        </legend>
                                        {lines.map((key, i) => {
                                            const error = (field: string) =>
                                                errors[`lines.${i}.${field}`];
                                            const batch = batchOf(key);

                                            return (
                                                <div
                                                    key={key}
                                                    className="grid items-start gap-2 sm:grid-cols-[1fr_7rem_auto]"
                                                >
                                                    <div>
                                                        <select
                                                            name={`lines[${i}][batch_id]`}
                                                            aria-label={`Batch, line ${i + 1}`}
                                                            className={
                                                                selectClass
                                                            }
                                                            onChange={(e) =>
                                                                setChosen({
                                                                    ...chosen,
                                                                    [key]: e
                                                                        .target
                                                                        .value,
                                                                })
                                                            }
                                                        >
                                                            {batches.map(
                                                                (option) => (
                                                                    <option
                                                                        key={
                                                                            option.id
                                                                        }
                                                                        value={
                                                                            option.id
                                                                        }
                                                                    >
                                                                        {
                                                                            option.label
                                                                        }{' '}
                                                                        (
                                                                        {
                                                                            option.onHand
                                                                        }{' '}
                                                                        on hand)
                                                                    </option>
                                                                ),
                                                            )}
                                                        </select>
                                                        {error('batch_id') && (
                                                            <p className="text-xs text-destructive">
                                                                {error(
                                                                    'batch_id',
                                                                )}
                                                            </p>
                                                        )}
                                                    </div>
                                                    <div>
                                                        <Input
                                                            name={`lines[${i}][quantity]`}
                                                            type="number"
                                                            min={1}
                                                            max={batch?.onHand}
                                                            required
                                                            placeholder="Qty"
                                                            aria-label={`Quantity, line ${i + 1}`}
                                                        />
                                                        {error('quantity') && (
                                                            <p className="text-xs text-destructive">
                                                                {error(
                                                                    'quantity',
                                                                )}
                                                            </p>
                                                        )}
                                                    </div>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label={`Remove line ${i + 1}`}
                                                        disabled={
                                                            lines.length === 1
                                                        }
                                                        onClick={() =>
                                                            setLines(
                                                                lines.filter(
                                                                    (k) =>
                                                                        k !==
                                                                        key,
                                                                ),
                                                            )
                                                        }
                                                    >
                                                        <Trash2
                                                            className="size-4"
                                                            aria-hidden
                                                        />
                                                    </Button>
                                                </div>
                                            );
                                        })}
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            className="justify-self-start"
                                            onClick={() => {
                                                setLines([...lines, nextKey]);
                                                setNextKey(nextKey + 1);
                                            }}
                                        >
                                            <Plus
                                                className="size-4"
                                                aria-hidden
                                            />
                                            Add batch
                                        </Button>
                                        {errors.lines && (
                                            <p className="text-sm text-destructive">
                                                {errors.lines}
                                            </p>
                                        )}
                                    </fieldset>

                                    <div className="flex gap-3">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Send
                                        </Button>
                                        <Button variant="ghost" asChild>
                                            <Link
                                                href={index({
                                                    query: {
                                                        direction: 'outgoing',
                                                    },
                                                })}
                                            >
                                                Cancel
                                            </Link>
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    )}
                </PanelBody>
            </Panel>
        </>
    );
}
