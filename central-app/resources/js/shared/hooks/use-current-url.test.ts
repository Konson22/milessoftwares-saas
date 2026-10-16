import { describe, expect, it, vi } from 'vite-plus/test';
import { useCurrentUrl } from './use-current-url';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ url: '/settings/profile?tab=1' }),
}));

describe('useCurrentUrl', () => {
    const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();

    it('matches paths, absolute and scheme-relative URLs by path', () => {
        expect(isCurrentUrl('/settings/profile')).toBe(true);
        expect(
            isCurrentUrl('http://auth.milessoftwares.local/settings/profile'),
        ).toBe(true);
        // Wayfinder's form for routes bound to a host.
        expect(
            isCurrentUrl({
                url: '//auth.milessoftwares.local/settings/profile',
                method: 'get',
            }),
        ).toBe(true);
    });

    it('does not match other pages', () => {
        expect(
            isCurrentUrl('//auth.milessoftwares.local/settings/security'),
        ).toBe(false);
        expect(isCurrentOrParentUrl('/settings/profile/extra')).toBe(false);
        expect(isCurrentOrParentUrl('/settings')).toBe(true);
    });
});
