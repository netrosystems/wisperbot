import React, { useState } from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

const { put } = vi.hoisted(() => ({ put: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { delete: vi.fn() },
    usePage: () => ({ props: { flash: {} } }),
    // A small stand-in for Inertia's form state, so the select really changes.
    useForm: (initial) => {
        const [data, setState] = useState(initial);
        return {
            data,
            setData: (key, value) => setState(previous => ({ ...previous, [key]: value })),
            put: (url, options) => put(url, data, options),
            post: vi.fn(),
            processing: false,
            reset: vi.fn(),
        };
    },
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key) => key }),
}));

vi.mock('@/Layouts/ClientLayout', () => ({
    default: ({ children }) => <main>{children}</main>,
}));

import AiChatbotsIndex from '@/Pages/AI/Chatbots/Index';

const knowledgeBase = { id: 7, name: 'Help centre', brand: 'Telzen', purpose: 'Travel eSIM data plans', audience: 'Travellers' };
const bot = (overrides = {}) => ({
    id: 3, uuid: 'bot-3', name: 'Support Bot', tone: 'friendly', ai_kb_id: 7,
    answer_scope: 'business_only', reply_length: 'standard', engine: 'v1', ...overrides,
});

function openSettings(props) {
    render(<AiChatbotsIndex chatbots={[props.chatbot]} knowledgeBases={[knowledgeBase]} {...props} />);
    fireEvent.click(screen.getByText('ai.configure'));
}

describe('Smart Bot answer controls', () => {
    it('saves the chosen reply length with the other settings', () => {
        openSettings({ chatbot: bot() });

        fireEvent.change(screen.getByLabelText('ai.reply_length'), { target: { value: 'detailed' } });
        fireEvent.click(screen.getByText('ai.save_changes'));

        expect(put).toHaveBeenCalledWith('/client.ai.chatbots.update/"bot-3"', expect.objectContaining({ reply_length: 'detailed', answer_scope: 'business_only' }), expect.anything());
    });

    it('marks answer scope as staged only when neither routing nor the new engine applies it', () => {
        openSettings({ chatbot: bot() });
        expect(screen.getByText('ai.staged_rollout')).toBeInTheDocument();
        expect(screen.queryByText('ai.engine_v2_badge')).not.toBeInTheDocument();
    });

    it('shows a bot on the new answer engine and drops the staged label', () => {
        openSettings({ chatbot: bot({ engine: 'v2' }), engineV2Enabled: true });
        expect(screen.getByText('ai.engine_v2_badge')).toBeInTheDocument();
        expect(screen.queryByText('ai.staged_rollout')).not.toBeInTheDocument();
    });
});
