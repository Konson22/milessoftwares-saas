import type { KeyboardEvent } from 'react';
import { Fragment, useRef, useState } from 'react';

const INPUT =
    'w-full border border-input bg-card px-2.5 py-1.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/50';

/** The "@word" being typed just before the caret, if any. */
function mentionAt(text: string, caret: number): string | null {
    const match = /(?:^|[^\p{L}\p{N}])@([\p{L}\p{N} .'-]{0,40})$/u.exec(
        text.slice(0, caret),
    );

    return match ? match[1] : null;
}

/**
 * A comment box that offers people when you type @. It stays an ordinary
 * field of its form, so the form sends and resets it as usual.
 */
export function MentionTextarea({
    name,
    people,
    ...props
}: {
    name: string;
    people: string[];
    rows?: number;
    required?: boolean;
    placeholder?: string;
    'aria-label'?: string;
}) {
    const field = useRef<HTMLTextAreaElement>(null);
    const [matches, setMatches] = useState<string[]>([]);
    const [selected, setSelected] = useState(0);

    const refresh = () => {
        const element = field.current;
        const query = element
            ? mentionAt(element.value, element.selectionStart)
            : null;
        const found =
            query === null
                ? []
                : people
                      .filter((person) =>
                          person.toLowerCase().includes(query.toLowerCase()),
                      )
                      .slice(0, 6);

        setMatches(found);
        setSelected(0);
    };

    const choose = (person: string) => {
        const element = field.current;
        if (!element) {
            return;
        }

        const caret = element.selectionStart;
        const before = element.value.slice(0, caret);
        const at = before.lastIndexOf('@');
        const inserted = `@${person} `;

        element.value = `${before.slice(0, at)}${inserted}${element.value.slice(caret)}`;
        element.focus();
        element.selectionStart = element.selectionEnd = at + inserted.length;
        setMatches([]);
    };

    const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (matches.length === 0) {
            return;
        }

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const step = event.key === 'ArrowDown' ? 1 : -1;
            setSelected(
                (current) => (current + step + matches.length) % matches.length,
            );
        } else if (event.key === 'Enter' || event.key === 'Tab') {
            event.preventDefault();
            choose(matches[selected]);
        } else if (event.key === 'Escape') {
            setMatches([]);
        }
    };

    return (
        <div className="relative">
            <textarea
                {...props}
                ref={field}
                name={name}
                className={INPUT}
                onInput={refresh}
                onClick={refresh}
                onKeyDown={onKeyDown}
                onBlur={() => setMatches([])}
            />
            {matches.length > 0 && (
                <ul
                    role="listbox"
                    aria-label="People to mention"
                    className="shadow-md absolute bottom-full z-10 mb-1 w-64 border bg-popover text-sm"
                >
                    {matches.map((person, index) => (
                        <li
                            key={person}
                            role="option"
                            aria-selected={index === selected}
                            className={
                                index === selected
                                    ? 'cursor-pointer bg-muted px-2.5 py-1.5'
                                    : 'cursor-pointer px-2.5 py-1.5'
                            }
                            // Before the field's blur closes the list.
                            onMouseDown={(event) => {
                                event.preventDefault();
                                choose(person);
                            }}
                        >
                            {person}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/**
 * A comment with its @mentions picked out. Text only: nothing in it is
 * treated as markup.
 */
export function CommentBody({
    body,
    people,
}: {
    body: string;
    people: string[];
}) {
    if (people.length === 0 || !body.includes('@')) {
        return <>{body}</>;
    }

    const names = [...people]
        .sort((a, b) => b.length - a.length)
        .map((person) => person.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
        .join('|');
    const parts = body.split(
        new RegExp(
            `(?<![\\p{L}\\p{N}])(@(?:${names}))(?![\\p{L}\\p{N}])`,
            'iu',
        ),
    );

    return (
        <>
            {parts.map((part, index) => (
                <Fragment key={index}>
                    {index % 2 === 1 ? (
                        <span className="font-medium text-primary">{part}</span>
                    ) : (
                        part
                    )}
                </Fragment>
            ))}
        </>
    );
}
