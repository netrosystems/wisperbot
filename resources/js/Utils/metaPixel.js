// WisperBot's own marketing Meta Pixel.
//
// Runs only on public pages the server marks as eligible (props.metaPixel),
// never inside client or admin workspaces, and only with consent:
// - European time zones (EEA, UK, Switzerland and neighbours): nothing loads
//   until the visitor accepts.
// - Elsewhere: advertising cookies are on by default, the banner is a notice,
//   and the visitor can turn them off at any time.
// The decision is stored in a first-party cookie the server also reads, so the
// Conversions API follows exactly the same choice.

export const CONSENT_COOKIE = 'wb_marketing_consent';
const CHOICE_KEY = 'cookie_consent'; // 'accepted' | 'declined' once the visitor has answered
const ONE_YEAR = 60 * 60 * 24 * 365;
const OPT_IN_TIME_ZONE = /^(Europe\/|Atlantic\/(Reykjavik|Canary|Madeira|Azores|Faroe)|Arctic\/Longyearbyen)/;
const FBEVENTS_SRC = 'https://connect.facebook.net/en_US/fbevents.js';

// Extra standard events for specific public pages, sent after PageView.
const PAGE_EVENTS = {
    'marketing/Pricing': ['ViewContent', { content_name: 'Pricing', content_category: 'pricing' }],
};

const state = {
    pixelId: '',
    enabled: false,
    loaded: false,
    page: null,
    lastTrackedUrl: null,
    reopened: false,
};
const listeners = new Set();
let snapshotCache = null;

function storage() {
    try {
        return window.localStorage;
    } catch {
        return null;
    }
}

export function requiresOptIn() {
    try {
        return OPT_IN_TIME_ZONE.test(Intl.DateTimeFormat().resolvedOptions().timeZone || '');
    } catch {
        // Unknown location: ask first.
        return true;
    }
}

export function readConsent() {
    const match = document.cookie.match(new RegExp(`(?:^|;\\s*)${CONSENT_COOKIE}=(granted|denied)`));
    return match ? match[1] : null;
}

function writeConsent(value) {
    const secure = window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `${CONSENT_COOKIE}=${value}; Max-Age=${ONE_YEAR}; Path=/; SameSite=Lax${secure}`;
}

function hasAnswered() {
    const choice = storage()?.getItem(CHOICE_KEY);
    return choice === 'accepted' || choice === 'declined';
}

/** Effective consent for this visitor, applying the regional default once. */
function effectiveConsent() {
    const stored = readConsent();
    if (stored) return stored;

    // Honour an answer given to the earlier banner, which only used localStorage.
    const legacy = storage()?.getItem(CHOICE_KEY);
    if (legacy === 'accepted' || legacy === 'declined') {
        const value = legacy === 'accepted' ? 'granted' : 'denied';
        writeConsent(value);
        return value;
    }

    if (requiresOptIn()) return null;

    writeConsent('granted');
    return 'granted';
}

function loadPixel(pixelId) {
    if (state.loaded || !pixelId) return;

    /* eslint-disable */
    !function (f, b, e, v, n, t, s) {
        if (f.fbq) return; n = f.fbq = function () {
            n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments);
        };
        if (!f._fbq) f._fbq = n; n.push = n; n.loaded = !0; n.version = '2.0'; n.queue = [];
        // Inertia navigations are reported by syncMetaPixel; Meta's own
        // history listener would double-count them and ignore consent timing.
        n.disablePushState = true;
        // Otherwise fbevents drops every PageView after the first on this document.
        n.allowDuplicatePageViews = true;
        t = b.createElement(e); t.async = !0; t.src = v;
        s = b.getElementsByTagName(e)[0]; s.parentNode.insertBefore(t, s);
    }(window, document, 'script', FBEVENTS_SRC);
    /* eslint-enable */

    // Only the events WisperBot sends explicitly; no automatic button/form scraping.
    window.fbq('set', 'autoConfig', false, pixelId);
    window.fbq('consent', 'grant');
    window.fbq('init', pixelId);
    state.loaded = true;
}

function trackPage(page) {
    if (!page || page.url === state.lastTrackedUrl) return;
    state.lastTrackedUrl = page.url;
    window.fbq('track', 'PageView');
    const extra = PAGE_EVENTS[page.component];
    if (extra) window.fbq('track', extra[0], extra[1]);
}

function notify() {
    listeners.forEach((listener) => listener());
}

/** Call with every Inertia page (initial load and each navigation). */
export function syncMetaPixel(page) {
    const config = page?.props?.metaPixel;
    state.page = page;
    state.pixelId = config?.pixel_id || '';
    state.enabled = Boolean(config?.enabled && /^\d{5,20}$/.test(state.pixelId));
    snapshotCache = null;
    notify();

    if (!state.enabled || effectiveConsent() !== 'granted') return;

    loadPixel(state.pixelId);
    trackPage(page);
}

/** Fire a standard event from a public page; no-op without consent. */
export function trackMetaEvent(name, params = {}, eventId = null) {
    if (!state.enabled || !state.loaded || readConsent() !== 'granted') return;
    window.fbq('track', name, params, eventId ? { eventID: eventId } : undefined);
}

/** Shared by the browser event and the server event so Meta counts one. */
export function newMetaEventId(prefix = 'evt') {
    const random = window.crypto?.randomUUID?.() ?? `${Date.now()}${Math.random().toString(16).slice(2)}`;
    return `${prefix}_${random}`.replace(/[^A-Za-z0-9_-]/g, '').slice(0, 64);
}

export function acceptMarketingCookies() {
    storage()?.setItem(CHOICE_KEY, 'accepted');
    writeConsent('granted');
    state.reopened = false;
    snapshotCache = null;
    if (state.enabled) {
        loadPixel(state.pixelId);
        window.fbq?.('consent', 'grant');
        trackPage(state.page);
    }
    notify();
}

export function rejectMarketingCookies() {
    storage()?.setItem(CHOICE_KEY, 'declined');
    writeConsent('denied');
    state.reopened = false;
    snapshotCache = null;
    // Stops further Pixel requests immediately; the script is gone on the next full load.
    window.fbq?.('consent', 'revoke');
    notify();
}

/** Show the banner again so the visitor can change their choice. */
export function reopenCookieSettings() {
    state.reopened = true;
    snapshotCache = null;
    notify();
}

/** For useSyncExternalStore in the consent banner. */
export function getConsentSnapshot() {
    if (!snapshotCache) {
        const optIn = requiresOptIn();
        snapshotCache = {
            enabled: state.enabled,
            visible: state.enabled && (state.reopened || !hasAnswered()),
            optIn,
            consent: readConsent(),
        };
    }
    return snapshotCache;
}

export function subscribeConsent(listener) {
    listeners.add(listener);
    return () => listeners.delete(listener);
}

/** Test helper. */
export function __resetMetaPixelForTests() {
    Object.assign(state, { pixelId: '', enabled: false, loaded: false, page: null, lastTrackedUrl: null, reopened: false });
    snapshotCache = null;
    listeners.clear();
}
