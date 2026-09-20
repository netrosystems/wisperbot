import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import TeamIndex from '@/Pages/client/Team/Index';

const formPost = vi.hoisted(() => vi.fn());
const formTransform = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', async () => {
    const { useRef, useState } = await import('react');

    return {
        Head: () => null,
        router: { delete: vi.fn() },
        usePage: () => ({ props: { flash: {} } }),
        useForm: initial => {
            const [data, setState] = useState(initial);
            const dataRef = useRef(initial);
            const setData = (keyOrData, value) => {
                const next = typeof keyOrData === 'function'
                    ? keyOrData(dataRef.current)
                    : typeof keyOrData === 'string'
                        ? { ...dataRef.current, [keyOrData]: value }
                        : keyOrData;
                dataRef.current = next;
                setState(next);
            };

            return {
                data,
                errors: {},
                processing: false,
                setData,
                reset: () => setData(initial),
                clearErrors: vi.fn(),
                transform: formTransform,
                post: formPost,
                put: vi.fn(),
            };
        },
    };
});

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key, options = {}) => options.defaultValue || key }),
}));

vi.mock('@/Layouts/ClientLayout', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('@/Components/WeeklyScheduleEditor', () => ({
    default: () => null,
    defaultWeeklySchedule: (timezone, enabled) => ({ timezone, enabled, schedule: {} }),
    normalizeWeeklySchedule: value => value,
    SCHEDULE_DAYS: [],
}));
vi.mock('@/Components/ui', () => {
    const Modal = ({ show, children }) => show ? <div>{children}</div> : null;
    Modal.Header = ({ title }) => <h2>{title}</h2>;
    Modal.Body = ({ children }) => <div>{children}</div>;
    Modal.Footer = ({ children }) => <div>{children}</div>;

    return {
        Modal,
        Button: ({ children, ...props }) => <button {...props}>{children}</button>,
        PasswordInput: props => <input type="password" {...props} />,
    };
});

describe('team member edit form', () => {
    beforeEach(() => {
        formPost.mockClear();
        formTransform.mockClear();
    });

    it('sets multipart method spoofing before posting instead of chaining from transform', () => {
        render(<TeamIndex
            client={{ name: 'Example client' }}
            workspace={{ name: 'Example workspace' }}
            invitations={[]}
            users={[{
                id: 9,
                name: 'Ava Agent',
                email: 'ava@example.com',
                client_role: 'staff',
                status: 'active',
                avatar_url: 'https://example.com/ava.jpg',
                availability: { enabled: false, timezone: 'UTC', schedule: {} },
            }]}
        />);

        fireEvent.click(screen.getByRole('button', { name: 'client.edit' }));
        fireEvent.click(screen.getByRole('button', { name: 'client.save' }));

        expect(formTransform).toHaveBeenCalledOnce();
        expect(formPost).toHaveBeenCalledWith(
            '/client.team.update/{"member":9}',
            expect.objectContaining({ forceFormData: true, preserveScroll: true }),
        );
    });
});
