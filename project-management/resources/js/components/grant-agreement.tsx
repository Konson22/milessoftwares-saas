import { Form } from "@inertiajs/react";
import { useState } from "react";
import { Panel, PanelBody, PanelHeader } from "@miles/ui/components/panel";
import { StatusBadge } from "@miles/ui/components/status-badge";
import { Button } from "@miles/ui/components/ui/button";
import { Field } from "@/components/field";
import { formatDate } from "@/lib/status";

/**
 * The steps of a grant payment agreed between two organizations on Tatook
 * (App\Actions\GrantAgreements). "Sent" is the donor's own record of a
 * transfer, never a confirmation from Finance.
 */
export const PAYMENT_STEPS: Record<
    string,
    {
        label: string;
        tone: "neutral" | "info" | "success" | "warning" | "danger";
    }
> = {
    planned: { label: "Planned", tone: "neutral" },
    requested: { label: "Requested", tone: "info" },
    approved: { label: "Approved", tone: "info" },
    rejected: { label: "Rejected", tone: "danger" },
    sent: { label: "Sent by donor", tone: "warning" },
    received: { label: "Received", tone: "success" },
    disputed: { label: "Disputed", tone: "danger" },
};

export function PaymentStep({ status }: { status: string }) {
    const step = PAYMENT_STEPS[status] ?? { label: status, tone: "neutral" };

    return <StatusBadge tone={step.tone}>{step.label}</StatusBadge>;
}

export const AGREEMENT_STEPS: Record<string, string> = {
    proposed: "Waiting for the donor",
    approved: "Approved by the donor",
    declined: "Declined by the donor",
};

const textareaClass =
    "w-full rounded-md border bg-transparent px-3 py-2 text-sm";

/** The donor's decision on terms proposed to it. */
export function AgreementDecision({
    action,
    proposed,
}: {
    action: string;
    proposed: string;
}) {
    return (
        <Form
            action={action}
            method="post"
            options={{ preserveScroll: true }}
            className="grid gap-3"
        >
            {({ errors, processing }) => (
                <>
                    <p className="text-sm">
                        They propose {proposed}. Approving makes it the ceiling
                        for the grant and the payments they can request.
                    </p>
                    <Field id="agreement-note" label="Note" error={errors.note}>
                        <textarea
                            id="agreement-note"
                            name="note"
                            rows={2}
                            maxLength={2000}
                            className={textareaClass}
                        />
                    </Field>
                    {errors.agreement && (
                        <p className="text-sm text-destructive">
                            {errors.agreement}
                        </p>
                    )}
                    <span className="flex gap-2">
                        <Button
                            type="submit"
                            name="decision"
                            value="approve"
                            size="sm"
                            disabled={processing}
                        >
                            Approve the terms
                        </Button>
                        <Button
                            type="submit"
                            name="decision"
                            value="decline"
                            size="sm"
                            variant="outline"
                            disabled={processing}
                        >
                            Decline (say why)
                        </Button>
                    </span>
                </>
            )}
        </Form>
    );
}

