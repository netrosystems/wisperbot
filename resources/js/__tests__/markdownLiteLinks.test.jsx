import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import MarkdownLite from '@/Components/MarkdownLite';

describe('MarkdownLite links', () => {
    it('normalises escaped query strings in markdown links', () => {
        const url = 'https://www.telzen.net/packages/6911a730a56d9a0dcf383fe5?countryid=68fa05df73ed268e692de22e&countryname=Dominica&plan_type=esim';
        render(
            <MarkdownLite
                content={`[${url.replace('plan_type', 'plan\\_type')}](${url.replaceAll('&', '\\&')})`}
            />
        );

        const link = screen.getByRole('link');
        expect(link).toHaveTextContent(url);
        expect(link).toHaveAttribute('href', url);
    });
});
