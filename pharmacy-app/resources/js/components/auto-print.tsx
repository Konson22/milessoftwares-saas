import { useEffect, useRef } from 'react';

/**
 * Prints documents in the background after a counter step: each opens in a
 * hidden frame that prints itself (it prints only if it is still the
 * original, so a page reloaded later never prints twice). The page around
 * stays as it is, so the counter carries on.
 */
export function AutoPrint({ urls }: { urls: string[] }) {
    const frames = useRef<HTMLIFrameElement[]>([]);
    const key = urls.join('\n');

    useEffect(() => {
        if (key === '') {
            return;
        }

        // Frames of the last step go once the next one prints.
        frames.current.forEach((frame) => frame.remove());
        frames.current = key.split('\n').map((url) => {
            const frame = document.createElement('iframe');
            frame.src = url;
            frame.title = 'Printing';
            frame.setAttribute('aria-hidden', 'true');
            frame.style.cssText =
                'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden';
            document.body.appendChild(frame);

            return frame;
        });
    }, [key]);

    useEffect(
        () => () => frames.current.forEach((frame) => frame.remove()),
        [],
    );

    return null;
}