/** The donor's actions on one payment: decide a request, record a transfer. */
export function DonorPaymentActions({
    base,
    payment,
}: {
    base: string;
    payment: { id: string; status: string };
}) {
    const [rejecting, setRejecting] = useState(false);
    const today = new Date().toISOString().slice(0, 10);

    if (payment.status === "requested") {
        return (
            <Form
                action={`${base}/payments/${payment.id}/decision`}
                method="post"
                options={{ preserveScroll: true }}
                className="mt-2 grid gap-2"
            >
                {({ errors, processing }) => (
                    <>
                        {rejecting && (
                            <Field
                                id={`reject-${payment.id}`}
                                label="Why not"
                                error={errors.note}
                            >
                                <textarea
                                    id={`reject-${payment.id}`}
                                    name="note"
                                    rows={2}
                                    required
                                    maxLength={2000}
                                    className={textareaClass}
                                />
                            </Field>
                        )}
                        {errors.disbursement && (
                            <p className="text-destructive">
                                {errors.disbursement}
                            </p>
                        )}
                        <span className="flex gap-2">
                            {!rejecting && (
                                <Button
                                    type="submit"
                                    name="decision"
                                    value="approve"
                                    size="sm"
                                    disabled={processing}
                                >
                                    Approve
                                </Button>
                            )}
                            {rejecting ? (
                                <Button
                                    type="submit"
                                    name="decision"
                                    value="reject"
                                    size="sm"
                                    variant="outline"
                                    disabled={processing}
                                >
                                    Reject
                                </Button>
                            ) : (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => setRejecting(true)}
                                >
                                    Reject…
                                </Button>
                            )}
                        </span>
                    </>
                )}
            </Form>
        );
    }

    if (payment.status === "approved" || payment.status === "disputed") {
        return (
            <Form
                action={`${base}/payments/${payment.id}/sent`}
                method="post"
                options={{ preserveScroll: true }}
                className="mt-2 flex flex-wrap items-end gap-2"
            >
                {({ errors, processing }) => (
                    <>
                        <Field
                            id={`sent-on-${payment.id}`}
                            label="Sent on"
                            error={errors.sent_on}
                        >
                            <input
                                id={`sent-on-${payment.id}`}
                                name="sent_on"
                                type="date"
                                max={today}
                                defaultValue={today}
                                required
                                className="rounded-md border bg-transparent px-2 py-1 text-sm"
                            />
                        </Field>
                        <Field
                            id={`sent-ref-${payment.id}`}
                            label="Transfer reference"
                            error={errors.reference}
                        >
                            <input
                                id={`sent-ref-${payment.id}`}
                                name="reference"
                                required
                                maxLength={100}
                                className="rounded-md border bg-transparent px-2 py-1 text-sm"
                            />
                        </Field>
                        <Button type="submit" size="sm" disabled={processing}>
                            Record the transfer
                        </Button>
                        <p className="w-full text-xs text-muted-foreground">
                            Your own record of a transfer you made. They confirm
                            it once it reaches their account.
                        </p>
                    </>
                )}
            </Form>
        );
    }

    return null;
}

/** What one payment's history says, in a line. */
export function paymentDetail(payment: {
    status: string;
    receivedOn: string | null;
    sentOn?: string | null;
    sentReference?: string | null;
    decisionNote?: string | null;
    disputeNote?: string | null;
}): string | null {
    if (payment.receivedOn) {
        return `Received ${formatDate(payment.receivedOn)}`;
    }
    if (payment.status === "sent" && payment.sentOn) {
        return `Sent ${formatDate(payment.sentOn)}${payment.sentReference ? `, reference ${payment.sentReference}` : ""}`;
    }
    if (payment.status === "disputed") {
        return `Disputed: ${payment.disputeNote ?? ""}`;
    }
    if (payment.status === "rejected") {
        return `Rejected: ${payment.decisionNote ?? ""}`;
    }

    return null;
}

/** The recipient's view of the terms agreed with its donor. */
export function RecipientAgreement({
    agreement,
    proposeUrl,
    money,
    canManage,
}: {
    agreement: {
        donor: string;
        status: string | null;
        approvedMinor: number | null;
        committedMinor: number;
        decidedAt: string | null;
        note: string | null;
        canPropose: boolean;
        changed: boolean;
    };
    proposeUrl: string;
    money: (minor: number) => string;
    canManage: boolean;
}) {
    return (
        <Panel>
            <PanelHeader
                title={`Agreement with ${agreement.donor}`}
                description={`${agreement.donor} is on Tatook: it approves the terms, then each payment you request. Recording a payment as received stays yours to do.`}
            />
            <PanelBody className="grid gap-2 text-sm">
                <p>
                    {agreement.status === null
                        ? "Not proposed yet."
                        : AGREEMENT_STEPS[agreement.status]}
                    {agreement.approvedMinor !== null &&
                        ` · ${money(agreement.approvedMinor)} approved, ${money(agreement.committedMinor)} requested so far`}
                </p>
                {agreement.note && (
                    <p className="text-muted-foreground">“{agreement.note}”</p>
                )}
                {agreement.changed && (
                    <p className="text-destructive">
                        The grant amount differs from what the donor approved.
                        Propose the change to them.
                    </p>
                )}
                {canManage && agreement.canPropose && (
                    <Form
                        action={proposeUrl}
                        method="post"
                        options={{ preserveScroll: true }}
                        className="grid gap-2"
                    >
                        {({ errors, processing }) => (
                            <>
                                <Field
                                    id="propose-note"
                                    label="Note to the donor"
                                    error={errors.note ?? errors.agreement}
                                >
                                    <textarea
                                        id="propose-note"
                                        name="note"
                                        rows={2}
                                        maxLength={2000}
                                        className={textareaClass}
                                    />
                                </Field>
                                <div>
                                    <Button
                                        type="submit"
                                        size="sm"
                                        disabled={processing}
                                    >
                                        {agreement.status === null
                                            ? "Send the terms for approval"
                                            : "Propose the terms again"}
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                )}
            </PanelBody>
        </Panel>
    );
}
