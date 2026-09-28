import { useState } from 'react';
import { Film, ImageIcon, Play } from 'lucide-react';
import { isVideoUrl } from '@/Utils/socialMedia';

/*
 * Post media is a list of URLs that may be images or videos. An <img> pointed
 * at a video shows a broken image, so videos render as <video>. `preload`
 * "metadata" plus the `#t=0.1` fragment makes the browser fetch only enough of
 * the file to paint its first frame, not the whole video.
 */

function Fallback({ video, className }) {
    const Icon = video ? Film : ImageIcon;

    return (
        <span className={`flex items-center justify-center bg-neutral-100 text-neutral-400 dark:bg-neutral-800 ${className}`}>
            <Icon className="h-4 w-4" aria-hidden="true" />
        </span>
    );
}

/** Small square thumbnail: a video's first frame with a play badge. */
export function MediaThumb({ url, className = 'h-11 w-11 rounded-lg' }) {
    const [failed, setFailed] = useState(false);
    const video = isVideoUrl(url);

    if (!url || failed) {
        return <Fallback video={video} className={`shrink-0 ${className}`} />;
    }

    if (!video) {
        return <img src={url} alt="" loading="lazy" onError={() => setFailed(true)} className={`shrink-0 object-cover ${className}`} />;
    }

    return (
        <span className={`relative block shrink-0 overflow-hidden bg-neutral-900 ${className}`}>
            <video src={`${url}#t=0.1`} preload="metadata" muted playsInline tabIndex={-1} aria-hidden="true" onError={() => setFailed(true)} className="h-full w-full object-cover" />
            <span className="absolute inset-0 flex items-center justify-center">
                <span className="flex h-5 w-5 items-center justify-center rounded-full bg-black/55 text-white">
                    <Play className="ml-px h-2.5 w-2.5 fill-current" aria-hidden="true" />
                </span>
            </span>
        </span>
    );
}

/** Full-size preview: videos can be played in place. */
export function MediaPreview({ url, alt = '', className = 'block max-h-[32rem] w-full object-contain' }) {
    const [failed, setFailed] = useState(false);
    const video = isVideoUrl(url);

    if (!url || failed) {
        return <Fallback video={video} className="h-40 w-full" />;
    }

    return video
        ? <video src={`${url}#t=0.1`} preload="metadata" controls muted playsInline onError={() => setFailed(true)} className={`bg-neutral-900 ${className}`} />
        : <img src={url} alt={alt} onError={() => setFailed(true)} className={className} />;
}
