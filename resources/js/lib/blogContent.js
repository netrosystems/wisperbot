const blockSelector = 'p,h2,h3,h4,blockquote,ul,ol,figure,table,pre,hr';

const slugify = (value) => value.toLowerCase().trim()
    .replace(/[^a-z0-9\s-]/g, '')
    .replace(/[\s-]+/g, '-')
    .replace(/^-|-$/g, '') || 'section';

export function normalizeBlogHtml(html = '') {
    if (typeof DOMParser === 'undefined' || !html) return html || '';
    const document = new DOMParser().parseFromString(`<div id="blog-editor-root">${html}</div>`, 'text/html');
    const root = document.getElementById('blog-editor-root');
    if (!root) return html;

    for (let pass = 0; pass < 3; pass += 1) {
        root.querySelectorAll('pre,p,h2,h3,h4').forEach((node) => {
            if (node.querySelector(blockSelector)) node.replaceWith(...node.childNodes);
        });
    }

    const used = new Set();
    root.querySelectorAll('h2,h3,h4').forEach((heading) => {
        const base = slugify(heading.textContent || '');
        let id = base;
        let suffix = 2;
        while (used.has(id)) id = `${base}-${suffix++}`;
        used.add(id);
        heading.id = id;
    });

    root.querySelectorAll('img').forEach((image) => {
        image.loading = 'lazy';
        image.decoding = 'async';
    });
    return root.innerHTML;
}

export function analyzeBlogHtml(html = '') {
    if (typeof DOMParser === 'undefined') return { words: 0, headings: 0, images: 0, missingAlt: 0, tables: 0, questions: 0 };
    const document = new DOMParser().parseFromString(html, 'text/html');
    const words = (document.body.textContent || '').trim().split(/\s+/).filter(Boolean).length;
    return {
        words,
        headings: document.querySelectorAll('h2,h3').length,
        images: document.querySelectorAll('img').length,
        missingAlt: [...document.querySelectorAll('img')].filter((image) => !image.alt.trim()).length,
        tables: document.querySelectorAll('table').length,
        questions: [...document.querySelectorAll('h2,h3')].filter((heading) => heading.textContent.trim().endsWith('?')).length,
    };
}
