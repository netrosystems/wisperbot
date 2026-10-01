export const WHATSAPP_ONBOARDING_CLOUD_API = 'cloud_api';
export const WHATSAPP_ONBOARDING_COEXISTENCE = 'coexistence';

export function embeddedSignupExtras(channel, whatsappOnboarding = WHATSAPP_ONBOARDING_CLOUD_API) {
    if (channel === 'whatsapp') {
        return {
            setup: {},
            featureType: whatsappOnboarding === WHATSAPP_ONBOARDING_COEXISTENCE
                ? 'whatsapp_business_app_onboarding'
                : '',
            sessionInfoVersion: '3',
        };
    }

    if (channel === 'instagram') return { feature_type: 'instagram_management' };
    if (channel === 'messenger') return { feature_type: 'messenger_chat' };

    return {};
}

export function isWhatsappEmbeddedSignupFinish(eventName) {
    return !eventName || [
        'FINISH',
        'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING',
    ].includes(eventName);
}

export function embeddedSignupLoginOptions(configId, channel, whatsappOnboarding = WHATSAPP_ONBOARDING_CLOUD_API) {
    return {
        config_id: configId,
        response_type: 'code',
        override_default_response_type: true,
        display: 'popup',
        extras: embeddedSignupExtras(channel, whatsappOnboarding),
    };
}

/**
 * Listens for the WA_EMBEDDED_SIGNUP postMessage that Meta sends when
 * sessionInfoVersion:'3' is set. It carries the WhatsApp Business Account and
 * phone number the person chose. Listening must last as long as Meta's window
 * is open: Coexistence (scanning a QR code with the WhatsApp Business app) and
 * adding a new number both take minutes, and a fixed 15-second timer used to
 * drop the message, so the server had to guess the account.
 */
export function listenForWabaSessionInfo() {
    let finished = null;
    let failed = null;
    let settle = null;

    function handler(event) {
        let hostname;
        try {
            hostname = new URL(event.origin).hostname;
        } catch {
            return;
        }
        if (hostname !== 'facebook.com' && !hostname.endsWith('.facebook.com')) return;

        try {
            const parsed = typeof event.data === 'string' ? JSON.parse(event.data) : event.data;
            if (parsed?.type !== 'WA_EMBEDDED_SIGNUP') return;
            if (parsed.event === 'CANCEL' || parsed.event === 'ERROR') {
                failed = new Error(parsed.data?.error_message ?? 'WhatsApp authorization was not completed.');
            } else if (isWhatsappEmbeddedSignupFinish(parsed.event)) {
                finished = parsed.data ?? {};
            } else {
                return;
            }
            settle?.();
        } catch {
            // Ignore unrelated non-JSON cross-window messages.
        }
    }

    window.addEventListener('message', handler);
    const stop = () => window.removeEventListener('message', handler);

    return {
        stop,
        /**
         * Called once Meta's window has closed. The message normally arrives
         * before that; wait briefly in case it is still in flight. Meta can
         * also return a valid code without it (reused settings), and the
         * server then finds the account from the token.
         */
        result(graceMs = 4000) {
            return new Promise((resolve, reject) => {
                let timer = null;
                const done = () => {
                    clearTimeout(timer);
                    settle = null;
                    stop();
                    if (failed) reject(failed);
                    else resolve(finished ?? {});
                };
                if (finished || failed) {
                    done();
                    return;
                }
                settle = done;
                timer = setTimeout(done, graceMs);
            });
        },
    };
}
