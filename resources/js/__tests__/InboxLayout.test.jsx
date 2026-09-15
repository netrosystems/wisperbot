import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import InboxLayout from '@/Layouts/InboxLayout';

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: key => key }),
}));
vi.mock('@/Layouts/useClientNav', () => ({ default: () => [] }));
vi.mock('@/Components/UpgradeModal', () => ({ default: () => null }));
vi.mock('sonner', () => ({ Toaster: () => null, toast: vi.fn() }));
vi.mock('@/Components/Sidebar', () => ({
    default: ({ open, onClose }) => open ? (
        <nav aria-label="Client navigation">
            <button onClick={onClose}>Close menu</button>
        </nav>
    ) : null,
}));

describe('Inbox mobile navigation', () => {
    it('reserves top-bar space instead of floating a menu over replies', () => {
        const { container } = render(
            <InboxLayout>
                <main><textarea aria-label="Reply" /><button>Send</button></main>
            </InboxLayout>,
        );
        const header = screen.getByRole('banner');
        const menu = within(header).getByRole('button', { name: 'open_menu' });

        expect(header).toHaveClass('shrink-0', 'lg:hidden');
        expect(menu).toHaveClass('h-11', 'w-11', 'focus-visible:ring-2');
        expect(menu).not.toHaveClass('fixed', 'absolute', 'bottom-4');
        expect(header.nextElementSibling).toBe(screen.getByRole('main'));
        expect(container.firstElementChild).toHaveStyle({ height: '100dvh' });
        expect(container.firstElementChild).toHaveClass('h-screen', 'overflow-hidden');
        expect(header.parentElement).toHaveClass('min-h-0', 'min-w-0');
        expect(screen.getByRole('textbox', { name: 'Reply' })).toBeEnabled();
    });

    it('opens and closes the existing navigation drawer from the top button', () => {
        render(<InboxLayout><main>Conversation</main></InboxLayout>);
        const menu = screen.getByRole('button', { name: 'open_menu' });

        expect(menu).toHaveAttribute('aria-expanded', 'false');
        fireEvent.click(menu);
        expect(menu).toHaveAttribute('aria-expanded', 'true');
        expect(screen.getByRole('navigation', { name: 'Client navigation' })).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Close menu' }));
        expect(menu).toHaveAttribute('aria-expanded', 'false');
        expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
    });

    it('provides a return link without covering the thread header', () => {
        render(<InboxLayout mobileBackHref="/app/inbox?folder=mine"><main>Thread</main></InboxLayout>);

        const back = within(screen.getByRole('banner')).getByRole('link', { name: 'inbox.title' });
        expect(back).toHaveAttribute('href', '/app/inbox?folder=mine');
        expect(back).toHaveClass('min-h-11');
    });

    it('uses the email title in the same compact top bar', () => {
        render(<InboxLayout mobileTitle="Email MasterBox"><main>Email thread</main></InboxLayout>);

        expect(within(screen.getByRole('banner')).getByText('Email MasterBox')).toBeInTheDocument();
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });
});
