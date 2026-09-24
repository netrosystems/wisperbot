import { describe, expect, it } from 'vitest';
import { mediaWarnings } from '@/Utils/socialMedia';

describe('mediaWarnings', () => {
    it('flags media each network cannot publish', () => {
        expect(mediaWarnings(['facebook'], ['https://cdn.test/a.mp4', 'https://cdn.test/b.jpg'])).toEqual(['social.media_rule_facebook']);
        expect(mediaWarnings(['instagram'], ['https://cdn.test/a.png'])).toEqual(['social.media_rule_instagram_jpg']);
        expect(mediaWarnings(['linkedin'], ['https://cdn.test/a.jpg', 'https://cdn.test/b.jpg'])).toEqual(['social.media_rule_linkedin']);
        expect(mediaWarnings(['tiktok'], ['https://cdn.test/a.jpg'])).toEqual(['social.media_rule_video']);
    });

    it('accepts media every selected network supports', () => {
        expect(mediaWarnings(['facebook', 'instagram'], ['https://cdn.test/a.jpg', 'https://cdn.test/b.jpg'])).toEqual([]);
        expect(mediaWarnings(['facebook', 'instagram', 'linkedin', 'youtube'], ['https://cdn.test/tour.mp4'])).toEqual([]);
    });
});
