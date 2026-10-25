import { Plus } from 'lucide-react';

export type Question = { question: string; answer: string };

/** What people ask before choosing Miles, on the home page. */
export function faq(trialDays: number): Question[] {
    return [
        {
            question: 'Do I need to install anything?',
            answer: 'Not for the cloud version: it runs in the browser on any computer, tablet or phone. Pharmacy ERP and Retail ERP also have desktop apps, and Pharmacy ERP has a mobile app.',
        },
        {
            question: 'Can I try it before paying?',
            answer: `Yes. Choose an application and create your workspace, and you can use it free for ${trialDays} days. No payment details are needed to start.`,
        },
        {
            question: 'Can I use it across several branches?',
            answer: 'Yes. Add branches, give staff roles per branch, move stock between them and report on each one.',
        },
        {
            question: 'Will my accountant be able to use it?',
            answer: 'Yes. Finance is a full double-entry ledger with trial balance, profit and loss, balance sheet, aged reports, bank reconciliation and tax returns, all exportable to CSV.',
        },
        {
            question: 'Can I move from spreadsheets?',
            answer: 'Yes. Opening stock and opening balances are loaded when you set up, so you start from where you are.',
        },
        {
            question: 'Does it work without internet?',
            answer: 'For the products that have them, yes. The Pharmacy and Retail desktop apps and the Pharmacy mobile app keep selling offline and sync when the connection is back. For a pharmacy with no internet at all, there is a standalone offline edition. The web versions need internet.',
        },
        {
            question: 'Can I pay once instead of every month?',
            answer: 'Where a standalone offline edition is offered, yes: today that is Pharmacy ERP for a single branch on one Windows computer, bought once with no subscription. Contact us for the price.',
        },
        {
            question: 'Do you build custom software?',
            answer: 'Yes. We build custom ERPs, web, desktop and mobile applications and integrations, and we customize our own ERPs. Tell us what you need and we send a quotation.',
        },
        {
            question: 'Who can see my data?',
            answer: 'Only the people you invite, with the roles you give them. Our support team needs a time-limited grant to look, and you are told when one is approved.',
        },
    ];
}

/** Questions about trials and billing, on the pricing page. */
export function pricingFaq(trialDays: number): Question[] {
    return [
        {
            question: 'What happens after the free trial?',
            answer: `Your trial runs for ${trialDays} days. Choose a plan to keep going; everything you set up stays in place.`,
        },
        {
            question: 'Is Finance included with an ERP?',
            answer: 'Not by default. Every ERP works on its own, with its own sales, stock and till reports. Finance & Accounting is our advanced accounting application: add it when you want full books, and your sales and purchases post to it automatically.',
        },
        {
            question: 'Can I run a pharmacy and a shop together?',
            answer: 'Yes. Both run in one workspace and each reports on its own. Add Finance & Accounting and they share one set of books.',
        },
        {
            question: 'What does "Early access" mean?',
            answer: 'The product is live with selected customers while we finish it with them. You can try it, and we may still change it. Talk to us to join.',
        },
        {
            question: 'Can I add applications later?',
            answer: 'Yes. New applications join the same workspace and sign-in. Your team, branches and roles are already set up.',
        },
        {
            question: 'Is there a one-time purchase option?',
            answer: 'For Pharmacy ERP, yes: a standalone offline edition for a single branch on one Windows computer, bought once with no subscription and without cloud sync. Contact us for the price.',
        },
        {
            question: 'Do the desktop and mobile apps cost extra?',
            answer: 'No. Where a product has desktop or mobile apps that sync with the cloud, they are included with its cloud plan.',
        },
        {
            question: 'We need something different. Can you help?',
            answer: 'Larger organizations and special arrangements are billed by agreement. Contact us and we will put together a quote.',
        },
    ];
}

/** Open-one-at-a-time list of white cards. */
export default function FaqList({ items }: { items: Question[] }) {
    return (
        <div className="space-y-3">
            {items.map(({ question, answer }) => (
                <details
                    key={question}
                    name="faq"
                    className="group rounded-2xl border border-site-line bg-white px-5 transition-colors duration-200 open:border-site-accent/40 sm:px-6"
                >
                    <summary className="flex cursor-pointer list-none items-center justify-between gap-4 py-4 font-semibold sm:gap-6 sm:py-5 [&::-webkit-details-marker]:hidden">
                        {question}
                        <span className="inline-flex size-8 shrink-0 items-center justify-center rounded-full bg-site-accent/10 text-site-accent-ink transition-colors group-open:bg-site-accent-ink group-open:text-white">
                            <Plus
                                className="size-4 transition-transform duration-200 group-open:rotate-45"
                                aria-hidden
                            />
                        </span>
                    </summary>
                    <p className="pr-10 pb-5 text-sm leading-relaxed text-slate-600 sm:pr-12 sm:text-base">
                        {answer}
                    </p>
                </details>
            ))}
        </div>
    );
}
