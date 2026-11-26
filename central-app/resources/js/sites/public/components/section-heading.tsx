import type { ReactNode } from 'react';
import { display, type } from '@/sites/public/components/styles';

/**
 * An h2 and an optional lead, as every section of the public
 * site opens: centred, or left-aligned beside other content. Reads on
 * white, grey or (inside a navy band) dark.
 */
export default function SectionHeading({
    id,
    title,
    lead,
    align = 'center',
}: {
    id: string;
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
            <h2 id={id} className={`${type.section} ${display}`}>
                {title}
            </h2>
            {lead && <p className={`mt-4 max-w-2xl ${type.lead}`}>{lead}</p>}
        </div>
    );
}
