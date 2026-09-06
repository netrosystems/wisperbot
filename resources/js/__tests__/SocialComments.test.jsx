import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import Comments, { safeSocialLink } from '@/Pages/Social/Comments/Index';
import axios from 'axios';

vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn(), patch: vi.fn() } }));
vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key, options) => (options?.defaultValue ?? key).replace('{{name}}', options?.name || '') }) }));
vi.mock('@inertiajs/react', () => ({ Head: () => null, Link: ({ children, href, ...props }) => <a href={href} {...props}>{children}</a>, router: { get: vi.fn(), reload: vi.fn() } }));
vi.mock('@/Layouts/ClientLayout', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('@/Components/BrandIcons', () => ({ SocialBrandIcon: () => <span /> }));

const account = { id: 1, name: 'Example Page', network: 'facebook', active: true, settings: { mode: 'off', connection_status: 'ready', capabilities: { reply: true, hide: true, delete: true } } };
const comment = { id: 1, author_name: 'Alex', body: 'When do you open?', status: 'needs_attention', posted_at: '2026-09-05T10:00:00Z', account, post: { body: 'Our latest announcement', permalink: 'https://www.facebook.com/example/posts/1' } };
const props = { comments: { data: [comment] }, accounts: [account], chatbots: [], counts: { all: 1, needs_attention: 1, ai_handled: 0, resolved: 0 }, filters: { tab: 'needs_attention' }, canReply: true, canManage: true, workspaceId: 1 };
beforeEach(() => {
    vi.stubGlobal('route', name => `/${name}`);
    axios.get.mockResolvedValue({ data: { comment, replies: { data: [] }, operations: [], settings: account.settings } });
    axios.post.mockResolvedValue({ data: {} });
});
afterEach(() => { cleanup(); vi.clearAllMocks(); vi.unstubAllGlobals(); });

describe('Social comments workspace', () => {
    it('explains platform limitations without pretending unsupported accounts are ready', () => {
        render(<Comments {...props} commentPlatforms={{ youtube: { label: 'YouTube', summary: 'Comments not integrated', detail: 'Additional authorization is required.' } }} />);
        expect(screen.getByText('Which platforms support comments?')).toBeInTheDocument();
        expect(screen.getByText('YouTube · Comments not integrated')).toBeInTheDocument();
        expect(screen.getByText('Additional authorization is required.')).toBeInTheDocument();
    });
    it('shows readable compact navigation and one account onboarding action', () => {
        render(<Comments {...props} accounts={[]} />);
        expect(screen.getByRole('link', { name: 'Posts' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Comments' })).toHaveAttribute('aria-current', 'page');
        expect(screen.getAllByRole('link', { name: 'Connect account' })).toHaveLength(1);
        expect(screen.queryByText(/social\./)).not.toBeInTheDocument();
    });
    it('labels the public destination and requires explicit reply submission', async () => {
        render(<Comments {...props} filters={{ tab: 'needs_attention', selected: 1 }} />);
        const composer = await screen.findByLabelText('Public reply as Example Page');
        expect(screen.getByRole('button', { name: 'Reply publicly' })).toBeDisabled();
        fireEvent.change(composer, { target: { value: 'We open at nine.' } });
        fireEvent.click(screen.getByRole('button', { name: 'Reply publicly' }));
        await waitFor(() => expect(axios.post).toHaveBeenCalledWith('/app/social/automation/comments/1/reply', expect.objectContaining({ body: 'We open at nine.', idempotency_key: expect.any(String) })));
    });
    it('keeps viewers read-only', async () => {
        render(<Comments {...props} canReply={false} canManage={false} filters={{ tab: 'all', selected: 1 }} />);
        await screen.findByText('Public conversation');
        expect(screen.queryByRole('button', { name: 'Reply publicly' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'AI reply settings' })).not.toBeInTheDocument();
    });
    it('blocks another reply while delivery is unknown', async () => {
        axios.get.mockResolvedValue({ data: { comment, replies: { data: [] }, settings: account.settings, operations: [{ id: 'op', kind: 'reply', status: 'delivery_unknown', reason: 'verify_on_platform', body: 'A previous reply' }] } });
        render(<Comments {...props} filters={{ tab: 'all', selected: 1 }} />);
        const composer = await screen.findByLabelText('Public reply as Example Page');
        fireEvent.change(composer, { target: { value: 'Do not send twice' } });
        expect(screen.getByRole('button', { name: 'Reply publicly' })).toBeDisabled();
        expect(screen.getByText(/Meta may have accepted/)).toBeInTheDocument();
    });
    it('rejects javascript and look-alike platform links', () => {
        expect(safeSocialLink('javascript:alert(1)')).toBeNull();
        expect(safeSocialLink('https://facebook.com.attacker.test')).toBeNull();
        expect(safeSocialLink('https://attacker@facebook.com')).toBeNull();
        expect(safeSocialLink('https://www.instagram.com/p/example')).toBe('https://www.instagram.com/p/example');
    });
});
