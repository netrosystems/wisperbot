import { describe, expect, it } from 'vitest';
import {
    embeddedSignupExtras,
    embeddedSignupLoginOptions,
    isWhatsappEmbeddedSignupFinish,
    WHATSAPP_ONBOARDING_CLOUD_API,
    WHATSAPP_ONBOARDING_COEXISTENCE,
} from '@/Utils/metaEmbeddedSignup';

describe('Meta Embedded Signup helpers', () => {
    it('launches Coexistence with Meta’s required feature type', () => {
        expect(embeddedSignupExtras('whatsapp', WHATSAPP_ONBOARDING_COEXISTENCE)).toEqual({
            setup: {},
            featureType: 'whatsapp_business_app_onboarding',
            sessionInfoVersion: '3',
        });
    });

    it('keeps the standard Cloud API flow available', () => {
        expect(embeddedSignupExtras('whatsapp', WHATSAPP_ONBOARDING_CLOUD_API).featureType).toBe('');
    });

    it('accepts both standard and Coexistence completion events', () => {
        expect(isWhatsappEmbeddedSignupFinish('FINISH')).toBe(true);
        expect(isWhatsappEmbeddedSignupFinish('FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING')).toBe(true);
        expect(isWhatsappEmbeddedSignupFinish('CANCEL')).toBe(false);
    });

    it('requests the compact Meta popup presentation', () => {
        expect(embeddedSignupLoginOptions('CONFIG_ID', 'whatsapp', WHATSAPP_ONBOARDING_COEXISTENCE)).toMatchObject({
            config_id: 'CONFIG_ID',
            response_type: 'code',
            override_default_response_type: true,
            display: 'popup',
            extras: { featureType: 'whatsapp_business_app_onboarding' },
        });
    });
});
