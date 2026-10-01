import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { listenForWabaSessionInfo } from '@/Utils/metaEmbeddedSignup';

function metaMessage(data, origin = 'https://www.facebook.com') {
    window.dispatchEvent(new MessageEvent('message', { origin, data: JSON.stringify(data) }));
}

describe('listenForWabaSessionInfo', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('keeps the account Meta sends minutes after the window opened', async () => {
        const listener = listenForWabaSessionInfo();

        // Coexistence: scanning the QR code with the Business app takes minutes.
        vi.advanceTimersByTime(5 * 60 * 1000);
        metaMessage({ type: 'WA_EMBEDDED_SIGNUP', event: 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING', data: { waba_id: 'WABA_1', phone_number_id: 'PHONE_1' } });

        await expect(listener.result()).resolves.toEqual({ waba_id: 'WABA_1', phone_number_id: 'PHONE_1' });
    });

    it('waits briefly after the window closes for a message still in flight', async () => {
        const listener = listenForWabaSessionInfo();
        const result = listener.result(4000);

        metaMessage({ type: 'WA_EMBEDDED_SIGNUP', event: 'FINISH', data: { waba_id: 'WABA_2' } });

        await expect(result).resolves.toEqual({ waba_id: 'WABA_2' });
    });

    it('resolves empty when Meta sends no message, so the server finds the account', async () => {
        const listener = listenForWabaSessionInfo();
        const result = listener.result(4000);

        vi.advanceTimersByTime(4000);

        await expect(result).resolves.toEqual({});
    });

    it('ignores messages from other sites and rejects a cancelled signup', async () => {
        const listener = listenForWabaSessionInfo();
        metaMessage({ type: 'WA_EMBEDDED_SIGNUP', event: 'FINISH', data: { waba_id: 'EVIL' } }, 'https://evil.example');
        metaMessage({ type: 'WA_EMBEDDED_SIGNUP', event: 'CANCEL', data: { error_message: 'Closed' } });

        await expect(listener.result()).rejects.toThrow('Closed');
    });
});
