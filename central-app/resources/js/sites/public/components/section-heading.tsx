import type { ReactNode } from 'react';
import { display, eyebrow } from '@/sites/public/components/styles';

/**
 * Pill label, h2 and an optional lead, as every section of the public
 * site opens: centred, or left-aligned beside other content.
 */
export default function SectionHeading({
    id,
    label,
    title,
    lead,
    align = 'center',
}: {
    id: string;
    label: string;
    title: ReactNode;
    lead?: ReactNode;
    align?: 'center' | 'left';
}) {
    return (
        <div
            className={
                align === 'center'
                    ? 'mx-auto flex max-w-3xl flex-col items-center text-center'
                    : ''
            }
        >
            <p className={eyebrow}>{label}</p>
            <h2
                id={id}
                className={`mt-5 text-3xl leading-tight sm:text-5xl ${display}`}
            >
                {title}
            </h2>
            {lead && (
                <p className="mt-5 max-w-2xl text-lg text-slate-600">{lead}</p>
            )}
        </div>
    );
}
