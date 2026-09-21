import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import axios from 'axios';
import { router } from '@inertiajs/react';
import InboxShow from '@/Pages/Inbox/Show';
import InboxIndex from '@/Pages/Inbox/Index';

vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }));
vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: key => key }) }));
vi.mock('@/Layouts/useClientNav', () => ({ default: () => [] }));
vi.mock('@/Components/Sidebar', () => ({ default: () => null }));
vi.mock('@/Components/UpgradeModal', () => ({ default: () => null }));
vi.mock('sonner', () => ({ Toaster: () => null, toast: vi.fn() }));
vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');
    return {
        Head: () => null,
        Link: ({ href, children, onClick, only: _only, preserveScroll: _preserveScroll, preserveState: _preserveState, ...props }) => (
            <a href={href} onClick={event => { event.preventDefault(); onClick?.(event); }} {...props}>{children}</a>
        ),
        router: { get: vi.fn(), reload: vi.fn(), visit: vi.fn() },
        usePage: () => ({ props: { auth: { user: { id: 7, name: 'Agent', timezone: 'UTC', workspace_id: 1 } }, timezone: 'UTC', flash: {} } }),
        useForm: initial => {
            const [data, update] = useState(initial);
            return {
                data,
                setData: (key, value) => update(previous => ({ ...previous, [key]: value })),
                reset: () => update(initial),
            };
        },
    };
});

const conversation = {
    id: 1, uuid: 'test-chat', status: 'open', assigned_to: 'human',
    joined_user: { id: 7, name: 'Agent' },
    contact: { first_name: 'Customer', custom_fields: {} },
    channel_account: { channel: 'webchat', name: 'Website' },
};
const props = { conversation, messages: [], conversations: { data: [], total: 0 }, filters: { folder: 'mine' } };

beforeEach(() => {
    vi.clearAllMocks();
    sessionStorage.clear();
    delete window.Echo;
    Element.prototype.scrollIntoView = vi.fn();
    axios.get.mockResolvedValue({ data: { messages: [] } });
    axios.post.mockResolvedValue({ data: {} });
});

