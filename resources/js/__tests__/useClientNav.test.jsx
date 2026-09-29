import { renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import useClientNav, { isNavItemActive, navItemKey } from '@/Layouts/useClientNav';

let pageProps = {};
vi.mock('@inertiajs/react', () => ({ usePage: () => ({ props: pageProps }) }));
vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key, options) => options?.defaultValue ?? key }),
}));

const socialGroup = () => renderHook(() => useClientNav()).result.current
    .find(group => group.key === 'automation-social');

describe('client sidebar social media group', () => {
    let currentRoute = 'client.dashboard';

    beforeEach(() => {
        currentRoute = 'client.dashboard';
        // Ziggy-style wildcard matching for route().current(pattern).
        globalThis.route = vi.fn((name) => {
            if (name !== undefined) return `/${name}`;
            return {
                current: pattern => new RegExp(`^${pattern.replace(/\./g, '\\.').replace(/\*/g, '.*')}$`).test(currentRoute),
            };
        });
        pageProps = { auth: { user: { client_role: 'administrator' } }, features: { social_comments: true } };
    });

    afterEach(() => {
        delete globalThis.route;
    });

    it('lists Post Scheduler with Comments below it when comments are enabled', () => {
        const items = socialGroup().items;

        expect(items.map(item => item.label)).toEqual(['Automations', 'Post Scheduler', 'Comments']);
        expect(items[2].href).toBe('/client.social.comments.index');
    });

    it('hides Comments when the rollout flag is off', () => {
        pageProps = { ...pageProps, features: { social_comments: false } };

        expect(socialGroup().items.map(item => item.label)).toEqual(['Automations', 'Post Scheduler']);
    });

    it('highlights only the entry that owns the current social page', () => {
        const [, scheduler, comments] = socialGroup().items;
        const activeOn = (name) => {
            currentRoute = name;
            return [isNavItemActive(scheduler.activePattern), isNavItemActive(comments.activePattern)];
        };

        expect(activeOn('client.social.automation.index')).toEqual([true, false]);
        expect(activeOn('client.social.automation.schedule')).toEqual([true, false]);
        expect(activeOn('client.social.posts.edit')).toEqual([true, false]);
        expect(activeOn('client.social.accounts.linkedin.select')).toEqual([true, false]);
        expect(activeOn('client.social.comments.index')).toEqual([false, true]);
        expect(activeOn('client.dashboard')).toEqual([false, false]);
    });

    it('builds a stable key from a list of patterns', () => {
        expect(navItemKey({ label: 'X', activePattern: ['a.*', 'b'] })).toBe('a.*|b');
        expect(navItemKey({ label: 'X', activePattern: 'a.*' })).toBe('a.*');
        expect(navItemKey({ label: 'X' })).toBe('X');
    });
});
