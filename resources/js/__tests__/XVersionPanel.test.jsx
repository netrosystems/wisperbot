import { describe, expect, it } from 'vitest';
import { defaultXMedia, networkContentPayload, xContent } from '@/Pages/Social/Posts/XVersionPanel';

const photos = ['https://cdn.test/1.jpg', 'https://cdn.test/2.jpg', 'https://cdn.test/3.jpg', 'https://cdn.test/4.jpg'];

describe('X version helpers', () => {
    it('starts with the first video alone, or the first 3 images', () => {
        expect(defaultXMedia(photos)).toEqual(photos.slice(0, 3));
        expect(defaultXMedia(['https://cdn.test/clip.mp4', ...photos])).toEqual(['https://cdn.test/clip.mp4']);
        expect(defaultXMedia(['', null])).toEqual([]);
    });

    it('publishes the shared post to X until it is customised', () => {
        expect(xContent('Shared', photos, null)).toEqual({ body: 'Shared', mediaUrls: photos });
        expect(xContent('Shared', photos, { body: 'For X', media_urls: [photos[1]] })).toEqual({ body: 'For X', mediaUrls: [photos[1]] });
    });

    it('drops X media that was removed from the post', () => {
        expect(xContent('Shared', [photos[0]], { body: 'For X', media_urls: [photos[0], photos[1]] }).mediaUrls).toEqual([photos[0]]);
    });

    it('sends an X version only when X is selected and customised', () => {
        const custom = { body: 'For X', media_urls: [photos[0]] };
        expect(networkContentPayload(true, photos, custom)).toEqual({ twitter: { body: 'For X', media_urls: [photos[0]] } });
        expect(networkContentPayload(false, photos, custom)).toBe(null);
        expect(networkContentPayload(true, photos, null)).toBe(null);
    });
});
