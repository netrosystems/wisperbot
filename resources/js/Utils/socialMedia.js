// Mirrors App\Modules\Social\Services\SocialMediaRules so the composer warns
// before submit. The server remains authoritative; X has its own panel.

const VIDEO_EXTENSIONS = ['mp4', 'mov', 'm4v'];

function extension(url) {
    let path = url ?? '';
    try { path = new URL(url).pathname; } catch { /* keep the raw value */ }
    const last = path.split('/').pop() ?? '';
    return last.includes('.') ? last.split('.').pop().toLowerCase() : '';
}

export function isVideoUrl(url) {
    return VIDEO_EXTENSIONS.includes(extension(url));
}

/** Translation keys for media the selected networks cannot publish. */
export function mediaWarnings(networks, mediaUrls) {
    const urls = (mediaUrls ?? []).filter(Boolean);
    const videos = urls.filter(isVideoUrl).length;
    const images = urls.length - videos;
    const warnings = [];

    if (networks.includes('facebook') && (videos > 1 || (videos === 1 && images > 0) || images > 10)) {
        warnings.push('social.media_rule_facebook');
    }
    if (networks.includes('instagram')) {
        if (urls.length > 10) warnings.push('social.media_rule_instagram_count');
        if (urls.some(url => !isVideoUrl(url) && !['jpg', 'jpeg', ''].includes(extension(url)))) warnings.push('social.media_rule_instagram_jpg');
    }
    if (networks.includes('linkedin') && urls.length > 1) {
        warnings.push('social.media_rule_linkedin');
    }
    if ((networks.includes('youtube') || networks.includes('tiktok')) && urls.length > 0 && videos === 0) {
        warnings.push('social.media_rule_video');
    }

    return warnings;
}
