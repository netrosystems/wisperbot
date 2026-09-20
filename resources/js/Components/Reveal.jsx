import { useState, useEffect, useRef } from 'react';

/**
 * Reveals its children with a fade-up transition the first time they scroll
 * into view. Hand-rolled on IntersectionObserver — no animation library.
 * `delay` staggers siblings; falls back to visible where IO is unavailable.
 */
export function Reveal({ children, className = '', delay = 0, as: Tag = 'div', y = 24, ...rest }) {
    const ref = useRef(null);
    const [shown, setShown] = useState(false);

    useEffect(() => {
        const el = ref.current;
        if (!el) return undefined;
        if (typeof IntersectionObserver === 'undefined') {
            setShown(true);
            return undefined;
        }
        // Content already in view on mount must be visible synchronously. This
        // also covers anchor navigation and background preview tabs, where
        // browser timer throttling could otherwise leave an entire section at
        // opacity zero until the user scrolls again.
        const rect = el.getBoundingClientRect();
        if (rect.top < window.innerHeight && rect.bottom > 0) {
            setShown(true);
            return undefined;
        }
        const io = new IntersectionObserver(
            ([entry]) => {
                if (entry.isIntersecting) {
                    setShown(true);
                    io.disconnect();
                }
            },
            { threshold: 0.12, rootMargin: '0px 0px -48px 0px' },
        );
        io.observe(el);
        return () => io.disconnect();
    }, []);

    return (
        <Tag
            ref={ref}
            className={`transition-all duration-700 ease-smooth motion-reduce:transition-none ${shown ? 'opacity-100 translate-y-0' : 'opacity-0'} ${className}`}
            style={{ transitionDelay: `${delay}ms`, transform: shown ? undefined : `translateY(${y}px)` }}
            {...rest}
        >
            {children}
        </Tag>
    );
}

/** Counts up to `value` once scrolled into view. Parses numeric prefixes so
 *  labels like "10k+" or "99.9%" animate the number and keep the suffix. */
export function useCountUp(raw, { duration = 1600 } = {}) {
    const ref = useRef(null);
    const [display, setDisplay] = useState(raw);

    useEffect(() => {
        const el = ref.current;
        const match = String(raw).match(/^([^\d]*)([\d.,]+)(.*)$/);
        if (!el || !match || typeof IntersectionObserver === 'undefined') {
            setDisplay(raw);
            return undefined;
        }
        const [, prefix, numStr, suffix] = match;
        const target = parseFloat(numStr.replace(/,/g, ''));
        const decimals = (numStr.split('.')[1] || '').length;
        if (!Number.isFinite(target)) {
            setDisplay(raw);
            return undefined;
        }
        let started = false;
        const run = () => {
            if (started) return;
            started = true;
            const start = performance.now();
            const tick = (now) => {
                const p = Math.min((now - start) / duration, 1);
                const eased = 1 - Math.pow(1 - p, 3);
                const current = (target * eased).toFixed(decimals);
                setDisplay(`${prefix}${Number(current).toLocaleString(undefined, { minimumFractionDigits: decimals, maximumFractionDigits: decimals })}${suffix}`);
                if (p < 1) requestAnimationFrame(tick);
            };
            requestAnimationFrame(tick);
        };
        const rect = el.getBoundingClientRect();
        if (rect.top < window.innerHeight && rect.bottom > 0) {
            run();
            return undefined;
        }
        const io = new IntersectionObserver(([entry]) => {
            if (!entry.isIntersecting) return;
            run();
            io.disconnect();
        }, { threshold: 0.4 });
        io.observe(el);
        return () => io.disconnect();
    }, [raw, duration]);

    return [ref, display];
}
