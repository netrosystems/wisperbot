import '@testing-library/jest-dom';
import { createElement } from 'react';
import { vi } from 'vitest';

// Mock Inertia
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { user: { name: 'Test User', timezone: 'UTC' } }, timezone: 'UTC', flash: {} } }),
    router:  { visit: vi.fn(), delete: vi.fn() },
    Head:    () => null,
    Link:    ({ href, children, ...props }) => createElement('a', { href, ...props }, children),
}));

// Mock the route() helper (Ziggy)
globalThis.route = (name, params) => `/${name}${params ? '/' + JSON.stringify(params) : ''}`;
