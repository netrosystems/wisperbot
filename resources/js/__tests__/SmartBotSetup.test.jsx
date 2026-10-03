import React, { useState } from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { put, routerPost } = vi.hoisted(() => ({ put: vi.fn(), routerPost: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...props }) => <a href={href} {...props}>{children}</a>,
    router: { post: routerPost, delete: vi.fn(), reload: vi.fn(), visit: vi.fn() },
    usePage: () => ({ props: { flash: {} } }),
    // A small stand-in for Inertia's form state, so fields really change.
    useForm: (initial) => {
        const [data, setState] = useState(initial);
        let transform = (value) => value;
        const form = {
            data,
            errors: {},
            processing: false,
            setData: (key, value) => setState(previous => (typeof key === 'object' ? key : { ...previous, [key]: value })),
            // Like Inertia, transform() returns nothing: it cannot be chained.
            transform: (fn) => { transform = fn; },
            put: (url, options) => put(url, transform(data), options),
            post: vi.fn(),
            clearErrors: vi.fn(),
        };

        return form;
    },
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key) => key }),
    Trans: ({ i18nKey }) => i18nKey,
}));

vi.mock('@/Layouts/ClientLayout', () => ({ default: ({ children }) => <main>{children}</main> }));
vi.mock('@/Components/CompanyBriefCard', () => ({ default: () => <div>company-brief</div> }));
vi.mock('@/Components/UnansweredQuestionsCard', () => ({ default: () => <div>unanswered</div> }));
vi.mock('@/Components/SmartBot/TestPanel', () => ({ default: () => <div>test-panel</div> }));

import SmartBotShow from '@/Pages/AI/Chatbots/Show';
import AiChatbotsIndex from '@/Pages/AI/Chatbots/Index';

const kb = (overrides = {}) => ({ id: 7, uuid: 'kb-7', brand: 'Telzen', purpose: 'Travel eSIM data plans', audience: '', documents: [{ id: 1, uuid: 'doc-1', source_type: 'url', status: 'indexed', title: 'Pricing', source_ref: 'https://example.com/pricing' }], knowledge_gaps: [], ...overrides });
const bot = (overrides = {}) => ({ id: 3, uuid: 'bot-3', name: 'Support Bot', tone: 'friendly', answer_scope: 'business_only', reply_length: 'standard', engine: 'v1', answers_configured_at: '2026-10-04T00:00:00Z', ...overrides });
const show = (props = {}) => render(<SmartBotShow chatbot={bot()} kb={kb()} tones={['friendly', 'professional']} {...props} />);

describe('Smart Bot setup page', () => {
    beforeEach(() => { put.mockClear(); routerPost.mockClear(); });

    it('opens on the first step still to do', () => {
        show({ kb: kb({ brand: '' }) });
        expect(screen.getByText('smart_bot.business_description')).toBeInTheDocument();

        show({ kb: kb({ documents: [] }), chatbot: bot() });
        expect(screen.getByText('smart_bot.knowledge_description')).toBeInTheDocument();
    });

    it('saves how it answers and ticks the step', () => {
        show({ chatbot: bot({ answers_configured_at: null }) });

        fireEvent.click(screen.getByLabelText(/smart_bot.scope_strict/));
        fireEvent.click(screen.getByLabelText('smart_bot.length_detailed'));
        fireEvent.click(screen.getByText('common.save'));

        expect(put).toHaveBeenCalledWith('/client.ai.chatbots.update/"bot-3"', expect.objectContaining({ answer_scope: 'verified_only', reply_length: 'detailed', answers_configured: true, name: 'Support Bot' }), expect.anything());
    });

    it('shows settings behind a rollout flag only while the flag is on', () => {
        const { unmount } = show({ chatbot: bot({ answers_configured_at: null }) });
        expect(screen.queryByText('ai.research_approved_sources')).not.toBeInTheDocument();
        expect(screen.queryByText('ai.live_product_prices')).not.toBeInTheDocument();
        unmount();

        show({ chatbot: bot({ answers_configured_at: null }), researchAvailable: true, liveProductFactsAvailable: true });
        expect(screen.getByText('ai.research_approved_sources')).toBeInTheDocument();
        expect(screen.getByText('ai.live_product_prices')).toBeInTheDocument();
    });

    it('adds pasted text as a text source', () => {
        show({ kb: kb({ documents: [] }) });

        fireEvent.click(screen.getByText('smart_bot.source_text'));
        fireEvent.change(screen.getByPlaceholderText('ai.text_content_placeholder'), { target: { value: 'We open at 9.' } });
        fireEvent.click(screen.getByText('smart_bot.add_and_read'));

        const [url, formData] = routerPost.mock.calls[0];
        expect(url).toBe('/client.ai.knowledge-bases.documents.add/"kb-7"');
        expect(formData.get('source_type')).toBe('text');
        expect(formData.get('source_ref')).toBe('We open at 9.');
        expect(formData.has('priority')).toBe(false);
    });
});

describe('Smart Bots list', () => {
    it('shows each bot\'s knowledge and offers unused knowledge a bot', () => {
        render(<AiChatbotsIndex
            chatbots={[{ ...bot(), knowledge: { total: 3, ready: 2, reading: 1, failed: 0 } }, { ...bot({ id: 4, uuid: 'bot-4', name: 'Empty' }), knowledge: null }]}
            unusedKnowledge={[{ id: 9, uuid: 'kb-9', name: 'Old help centre', documents_count: 4 }]}
        />);

        expect(screen.getByText('smart_bot.list_reading')).toBeInTheDocument();
        expect(screen.getByText('smart_bot.list_no_knowledge')).toBeInTheDocument();
        fireEvent.click(screen.getByText('smart_bot.create_bot_from'));
        expect(routerPost).toHaveBeenCalledWith('/client.ai.chatbots.store', { name: 'Old help centre', from_kb: 'kb-9' });
    });
});
