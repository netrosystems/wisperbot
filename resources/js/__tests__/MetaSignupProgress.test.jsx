import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import MetaSignupProgress from '@/Components/Inbox/MetaSignupProgress';

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key, options) => options.defaultValue }) }));
afterEach(cleanup);

describe('Meta signup inline progress', () => {
    it('announces progress without a second dialog or overlay', () => {
        render(<MetaSignupProgress loading />);
        expect(screen.getByRole('status')).toHaveTextContent('Keep this panel open');
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });
    it('removes guidance when authorization ends', () => {
        const { rerender } = render(<MetaSignupProgress loading />);
        rerender(<MetaSignupProgress loading={false} />);
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });
});
