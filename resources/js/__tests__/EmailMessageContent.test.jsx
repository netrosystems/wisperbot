import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { EmailAttachments, EmailBody } from '@/Components/Inbox/EmailMessageContent';

describe('EmailMessageContent', () => {
    it('renders formatting while removing executable and tracking content', () => {
        const { container } = render(
            <EmailBody
                html={'<p><strong>Formatted</strong> email</p><img src="https://tracker.test/pixel"><script>alert(1)</script>'}
                text="Fallback"
            />,
        );

        expect(screen.getByText('Formatted')).toBeInTheDocument();
        expect(container.querySelector('strong')).not.toBeNull();
        expect(container.querySelector('script')).toBeNull();
        expect(container.querySelector('img')).toBeNull();
    });

    it('renders every attachment from the shared payload contract', () => {
        render(<EmailAttachments payload={{ attachments: [
            { name: 'invoice.pdf', url: 'https://example.test/invoice.pdf', size: 2048 },
            { name: 'photo.jpg', url: 'https://example.test/photo.jpg', mime_type: 'image/jpeg' },
        ] }} />);

        expect(screen.getByText('invoice.pdf')).toBeInTheDocument();
        expect(screen.getByAltText('photo.jpg')).toBeInTheDocument();
        expect(screen.getByText('2 attachments')).toBeInTheDocument();
    });

});
