// Mirrors App\Modules\Social\Services\XContentRules so the composer warns
// before submit. The server remains authoritative.

export const X_MAX_WEIGHTED_LENGTH = 280;

const LINK_PATTERN = /(?:[a-z][a-z0-9+.-]*:\/\/|www\.|(?<![\p{L}\p{N}_])[\p{L}\p{N}](?:[\p{L}\p{N}-]*[\p{L}\p{N}])?(?:\.[\p{L}\p{N}](?:[\p{L}\p{N}-]*[\p{L}\p{N}])?)*\.(?:[\p{L}]{2,63})(?![\p{L}\p{N}_]))/iu;
const EMOJI_CLUSTER = /\p{Emoji_Presentation}|\p{Extended_Pictographic}\uFE0F|[0-9#*]\uFE0F?\u20E3/u;

export function xContainsLink(text) {
    return LINK_PATTERN.test(text ?? '');
}

// X's weighted count: emoji clusters count 2; code points in the Latin-heavy
// ranges count 1; everything else (CJK and most non-Latin scripts) counts 2.
export function xWeightedLength(text) {
    const value = (text ?? '').normalize('NFC');
    const segments = typeof Intl !== 'undefined' && Intl.Segmenter
        ? [...new Intl.Segmenter('en', { granularity: 'grapheme' }).segment(value)].map(part => part.segment)
        : [...value];
    let length = 0;
    for (const cluster of segments) {
        if (EMOJI_CLUSTER.test(cluster)) {
            length += 2;
            continue;
        }
        for (const character of cluster) {
            const code = character.codePointAt(0);
            length += (code <= 0x10ff || (code >= 0x2000 && code <= 0x200d)
                || (code >= 0x2010 && code <= 0x201f) || (code >= 0x2032 && code <= 0x2037)) ? 1 : 2;
        }
    }
    return length;
}

export const X_MAX_IMAGES = 3;

// Mirrors XContentRules::kindFromUrl(): a guess from the extension; the
// server checks the real file type before uploading.
export function xMediaKind(url) {
    let path = url ?? '';
    try { path = new URL(url).pathname; } catch { /* keep the raw value */ }
    const extension = (path.split('.').pop() ?? '').toLowerCase();
    if (['mp4', 'mov', 'm4v'].includes(extension)) return 'video';
    if (extension === 'gif') return 'gif';
    if (['bmp', 'svg', 'tif', 'tiff', 'heic', 'avi', 'mkv', 'webm', 'wmv', 'pdf'].includes(extension)) return null;
    return 'image';
}

// 'type' for an unsupported file, 'count' for too many or mixed media, else null.
export function xMediaProblem(urls) {
    const kinds = (urls ?? []).filter(Boolean).map(xMediaKind);
    if (kinds.includes(null)) return 'type';
    const images = kinds.filter(kind => kind === 'image').length;
    const single = kinds.length - images;
    if (images > X_MAX_IMAGES || single > 1 || (single === 1 && images > 0)) return 'count';
    return null;
}
