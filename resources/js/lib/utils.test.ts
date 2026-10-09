import { describe, expect, it } from 'vite-plus/test';
import { cn, toUrl } from './utils';

describe('cn', () => {
    it('merges conflicting tailwind classes, last one wins', () => {
        expect(cn('px-2 py-1', 'px-4')).toBe('py-1 px-4');
    });

    it('drops falsy values', () => {
        expect(cn('a', false, null, undefined, 'b')).toBe('a b');
    });
});

describe('toUrl', () => {
    it('returns string hrefs unchanged', () => {
        expect(toUrl('/dashboard')).toBe('/dashboard');
    });

    it('extracts the url from wayfinder route objects', () => {
        expect(toUrl({ url: '/settings', method: 'get' })).toBe('/settings');
    });
});
