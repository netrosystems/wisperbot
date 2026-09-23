import { Check, Film } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { SocialBrandIcon } from '@/Components/BrandIcons';
import { Toggle } from '@/Components/ui';
import { xContainsLink, xMediaKind, xMediaProblem, xWeightedLength, X_MAX_IMAGES, X_MAX_WEIGHTED_LENGTH } from '@/Utils/xText';

/** A starting X selection: the first video or GIF alone, else the first 3 images. */
export function defaultXMedia(mediaUrls) {
    const urls = (mediaUrls ?? []).filter(Boolean);
    if (urls.length > 0 && ['video', 'gif'].includes(xMediaKind(urls[0]))) return [urls[0]];
    return urls.filter(url => xMediaKind(url) === 'image').slice(0, X_MAX_IMAGES);
}

/**
 * What will be published to X: the customised version when there is one
 * (media limited to what is still attached to the post), else the shared post.
 */
export function xContent(body, mediaUrls, custom) {
    const shared = (mediaUrls ?? []).filter(Boolean);
    if (!custom) return { body: body ?? '', mediaUrls: shared };
    return { body: custom.body ?? '', mediaUrls: (custom.media_urls ?? []).filter(url => shared.includes(url)) };
}

/** `network_content` for the request: only sent when X is selected and customised. */
export function networkContentPayload(hasX, mediaUrls, custom) {
    if (!hasX || !custom) return null;
    const content = xContent('', mediaUrls, custom);
    return { twitter: { body: content.body, media_urls: content.mediaUrls } };
}

function Issues({ body, mediaUrls }) {
    const { t } = useTranslation();
    const mediaProblem = xMediaProblem(mediaUrls);
    const issues = [
        xWeightedLength(body) > X_MAX_WEIGHTED_LENGTH && t('social.x_length_warning'),
        xContainsLink(body) && t('social.x_link_warning'),
        mediaProblem === 'count' && t('social.x_media_warning'),
        mediaProblem === 'type' && t('social.x_media_type_warning'),
    ].filter(Boolean);

    return issues.map(issue => <p key={issue} className="text-xs font-medium text-red-600 dark:text-red-400">{issue}</p>);
}

/**
 * Offered whenever an X account is selected. Off: X publishes the shared post
 * and its rules apply to it. On: X gets its own text and a choice of the
 * post's media, and only this version must follow X's rules.
 */
export default function XVersionPanel({ body, mediaUrls, value, onChange, errors = {} }) {
    const { t } = useTranslation();
    const shared = (mediaUrls ?? []).filter(Boolean);
    const content = xContent(body, shared, value);
    const length = xWeightedLength(content.body);
    const sharedHasIssues = !value && (xWeightedLength(body) > X_MAX_WEIGHTED_LENGTH || xContainsLink(body) || xMediaProblem(shared) !== null);

    const toggleMedia = (url) => {
        const selected = content.mediaUrls;
        onChange({ ...value, media_urls: selected.includes(url) ? selected.filter(item => item !== url) : [...selected, url] });
    };

    return (
        <section aria-labelledby="x-version-title" className="rounded-lg border border-neutral-200 bg-neutral-50 p-3 space-y-3 dark:border-neutral-700 dark:bg-neutral-800/40">
            <div className="flex items-center justify-between gap-3">
                <div className="flex items-center gap-2 min-w-0">
                    <SocialBrandIcon network="twitter" className="h-4 w-4 shrink-0" />
                    <h3 id="x-version-title" className="text-sm font-semibold text-neutral-800 dark:text-neutral-100">{t('social.x_version_title')}</h3>
                </div>
                <Toggle
                    checked={Boolean(value)}
                    onChange={on => onChange(on ? { body, media_urls: defaultXMedia(shared) } : null)}
                    label={t('social.x_customize')}
                    className="text-xs font-medium text-neutral-600 dark:text-neutral-300"
                />
            </div>

            {!value ? (
                <div className="space-y-1">
                    <p className="text-xs text-neutral-600 dark:text-neutral-300">
                        {t('social.x_uses_shared')} <span className="text-neutral-400">· {length} / {X_MAX_WEIGHTED_LENGTH}</span>
                    </p>
                    <Issues body={body} mediaUrls={shared} />
                    {sharedHasIssues && <p className="text-xs text-neutral-600 dark:text-neutral-300">{t('social.x_customize_suggestion')}</p>}
                </div>
            ) : (
                <div className="space-y-3">
                    <div>
                        <div className="mb-1 flex items-center justify-between">
                            <label htmlFor="x-version-body" className="text-xs font-medium text-neutral-500 dark:text-neutral-400">{t('social.x_version_text')}</label>
                            <span className={`text-xs ${length > X_MAX_WEIGHTED_LENGTH ? 'text-red-500' : 'text-neutral-400'}`}>{length} / {X_MAX_WEIGHTED_LENGTH}</span>
                        </div>
                        <textarea
                            id="x-version-body"
                            value={value.body ?? ''}
                            onChange={e => onChange({ ...value, body: e.target.value })}
                            rows={4}
                            className="w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm resize-none focus:outline-none focus:ring-2 focus:ring-brand-500 dark:border-neutral-600 dark:bg-neutral-800"
                        />
                        {errors['network_content.twitter.body'] && <p className="mt-1 text-xs text-red-500">{errors['network_content.twitter.body']}</p>}
                    </div>

                    <div>
                        <p className="mb-1 text-xs font-medium text-neutral-500 dark:text-neutral-400">{t('social.x_version_media')}</p>
                        {shared.length === 0 ? (
                            <p className="text-xs text-neutral-400">{t('social.x_version_no_media')}</p>
                        ) : (
                            <div className="flex flex-wrap gap-2">
                                {shared.map(url => {
                                    const selected = content.mediaUrls.includes(url);
                                    const kind = xMediaKind(url);
                                    return (
                                        <button
                                            key={url}
                                            type="button"
                                            aria-pressed={selected}
                                            aria-label={t('social.x_version_media_toggle')}
                                            onClick={() => toggleMedia(url)}
                                            className={`relative h-16 w-16 overflow-hidden rounded-lg border-2 transition focus:outline-none focus:ring-2 focus:ring-brand-500/40 ${selected ? 'border-brand-500' : 'border-transparent opacity-60 hover:opacity-100'}`}
                                        >
                                            {kind === 'video'
                                                ? <span className="flex h-full w-full items-center justify-center bg-neutral-900 text-white"><Film className="h-5 w-5" /></span>
                                                : <img src={url} alt="" className="h-full w-full object-cover" />}
                                            {selected && (
                                                <span className="absolute right-1 top-1 flex h-4 w-4 items-center justify-center rounded-full bg-brand-500 text-white">
                                                    <Check className="h-3 w-3" />
                                                </span>
                                            )}
                                        </button>
                                    );
                                })}
                            </div>
                        )}
                        {errors['network_content.twitter.media_urls'] && <p className="mt-1 text-xs text-red-500">{errors['network_content.twitter.media_urls']}</p>}
                    </div>

                    <Issues body={content.body} mediaUrls={content.mediaUrls} />
                </div>
            )}

            <p className="text-xs text-neutral-500 dark:text-neutral-400">{t('social.x_text_only_hint')}</p>
        </section>
    );
}
