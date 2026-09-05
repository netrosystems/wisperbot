import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import WhatsappConnectionHealth from '@/Components/Inbox/WhatsappConnectionHealth';

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key, options) => options?.defaultValue ?? key }) }));

afterEach(() => { cleanup(); vi.unstubAllGlobals(); });

const waba = (health = {}) => ({
    id: 7, can_manage_health: true,
    connection_health: { enabled: true, state: 'ready', action: 'check', components: {}, ...health },
});

describe('WhatsApp connection health', () => {
    it('distinguishes configuration success from real delivery', () => {
        render(<WhatsappConnectionHealth waba={waba()} />);
        expect(screen.getByRole('status')).toHaveTextContent('Ready');
        expect(screen.getByText(/Send a message to your business number/)).toBeInTheDocument();
        expect(screen.queryByText(/real incoming message has been processed/)).not.toBeInTheDocument();
    });

    it('runs repair without claiming success before verification', async () => {
        const fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ health: { enabled: true, state: 'checking', operation_id: 'op', components: {} } }) });
        vi.stubGlobal('fetch', fetch);
        render(<WhatsappConnectionHealth waba={waba({ state: 'needs_attention', action: 'repair' })} />);
        fireEvent.click(screen.getByRole('button', { name: 'Repair connection' }));
        await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent('Checking'));
        expect(fetch).toHaveBeenCalledWith(expect.stringContaining('setup.repair'), expect.objectContaining({ method: 'POST' }));
        expect(screen.queryByText(/checks passed/)).not.toBeInTheDocument();
    });

    it('offers reconnection and suppresses mutation controls for members', () => {
        const reconnect = vi.fn();
        const { rerender } = render(<WhatsappConnectionHealth waba={waba({ state: 'reconnect_required', action: 'reconnect' })} onReconnect={reconnect} />);
        fireEvent.click(screen.getByRole('button', { name: 'Reconnect WhatsApp' }));
        expect(reconnect).toHaveBeenCalledOnce();
        rerender(<WhatsappConnectionHealth waba={{ ...waba(), can_manage_health: false }} />);
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });

    it('shows request failures accessibly', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, json: async () => ({ message: 'Please wait before checking again.' }) }));
        render(<WhatsappConnectionHealth waba={waba()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Check connection' }));
        expect(await screen.findByRole('alert')).toHaveTextContent('Please wait');
    });
});
