import { describe, expect, it } from 'vitest';
import { analyzeBlogHtml, normalizeBlogHtml } from '@/lib/blogContent';

describe('blog content utilities', () => {
    it('repairs nested legacy code blocks and creates stable unique anchors', () => {
        const result = normalizeBlogHtml('<pre><pre><h2>Choose a channel</h2><p>Text</p><h2>Choose a channel</h2></pre></pre>');

        expect(result).not.toContain('<pre>');
        expect(result).toContain('<h2 id="choose-a-channel">Choose a channel</h2>');
        expect(result).toContain('<h2 id="choose-a-channel-2">Choose a channel</h2>');
    });

    it('reports content health signals used by the editor', () => {
        const health = analyzeBlogHtml('<h2>Overview</h2><p>Two useful words</p><img src="/image.webp"><table><tbody><tr><td>A</td></tr></tbody></table><h3>Why now?</h3>');

        expect(health).toMatchObject({ headings: 2, images: 1, missingAlt: 1, tables: 1, questions: 1 });
        expect(health.words).toBeGreaterThan(3);
    });
});
