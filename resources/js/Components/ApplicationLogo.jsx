import { usePage } from '@inertiajs/react';

export default function ApplicationLogo({ className, style, alt }) {
    let logoUrl = null;
    try {
        // eslint-disable-next-line react-hooks/rules-of-hooks
        logoUrl = usePage().props.branding?.logo_url ?? null;
    } catch {
        logoUrl = null;
    }

    if (logoUrl) {
        return (
            <img
                src={logoUrl}
                alt={alt ?? 'App Logo'}
                className={className}
                style={style}
            />
        );
    }

    // Fallback brand mark: the WisperBot "W" monogram as a single-color glyph.
    // Both strokes take a single `fill` — callers recolor it with `fill-current`
    // + a text color (white on the auth gradient, brand-500 in the topbar, etc.).
    return (
        <svg
            className={className}
            style={style}
            viewBox="0 0 506 296"
            xmlns="http://www.w3.org/2000/svg"
        >
            <path d="M305.852 295.402L269.972 220.855L310.38 146.656L320.831 167.557L406.177 0.348633H505.805L359.498 295.402H305.852Z" />
            <path d="M150.438 295.401H198.56L319.089 94.0547L272.41 0L174.524 155.364L96.4931 0H0L150.438 295.401Z" />
        </svg>
    );
}
