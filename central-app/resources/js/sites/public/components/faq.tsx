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
            answer: 'Yes. Add another application whenever you need it. Your team, branches and roles are already set up, so you do not start from scratch.',
        },
        {
            question:
                'Do my people need a separate account for each application?',
            answer: 'No. Everyone uses the same sign-in, and you decide which applications and which parts of them each person can use.',
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
                    className="group rounded-2xl border border-site-line bg-white px-5 shadow-[0_8px_30px_-18px_rgba(22,24,55,0.2)] transition-colors duration-200 open:border-site-accent/40 sm:px-6"
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
