import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { EmailAttachments, EmailBody } from '@/Components/Inbox/EmailMessageContent';

describe('EmailMessageContent', () => {
    it('renders formatting while removing executable and tracking content', () => {
        render(
            <EmailBody
                html={'<style>.red{color:red}</style><p class="red"><strong>Formatted</strong> email</p><img src="https://images.test/photo.png" onerror="alert(1)"><script>alert(1)</script>'}
                text="Fallback"
            />,
        );

        const frame = screen.getByTitle('Email content');
        const source = frame.getAttribute('srcdoc');
        expect(source).toContain('.red{color:red}');
        expect(source).toContain('<strong>Formatted</strong>');
        expect(source).toContain('https://images.test/photo.png');
        expect(source).not.toContain('<script');
        expect(source).not.toContain('onerror');
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

    it('does not crash for legacy messages with a null payload', () => {
        const { container } = render(<EmailAttachments payload={null} />);

        expect(container).toBeEmptyDOMElement();
    });

});
