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
