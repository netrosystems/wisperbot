import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    __resetMetaPixelForTests,
    acceptMarketingCookies,
    getConsentSnapshot,
    readConsent,
    rejectMarketingCookies,
    syncMetaPixel,
    trackMetaEvent,
} from '@/Utils/metaPixel';

const PIXEL = '1441987344517179';

function page(url, { enabled = true, component = 'Welcome' } = {}) {
    return { url, component, props: { metaPixel: { pixel_id: enabled ? PIXEL : '', enabled } } };
}

function mockTimeZone(timeZone) {
    vi.spyOn(Intl, 'DateTimeFormat').mockImplementation(() => ({ resolvedOptions: () => ({ timeZone }) }));
}

function fbqCalls() {
    return window.fbq?.queue?.map((args) => Array.from(args)) ?? [];
}

describe('WisperBot marketing Pixel', () => {
    beforeEach(() => {
        __resetMetaPixelForTests();
        document.cookie = 'wb_marketing_consent=; Max-Age=0; Path=/';
        localStorage.clear();
        delete window.fbq;
        delete window._fbq;
        document.head.innerHTML = '<script></script>';
    });

    afterEach(() => vi.restoreAllMocks());

    it('loads nothing in European time zones until the visitor accepts', () => {
        mockTimeZone('Europe/Berlin');

        syncMetaPixel(page('/'));

        expect(window.fbq).toBeUndefined();
        expect(readConsent()).toBeNull();
        expect(getConsentSnapshot()).toMatchObject({ visible: true, optIn: true });

        acceptMarketingCookies();

        expect(readConsent()).toBe('granted');
        expect(fbqCalls()).toEqual(
            expect.arrayContaining([
                ['set', 'autoConfig', false, PIXEL],
                ['init', PIXEL],
                ['track', 'PageView'],
            ]),
        );
        expect(getConsentSnapshot().visible).toBe(false);
        expect(window.fbq.disablePushState).toBe(true);
        expect(window.fbq.allowDuplicatePageViews).toBe(true);
    });

    it('applies the notice default elsewhere and tracks each navigation once', () => {
        mockTimeZone('Asia/Dhaka');

        syncMetaPixel(page('/'));
        syncMetaPixel(page('/'));
        syncMetaPixel(page('/pricing', { component: 'marketing/Pricing' }));

        expect(readConsent()).toBe('granted');
        const tracked = fbqCalls().filter(([method]) => method === 'track');
        expect(tracked).toEqual([
            ['track', 'PageView'],
            ['track', 'PageView'],
            ['track', 'ViewContent', { content_name: 'Pricing', content_category: 'pricing' }],
        ]);
        expect(getConsentSnapshot()).toMatchObject({ visible: true, optIn: false });
    });

    it('never runs on pages the server does not mark as public', () => {
        mockTimeZone('Asia/Dhaka');

        syncMetaPixel(page('/app/inbox', { enabled: false }));

        expect(window.fbq).toBeUndefined();
        expect(getConsentSnapshot().visible).toBe(false);
    });

    it('stops sending after the visitor turns cookies off', () => {
        mockTimeZone('America/New_York');
        syncMetaPixel(page('/contact'));

        rejectMarketingCookies();
        trackMetaEvent('Lead', {}, 'lead_12345678');

        expect(readConsent()).toBe('denied');
        expect(fbqCalls()).toContainEqual(['consent', 'revoke']);
        expect(fbqCalls().some(([method, name]) => method === 'track' && name === 'Lead')).toBe(false);
    });

    it('sends browser events with the shared event ID for deduplication', () => {
        mockTimeZone('Asia/Dhaka');
        syncMetaPixel(page('/contact'));

        trackMetaEvent('Lead', { content_name: 'Contact form' }, 'lead_12345678');

        expect(fbqCalls()).toContainEqual(['track', 'Lead', { content_name: 'Contact form' }, { eventID: 'lead_12345678' }]);
    });
});
