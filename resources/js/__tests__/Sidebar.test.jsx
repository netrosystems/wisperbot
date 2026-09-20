import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Sidebar from '@/Components/Sidebar';

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key) => key }),
}));

const navItems = Array.from({ length: 20 }, (_, index) => ({
    key: `item-${index}`,
    label: `Item ${index}`,
    href: `/item-${index}`,
    active: false,
}));

describe('Sidebar scroll persistence', () => {
    beforeEach(() => {
        window.sessionStorage.clear();
    });

    it('restores its scroll position after the sidebar is remounted', () => {
        const firstRender = render(
            <Sidebar scrollKey="admin" navItems={navItems} showCreateButton={false} />,
        );
        const firstNav = screen.getByTestId('sidebar-scroll-desktop');

        firstNav.scrollTop = 420;
        fireEvent.scroll(firstNav);

        expect(window.sessionStorage.getItem('wisperbot.sidebar.scroll.admin')).toBe('420');

        firstRender.unmount();
        render(<Sidebar scrollKey="admin" navItems={navItems} showCreateButton={false} />);

        expect(screen.getByTestId('sidebar-scroll-desktop').scrollTop).toBe(420);
    });

    it('keeps admin and client menu positions independent', () => {
        window.sessionStorage.setItem('wisperbot.sidebar.scroll.admin', '510');
        window.sessionStorage.setItem('wisperbot.sidebar.scroll.client', '275');

        const adminRender = render(
            <Sidebar scrollKey="admin" navItems={navItems} showCreateButton={false} />,
        );
        expect(screen.getByTestId('sidebar-scroll-desktop').scrollTop).toBe(510);

        adminRender.unmount();
        render(<Sidebar scrollKey="client" navItems={navItems} showCreateButton={false} />);

        expect(screen.getByTestId('sidebar-scroll-desktop').scrollTop).toBe(275);
    });

    it('restores the client position when the mobile drawer opens', () => {
        window.sessionStorage.setItem('wisperbot.sidebar.scroll.client', '330');

        const { rerender } = render(
            <Sidebar scrollKey="client" navItems={navItems} showCreateButton={false} open={false} />,
        );

        rerender(
            <Sidebar scrollKey="client" navItems={navItems} showCreateButton={false} open onClose={() => {}} />,
        );

        expect(screen.getByTestId('sidebar-scroll-mobile').scrollTop).toBe(330);
    });

    it('keeps the core group open and persists collapsed navigation groups', () => {
        const navGroups = [
            { key: 'home', label: 'Home', items: [{ label: 'Dashboard', href: '/app/dashboard', active: false }] },
            { key: 'ai', label: 'Smart AI', items: [{ label: 'Smart Bots', href: '/app/ai/chatbots', active: false }] },
        ];

        const firstRender = render(
            <Sidebar scrollKey="client" navGroups={navGroups} showCreateButton={false} />,
        );

        expect(screen.getByRole('link', { name: 'Dashboard' })).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Smart Bots' })).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Smart AI' }));
        expect(screen.getByRole('link', { name: 'Smart Bots' })).toBeInTheDocument();
        expect(window.sessionStorage.getItem('wisperbot.sidebar.group.client.ai')).toBe('1');

        firstRender.unmount();
        render(<Sidebar scrollKey="client" navGroups={navGroups} showCreateButton={false} />);
        expect(screen.getByRole('link', { name: 'Smart Bots' })).toBeInTheDocument();
    });
});
