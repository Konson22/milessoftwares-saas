import { Plus } from 'lucide-react';

/** Questions asked about trials and billing, on the home and pricing pages. */
export function faq(trialDays: number) {
    return [
        {
            question: 'Can I try an application before paying?',
            answer: `Yes. Choose an application and create your workspace, and you can use it free for ${trialDays} days. No payment details are needed to start.`,
        },
        {
            question: 'Can I add more applications later?',
            answer: 'Yes. Your organization keeps one workspace and one subscription, and new applications use the team, branches and roles you have already set up.',
        },
        {
            question:
                'Do my people need a separate account for each application?',
            answer: 'No. Everyone signs in once, and you decide which applications and which parts of them each person can use.',
        },
        {
            question: 'We need something different. Can you help?',
            answer: 'Larger organizations and special arrangements are billed by agreement. Contact us and we will put together a quote.',
        },
    ];
}

/** Open-one-at-a-time list of white cards. */
export default function FaqList({ trialDays }: { trialDays: number }) {
    return (
        <div className="space-y-3">
            {faq(trialDays).map(({ question, answer }) => (
                <details
                    key={question}
                    name="faq"
                    className="group rounded-2xl border border-site-line bg-white px-6 transition-shadow open:shadow-lg open:shadow-site-ink/5"
                >
                    <summary className="flex cursor-pointer list-none items-center justify-between gap-6 py-5 font-semibold [&::-webkit-details-marker]:hidden">
                        {question}
                        <span className="inline-flex size-8 shrink-0 items-center justify-center rounded-full bg-brand-blue/10 text-brand-blue transition-colors group-open:bg-brand-blue group-open:text-white">
                            <Plus
                                className="size-4 transition-transform group-open:rotate-45"
                                aria-hidden
                            />
                        </span>
                    </summary>
                    <p className="pr-12 pb-5 text-slate-600">{answer}</p>
                </details>
            ))}
        </div>
    );
}
