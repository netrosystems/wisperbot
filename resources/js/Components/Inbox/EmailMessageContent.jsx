import { ExternalLink, Paperclip } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

function safeEmailHtml(value) {
    if (typeof value !== 'string' || value.trim() === '') return '';
    if (typeof DOMParser === 'undefined') return '';

    const document = new DOMParser().parseFromString(value, 'text/html');
    document.querySelectorAll('script, iframe, object, embed, svg, math, form, input, button, select, textarea, template, link').forEach(node => node.remove());
    document.querySelectorAll('*').forEach(node => {
        [...node.attributes].forEach(attribute => {
            if (attribute.name.toLowerCase().startsWith('on')) node.removeAttribute(attribute.name);
        });
        if (node.hasAttribute('href') && !/^(https?:\/\/|mailto:|#)/i.test(node.getAttribute('href') || '')) {
            node.removeAttribute('href');
        }
        if (node.hasAttribute('src') && !/^(https?:\/\/|data:image\/(?:png|jpe?g|gif|webp);base64,)/i.test(node.getAttribute('src') || '')) {
            node.removeAttribute('src');
        }
        if (node.hasAttribute('style') && /expression\s*\(|javascript:|vbscript:/i.test(node.getAttribute('style') || '')) {
            node.removeAttribute('style');
        }
    });

    document.querySelectorAll('style').forEach(node => {
        node.textContent = (node.textContent || '')
            .replace(/@import\s+[^;]+;?/gi, '')
            .replace(/@charset\s+[^;]+;?/gi, '')
            .replace(/expression\s*\(|javascript:|vbscript:|-moz-binding\s*:|behavior\s*:/gi, '');
    });

    const styles = [...document.querySelectorAll('style')].map(node => node.outerHTML).join('');
    document.querySelectorAll('style').forEach(node => node.remove());

    return styles + document.body.innerHTML;
}

export function EmailBody({ html, text, className = '' }) {
    const frameRef = useRef(null);
    const [frameHeight, setFrameHeight] = useState(160);
    const safeHtml = useMemo(() => safeEmailHtml(html), [html]);
    const sourceDocument = useMemo(() => safeHtml ? `<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; img-src https: data:; font-src https: data:; media-src https: data:; connect-src 'none'; frame-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'">
<meta name="color-scheme" content="light">
<base target="_blank">
<style>
html { color-scheme: light; background: #fff; }
body { box-sizing: border-box; margin: 0; padding: 2px; color: #171717; background: #fff; font-family: Arial, Helvetica, sans-serif; overflow-wrap: anywhere; }
*, *::before, *::after { box-sizing: border-box; }
img { max-width: 100%; height: auto; }
table { max-width: 100%; }
pre { max-width: 100%; overflow: auto; white-space: pre-wrap; }
</style>
</head>
<body>${safeHtml}</body>
</html>` : '', [safeHtml]);

    const resizeFrame = () => {
        const document = frameRef.current?.contentDocument;
        if (!document) return;
        const height = Math.max(document.body?.scrollHeight || 0, document.documentElement?.scrollHeight || 0);
        setFrameHeight(Math.max(120, Math.min(height + 8, 720)));
    };

    if (safeHtml) {
        return (
            <iframe
                ref={frameRef}
                title="Email content"
                srcDoc={sourceDocument}
                sandbox="allow-popups allow-popups-to-escape-sandbox allow-same-origin"
                referrerPolicy="no-referrer"
                onLoad={resizeFrame}
                className={`block w-full border-0 bg-white ${className}`}
                style={{ height: `${frameHeight}px` }}
            />
        );
    }

    return text ? <div className={`whitespace-pre-wrap break-words text-sm leading-relaxed ${className}`}>{text}</div> : null;
}

function attachmentUrl(attachment) {
    return attachment?.url || attachment?.preview_url || null;
}

function isImageAttachment(attachment) {
    const mime = String(attachment?.mime_type || attachment?.content_type || '').toLowerCase();
    const name = String(attachment?.name || attachment?.filename || attachmentUrl(attachment) || '');
    return mime.startsWith('image/') || /\.(jpe?g|png|gif|webp|bmp)$/i.test(name.split('?')[0]);
}

function formatBytes(value) {
    const bytes = Number(value);
    if (!Number.isFinite(bytes) || bytes <= 0) return '';
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export function EmailAttachments({ payload = {}, messageType = 'text' }) {
    let attachments = Array.isArray(payload.attachments) ? payload.attachments.filter(Boolean) : [];
    const legacyUrl = payload.preview_url || payload.url || payload.file_url;
    if (attachments.length === 0 && legacyUrl) {
        attachments = [{
            name: payload.filename || 'attachment',
            url: legacyUrl,
            mime_type: payload.mime_type,
            size: payload.file_size,
            type: messageType,
        }];
    }

    if (attachments.length === 0) {
        return payload.has_attachments ? (
            <div className="mt-3 flex items-center gap-2 rounded-lg bg-neutral-50 p-2.5 text-xs text-neutral-500 dark:bg-neutral-800/60 dark:text-neutral-400">
                <Paperclip className="h-4 w-4 shrink-0" />
                <span>Source attachment is available in the connected mailbox</span>
            </div>
        ) : null;
    }

    return (
        <div className="mt-4 border-t border-neutral-100 pt-3 dark:border-neutral-800">
            <p className="mb-2 text-[11px] font-bold uppercase text-neutral-400">{attachments.length === 1 ? 'Attachment' : `${attachments.length} attachments`}</p>
            <div className="flex flex-wrap gap-2">
                {attachments.map((attachment, index) => {
                    const url = attachmentUrl(attachment);
                    const name = attachment.name || attachment.filename || `attachment-${index + 1}`;
                    const size = formatBytes(attachment.size ?? attachment.size_bytes);
                    if (isImageAttachment(attachment) && url) {
                        return (
                            <a key={`${name}-${index}`} href={url} target="_blank" rel="noopener noreferrer" className="group max-w-sm overflow-hidden rounded-lg border border-neutral-200 bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-800">
                                <img src={url} alt={name} className="max-h-64 w-full object-contain" loading="lazy" />
                                <div className="flex items-center gap-2 px-3 py-2 text-xs text-neutral-500">
                                    <span className="max-w-xs truncate">{name}</span>
                                    <ExternalLink className="h-3.5 w-3.5 shrink-0" />
                                </div>
                            </a>
                        );
                    }

                    const content = (
                        <>
                            <Paperclip className="h-4 w-4 shrink-0 text-brand-600" />
                            <span className="max-w-xs truncate">{name}</span>
                            {size && <span className="text-[10px] text-neutral-400">{size}</span>}
                            {url && <ExternalLink className="h-3.5 w-3.5 shrink-0" />}
                        </>
                    );
                    return url ? (
                        <a key={`${name}-${index}`} href={url} target="_blank" rel="noopener noreferrer" download={name} className="inline-flex items-center gap-2 rounded-lg border border-neutral-200 bg-neutral-50 px-3 py-2 text-xs font-semibold text-neutral-700 hover:border-brand-300 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                            {content}
                        </a>
                    ) : (
                        <div key={`${name}-${index}`} className="inline-flex items-center gap-2 rounded-lg border border-neutral-200 bg-neutral-50 px-3 py-2 text-xs text-neutral-500 dark:border-neutral-700 dark:bg-neutral-800">
                            {content}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
