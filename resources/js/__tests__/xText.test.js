import { describe, expect, it } from 'vitest';
import { xContainsLink, xMediaProblem, xWeightedLength, X_MAX_WEIGHTED_LENGTH } from '@/Utils/xText';

describe('xText', () => {
    it('matches the server link rule', () => {
        for (const text of ['See https://example.com', 'www.example.com today', 'Visit example.com', 'bit.ly/abc', 'Mail hi@example.com']) {
            expect(xContainsLink(text)).toBe(true);
        }
        expect(xContainsLink('Opening hours change on Friday. Call us to book.')).toBe(false);
    });

    it('weighs emoji and CJK characters as 2', () => {
        expect(X_MAX_WEIGHTED_LENGTH).toBe(280);
        expect(xWeightedLength('a'.repeat(280))).toBe(280);
        expect(xWeightedLength('👍🏽中')).toBe(4);
        expect(xWeightedLength('')).toBe(0);
    });
});

describe('xMediaProblem', () => {
    it('allows up to 3 images or 1 video or GIF', () => {
        expect(xMediaProblem([])).toBe(null);
        expect(xMediaProblem(['https://cdn.test/1.jpg', 'https://cdn.test/2.png', 'https://cdn.test/3.webp'])).toBe(null);
        expect(xMediaProblem(['https://cdn.test/clip.mp4'])).toBe(null);
        expect(xMediaProblem(['https://cdn.test/1.jpg', 'https://cdn.test/2.jpg', 'https://cdn.test/3.jpg', 'https://cdn.test/4.jpg'])).toBe('count');
        expect(xMediaProblem(['https://cdn.test/clip.mp4', 'https://cdn.test/1.jpg'])).toBe('count');
        expect(xMediaProblem(['https://cdn.test/a.gif', 'https://cdn.test/b.mov'])).toBe('count');
        expect(xMediaProblem(['https://cdn.test/logo.svg'])).toBe('type');
    });
});
