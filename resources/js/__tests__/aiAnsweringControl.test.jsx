import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import AiAnsweringControl from '@/Components/Inbox/AiAnsweringControl';

afterEach(cleanup);

describe('AiAnsweringControl', () => {
    it('shows a compact read-only schedule summary to members', () => {
        render(<AiAnsweringControl
            segment="omni"
            canManage={false}
            chatbots={[{ id: 7, name: 'Support Bot' }]}
            policy={{ mode: 'scheduled', chatbot_id: 7, schedule: { timezone: 'Asia/Dhaka' } }}
        />);

        expect(screen.getByText('Scheduled')).toBeInTheDocument();
        expect(screen.getByText('Support Bot')).toBeInTheDocument();
        expect(screen.getByText('Asia/Dhaka')).toBeInTheDocument();
    });

    it('shows all three compact modes to administrators', () => {
        render(<AiAnsweringControl segment="omni" canManage chatbots={[{ id: 7, name: 'Support Bot' }]} policy={{ mode: 'off' }} />);

        fireEvent.click(screen.getByRole('button', { name: 'Manage AI answering' }));

        expect(screen.getByRole('radio', { name: /^Off/ })).toBeInTheDocument();
        expect(screen.getByRole('radio', { name: /^Always on/ })).toBeInTheDocument();
        expect(screen.getByRole('radio', { name: /^Scheduled/ })).toBeInTheDocument();
    });

    it('restores the saved mode when schedule editing is cancelled', async () => {
        render(<AiAnsweringControl segment="email" canManage chatbots={[{ id: 7, name: 'Support Bot' }]} policy={{ mode: 'always_on', chatbot_id: 7 }} />);

        fireEvent.click(screen.getByRole('button', { name: 'Manage AI answering' }));
        fireEvent.click(screen.getByRole('radio', { name: /^Scheduled/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));

        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
        fireEvent.click(screen.getByRole('button', { name: 'Manage AI answering' }));
        expect(screen.getByRole('radio', { name: /^Always on/ })).toHaveAttribute('aria-checked', 'true');
    });
});
