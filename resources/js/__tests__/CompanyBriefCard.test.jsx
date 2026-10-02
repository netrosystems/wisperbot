import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { post } = vi.hoisted(() => ({ post: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    router: { post, delete: vi.fn(), reload: vi.fn() },
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key, values) => (values?.count !== undefined ? `${key}:${values.count}` : key) }),
}));

vi.mock('@/Components/ConfirmDialog', () => ({ confirmDialog: vi.fn(async () => true) }));

import CompanyBriefCard from '@/Components/CompanyBriefCard';

const kb = (overrides = {}) => ({
    id: 7, uuid: 'kb-7', company_brief_status: 'none', company_brief: null, company_brief_approved_at: null,
    company_brief_draft: null, company_brief_drafted_at: null, ...overrides,
});

describe('Company brief card', () => {
    beforeEach(() => post.mockReset());

    it('offers a draft only once there are sources', () => {
        const { rerender } = render(<CompanyBriefCard kb={kb()} hasSources={false} />);
        expect(screen.getByText('ai.company_brief_draft').closest('button')).toBeDisabled();
        expect(screen.getByText('ai.company_brief_needs_sources')).toBeInTheDocument();

        rerender(<CompanyBriefCard kb={kb()} hasSources />);
        fireEvent.click(screen.getByText('ai.company_brief_draft'));
        expect(post).toHaveBeenCalledWith('/client.ai.knowledge-bases.company-brief.draft/{"kb":"kb-7"}', {}, expect.anything());
    });

    it('starts flagged sentences unticked and approves only the kept, edited ones', () => {
        render(<CompanyBriefCard hasSources kb={kb({
            company_brief_status: 'draft',
            company_brief_drafted_at: '2026-10-03T10:00:00Z',
            company_brief_draft: [
                { text: 'Telzen sells travel eSIMs.', source_title: 'About', supported: true },
                { text: 'Plans start at 2 USD.', source_title: 'Pricing', supported: false },
            ],
        })} />);

        const boxes = screen.getAllByRole('checkbox');
        expect(boxes.map(box => box.checked)).toEqual([true, false]);
        expect(screen.getByText('ai.company_brief_unsupported')).toBeInTheDocument();

        fireEvent.change(screen.getAllByRole('textbox')[0], { target: { value: 'Telzen sells travel eSIM data plans.' } });
        fireEvent.click(screen.getByText('ai.company_brief_approve:1'));

        expect(post).toHaveBeenCalledWith('/client.ai.knowledge-bases.company-brief.approve/{"kb":"kb-7"}', { sentences: ['Telzen sells travel eSIM data plans.'] }, expect.anything());
    });

    it('keeps showing the approved brief while a new draft waits', () => {
        render(<CompanyBriefCard hasSources kb={kb({
            company_brief: 'Telzen sells eSIMs.', company_brief_approved_at: '2026-10-02T10:00:00Z',
            company_brief_status: 'draft', company_brief_drafted_at: '2026-10-03T10:00:00Z',
            company_brief_draft: [{ text: 'New sentence.', source_title: 'About', supported: true }],
        })} />);

        expect(screen.getByText('Telzen sells eSIMs.')).toBeInTheDocument();
        expect(screen.getByText('ai.company_brief_in_use_until')).toBeInTheDocument();
    });
});
