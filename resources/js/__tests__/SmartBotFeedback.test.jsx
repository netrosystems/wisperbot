import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { post, get, inertiaPost } = vi.hoisted(() => ({ post: vi.fn(), get: vi.fn(), inertiaPost: vi.fn() }));

vi.mock('axios', () => ({ default: { post, get } }));
vi.mock('@inertiajs/react', () => ({ router: { post: inertiaPost } }));
vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key) => key }) }));

import BotReplyReview from '@/Components/Inbox/BotReplyReview';
import UnansweredQuestionsCard from '@/Components/UnansweredQuestionsCard';

const reply = {
    id: 41,
    payload: { ai_review: { kb_id: 7, reason_code: 'no_context', answer_origin: 'fallback', best_score: 0.42, sources: ['Pricing'] } },
};

describe('Bot reply review in the inbox', () => {
    beforeEach(() => { post.mockReset(); get.mockReset(); });

    it('rates a reply and opens Improve with the customer question after a thumbs down', async () => {
        post.mockResolvedValueOnce({ data: { ai_feedback: { rating: 'down', improved: false } } });
        get.mockResolvedValueOnce({ data: { question: 'Do you sell eSIMs for Japan?' } });
        render(<BotReplyReview msg={reply} conversationId="conv-1" />);

        fireEvent.click(screen.getByRole('button', { name: 'inbox.ai_review_bad' }));

        await waitFor(() => expect(screen.getByDisplayValue('Do you sell eSIMs for Japan?')).toBeInTheDocument());
        expect(post).toHaveBeenCalledWith('/client.inbox.messages.ai-feedback/{"conversation":"conv-1","message":41}', { rating: 'down' });
        expect(screen.getByRole('button', { name: 'inbox.ai_review_bad' })).toHaveAttribute('aria-pressed', 'true');
    });

    it('explains why the bot answered as it did', () => {
        render(<BotReplyReview msg={reply} conversationId="conv-1" />);
        fireEvent.click(screen.getByText('inbox.ai_review_why'));

        expect(screen.getByText('inbox.ai_reason_no_context')).toBeInTheDocument();
        expect(screen.getByText('42%')).toBeInTheDocument();
        expect(screen.getByText('Pricing')).toBeInTheDocument();
    });
});

describe('Unanswered questions on the Knowledge Base page', () => {
    it('lists questions and saves a written answer', () => {
        render(<UnansweredQuestionsCard guardedPublishing={false} kb={{ id: 7, uuid: 'kb-7', knowledge_gaps: [
            { id: 3, question_sample: 'Do you ship to Mars?', occurrences: 4, last_seen_at: '2026-10-03T10:00:00Z' },
        ] }} />);

        fireEvent.click(screen.getByText('ai.unanswered_write_answer'));
        fireEvent.change(screen.getByPlaceholderText('ai.unanswered_answer_placeholder'), { target: { value: 'No, we only sell travel eSIMs.' } });
        fireEvent.click(screen.getByText('ai.unanswered_save_answer'));

        expect(inertiaPost).toHaveBeenCalledWith('/client.ai.knowledge-bases.unanswered.answer/{"kb":"kb-7","gap":3}', { question: 'Do you ship to Mars?', answer: 'No, we only sell travel eSIMs.' }, expect.anything());
    });

    it('says when there is nothing to answer', () => {
        render(<UnansweredQuestionsCard guardedPublishing={false} kb={{ id: 7, uuid: 'kb-7', knowledge_gaps: [] }} />);
        expect(screen.getByText('ai.unanswered_questions_empty')).toBeInTheDocument();
    });
});