describe('Mobile chat reply layout', () => {
    it('merges refreshed messages for the same conversation', async () => {
        const first = {
            id: 10,
            direction: 'in',
            type: 'text',
            body: 'hello',
            sent_at: '2026-09-17T12:00:00Z',
        };
        const latest = {
            id: 11,
            direction: 'in',
            type: 'text',
            body: 'test again',
            sent_at: '2026-09-17T12:01:00Z',
        };
        const view = render(<InboxShow {...props} messages={[first]} />);

        expect(screen.getByText('hello')).toBeInTheDocument();
        view.rerender(<InboxShow {...props} messages={[first, latest]} />);

        expect(await screen.findByText('test again')).toBeInTheDocument();
    });

    it('renders conversation activity as a centered system row', () => {
        render(<InboxShow
            {...props}
            messages={[{
                id: 10,
                direction: 'system',
                type: 'event',
                body: 'Rahim joined the chat',
                sent_at: '2026-09-17T12:00:00Z',
            }]}
        />);

        const activity = screen.getByRole('status');
        expect(activity).toHaveTextContent('Rahim joined the chat');
        expect(activity.querySelector('.rounded-2xl')).toBeNull();
    });

    it('keeps the composer usable and sends the joined owner’s reply', async () => {
        render(<InboxShow {...props} />);
        const reply = screen.getByPlaceholderText('inbox.type_message_placeholder');
        const send = screen.getByRole('button', { name: 'inbox.send' });

        expect(reply).toHaveClass('min-w-0');
        expect(send).toHaveClass('h-11', 'w-11', 'shrink-0');
        expect(send).toBeDisabled();
        fireEvent.change(reply, { target: { value: 'Hello, how can I help?' } });
        expect(send).toBeEnabled();
        fireEvent.click(send);

        await waitFor(() => expect(axios.post).toHaveBeenCalledWith(
            '/client.inbox.reply/"test-chat"',
            { body: 'Hello, how can I help?', type: 'text', payload: null },
            { headers: { Accept: 'application/json' } },
        ));
        await waitFor(() => expect(reply).toHaveValue(''));
    });

    it('keeps desktop-only side columns out of mobile detail and preserves the return filter', () => {
        const { container } = render(<InboxShow {...props} />);

        expect(container.querySelector('aside')).toHaveClass('hidden', 'lg:flex');
        expect(container.querySelector('.w-72')).toHaveClass('hidden', 'lg:flex');
        const header = screen.getByRole('banner');
        expect(header.querySelector('a')).toHaveAttribute('href', '/client.inbox.index/{"folder":"mine"}');
    });

    it('does not bypass the join requirement while making more mobile space', () => {
        render(<InboxShow {...props} conversation={{ ...conversation, joined_user: null }} />);

        expect(screen.queryByPlaceholderText('inbox.type_message_placeholder')).not.toBeInTheDocument();
        expect(screen.getByText('Join before replying')).toBeInTheDocument();
        expect(screen.getAllByRole('button', { name: 'Join Chat' }).length).toBeGreaterThan(0);
    });

    it('retains filters and a new-conversation action on the full-width mobile list', () => {
        const { container } = render(<InboxIndex conversations={{ data: [], total: 0 }} filters={{}} />);
        const filters = container.querySelector('details');

        expect(filters).not.toHaveAttribute('open');
        expect(filters.querySelector('summary')).toHaveTextContent('common.filter');
        expect(filters.parentElement).toHaveClass('md:hidden');
        expect(within(filters.parentElement).getByRole('button', { name: 'inbox.new_conversation' })).toHaveClass('h-11', 'w-11');
        expect(container.querySelector('.md\\:w-80')).toHaveClass('flex-1', 'min-w-0');
    });

    it('loads the next inbox page when the conversation list reaches the end', async () => {
        axios.get.mockResolvedValueOnce({
            data: {
                props: {
                    conversations: {
                        data: [
                            {
                                id: 2,
                                uuid: 'second-chat',
                                status: 'open',
                                unread_count: 0,
                                contact: { first_name: 'Second', custom_fields: {} },
                                channel_account: { channel: 'webchat' },
                                last_message: { body: 'Second page' },
                            },
                        ],
                        total: 2,
                        next_page_url: null,
                    },
                },
            },
        });

        render(<InboxIndex
            conversations={{
                data: [
                    {
                        id: 1,
                        uuid: 'first-chat',
                        status: 'open',
                        unread_count: 0,
                        contact: { first_name: 'First', custom_fields: {} },
                        channel_account: { channel: 'webchat' },
                        last_message: { body: 'First page' },
                    },
                ],
                total: 2,
                next_page_url: '/page-2',
            }}
            filters={{}}
        />);

        const scrollRegion = screen.getByText('First').closest('.overflow-y-auto');
        Object.defineProperties(scrollRegion, {
            scrollHeight: { configurable: true, value: 1000 },
            scrollTop: { configurable: true, value: 820 },
            clientHeight: { configurable: true, value: 100 },
        });
        fireEvent.wheel(scrollRegion);
        fireEvent.scroll(scrollRegion);

        await waitFor(() => expect(axios.get).toHaveBeenCalledWith('/page-2', expect.objectContaining({
            headers: expect.objectContaining({ 'X-Inertia': 'true' }),
        })));
        expect(await screen.findByText('Second')).toBeInTheDocument();
    });

    it('sends inbox search to the server instead of filtering only loaded rows', async () => {
        render(<InboxIndex
            conversations={{
                data: [{
                    id: 1,
                    uuid: 'first-chat',
                    status: 'open',
                    unread_count: 0,
                    contact: { first_name: 'First', custom_fields: {} },
                    channel_account: { channel: 'webchat' },
                    last_message: { body: 'Hello' },
                }],
                total: 32,
                next_page_url: '/page-2',
            }}
            filters={{ folder: 'mine' }}
        />);

        fireEvent.change(screen.getByPlaceholderText('inbox.search_conversations'), {
            target: { value: 'Needle' },
        });

        await waitFor(() => expect(router.get).toHaveBeenCalledWith(
            expect.anything(),
            expect.objectContaining({ folder: 'mine', q: 'Needle' }),
            expect.objectContaining({ preserveState: true, preserveScroll: true, replace: true }),
        ));
    });

    it('keeps deleted search characters from returning after an older response', async () => {
        const cancel = vi.fn();
        router.get.mockImplementationOnce((_url, _data, options) => {
            options.onCancelToken({ cancel });
        });
        const inboxProps = {
            conversations: { data: [], total: 0 },
            filters: {},
        };
        const view = render(<InboxIndex {...inboxProps} />);
        const input = screen.getByPlaceholderText('inbox.search_conversations');

        fireEvent.change(input, { target: { value: 'Customer' } });
        await waitFor(() => expect(router.get).toHaveBeenCalledTimes(1));

        fireEvent.change(input, { target: { value: 'Cust' } });
        expect(cancel).toHaveBeenCalledTimes(1);

        view.rerender(<InboxIndex {...inboxProps} filters={{ q: 'Customer' }} />);
        expect(input).toHaveValue('Cust');
    });

    it('restores the conversation list position when switching threads', () => {
        const list = {
            data: [
                conversation,
                {
                    ...conversation,
                    id: 2,
                    uuid: 'second-chat',
                    contact: { first_name: 'Second', custom_fields: {} },
                },
            ],
            total: 2,
        };
        const view = render(<InboxShow {...props} conversations={list} />);
        const scrollRegion = screen.getByText('Second').closest('.overflow-y-auto');
        scrollRegion.scrollTop = 420;

        fireEvent.click(screen.getByText('Second').closest('a'));
        view.rerender(<InboxShow
            {...props}
            conversation={list.data[1]}
            conversations={list}
        />);

        expect(scrollRegion.scrollTop).toBe(420);
    });

    it('refreshes the resolved folder when an inbound message reopens a conversation', () => {
        const listeners = {};
        const channel = {
            listen: vi.fn((event, callback) => {
                listeners[event] = callback;
                return channel;
            }),
            notification: vi.fn(() => channel),
        };
        window.Echo = {
            private: vi.fn(() => channel),
            leave: vi.fn(),
        };

        render(<InboxIndex
            conversations={{
                data: [{
                    id: 1,
                    uuid: 'resolved-chat',
                    status: 'resolved',
                    unread_count: 0,
                    contact: { first_name: 'Customer', custom_fields: {} },
                    channel_account: { channel: 'webchat' },
                    last_message: { body: 'Old message' },
                }],
                total: 1,
            }}
            filters={{ folder: 'resolved' }}
        />);

        act(() => listeners['.MessageReceived']({
            conversation_id: 1,
            reopened: true,
            conversation: { status: 'open' },
        }));

        expect(router.reload).toHaveBeenCalledWith({
            only: ['conversations'],
            preserveScroll: true,
            preserveState: true,
        });
    });

    it('reconciles live visitors when Pusher reports a presence change', () => {
        const listeners = {};
        const channel = {
            listen: vi.fn((event, callback) => {
                listeners[event] = callback;
                return channel;
            }),
            notification: vi.fn(() => channel),
        };
        window.Echo = {
            private: vi.fn(() => channel),
            leave: vi.fn(),
        };

        render(<InboxIndex conversations={{ data: [], total: 0 }} filters={{ folder: 'live' }} />);

        act(() => listeners['.LiveVisitorUpdated']({
            conversation_id: 9,
            conversation_uuid: 'live-9',
            online: true,
        }));

        expect(router.reload).toHaveBeenCalledWith({
            only: ['conversations', 'liveUsersCount'],
            preserveScroll: true,
            preserveState: true,
            onFinish: expect.any(Function),
        });
    });
});
