import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { put } = vi.hoisted(() => ({ put: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { put },
    usePage: () => ({ props: { onesignal: { enabled: false } } }),
    useForm: () => ({
        data: { preferences: [] },
        setData: vi.fn(),
        post: vi.fn(),
        processing: false,
        transform: vi.fn(),
    }),
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (key) => ({
            'settings.email_inbox_alerts_label': 'Master Email Inbox alerts',
            'settings.email_inbox_alerts_desc': 'Email alert description',
            'settings.email_inbox_alerts_save_error': 'Could not update alerts.',
        }[key] ?? key),
    }),
}));

vi.mock('@/Layouts/ClientLayout', () => ({
    default: ({ children }) => <main>{children}</main>,
}));

vi.mock('@/push', () => ({
    subscribeToPush: vi.fn(),
    unsubscribeFromPush: vi.fn(),
}));

import NotificationSettings from '@/Pages/client/Settings/Notifications';

describe('Master Email Inbox notification preference', () => {
    beforeEach(() => put.mockReset());

    it('sends the inverse server preference and keeps other controls separate', () => {
        put.mockImplementation((_url, _data, options) => options?.onFinish?.());
        render(<NotificationSettings emailInboxNotificationsEnabled preferences={{}} />);

        const toggle = screen.getByRole('switch', { name: 'Master Email Inbox alerts' });
        expect(toggle).toHaveAttribute('aria-checked', 'true');

        fireEvent.click(toggle);

        expect(put).toHaveBeenCalledWith(
            '/client.notification-preferences.email-inbox.update',
            { enabled: false },
            expect.objectContaining({ preserveScroll: true }),
        );
        expect(toggle).toHaveAttribute('aria-checked', 'false');
    });

    it('rolls back the switch when saving fails', async () => {
        put.mockImplementation((_url, _data, options) => {
            options?.onError?.();
            options?.onFinish?.();
        });
        render(<NotificationSettings emailInboxNotificationsEnabled preferences={{}} />);

        const toggle = screen.getByRole('switch', { name: 'Master Email Inbox alerts' });
        fireEvent.click(toggle);

        await waitFor(() => expect(toggle).toHaveAttribute('aria-checked', 'true'));
        expect(screen.getByText('Could not update alerts.')).toBeInTheDocument();
    });
});
