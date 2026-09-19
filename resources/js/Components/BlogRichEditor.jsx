import { useEffect, useMemo, useRef, useState } from 'react';
import axios from 'axios';
import { Bold, Code, CodeXml, Heading2, Heading3, Image, Italic, Link2, List, ListOrdered, Minus, Pilcrow, Quote, Redo2, Table2, Undo2 } from 'lucide-react';
import { analyzeBlogHtml, normalizeBlogHtml } from '@/lib/blogContent';

const actions = [
    ['formatBlock', Pilcrow, 'Paragraph', 'p'], ['bold', Bold, 'Bold'], ['italic', Italic, 'Italic'],
    ['formatBlock', Heading2, 'Heading 2', 'h2'], ['formatBlock', Heading3, 'Heading 3', 'h3'],
    ['insertUnorderedList', List, 'Bulleted list'], ['insertOrderedList', ListOrdered, 'Numbered list'],
    ['formatBlock', Quote, 'Quote', 'blockquote'], ['undo', Undo2, 'Undo'], ['redo', Redo2, 'Redo'],
];

export default function BlogRichEditor({ value, onChange, onUploading }) {
    const editor = useRef(null);
    const fileInput = useRef(null);
    const [sourceMode, setSourceMode] = useState(false);
    const normalizedValue = useMemo(() => normalizeBlogHtml(value || ''), [value]);
    const health = useMemo(() => analyzeBlogHtml(normalizedValue), [normalizedValue]);

    useEffect(() => {
        if (!sourceMode && editor.current && editor.current.innerHTML !== normalizedValue) editor.current.innerHTML = normalizedValue;
        if (!sourceMode && normalizedValue !== (value || '')) onChange(normalizedValue);
    }, [normalizedValue, onChange, sourceMode, value]);

    const commit = () => {
        const html = normalizeBlogHtml(editor.current?.innerHTML || '');
        if (editor.current && editor.current.innerHTML !== html) editor.current.innerHTML = html;
        onChange(html);
    };
    const run = (command, commandValue = null) => {
        editor.current?.focus();
        document.execCommand(command, false, commandValue);
        commit();
    };
    const addCodeBlock = () => {
        const selection = window.getSelection();
        const current = selection?.anchorNode?.nodeType === window.Node.ELEMENT_NODE ? selection.anchorNode : selection?.anchorNode?.parentElement;
        run('formatBlock', current?.closest?.('pre') ? 'p' : 'pre');
    };
    const addLink = () => {
        const url = window.prompt('Paste a secure link (https://)');
        if (url && /^(https?:\/\/|mailto:|\/|#)/i.test(url)) run('createLink', url);
    };
    const addTable = () => run('insertHTML', '<table><thead><tr><th>Column 1</th><th>Column 2</th><th>Column 3</th></tr></thead><tbody><tr><td>Value</td><td>Value</td><td>Value</td></tr><tr><td>Value</td><td>Value</td><td>Value</td></tr></tbody></table><p><br></p>');
    const upload = async (file) => {
        if (!file) return;
        onUploading?.(true);
        const form = new FormData(); form.append('image', file);
        try {
            const { data } = await axios.post(route('admin.blog.upload'), form, { headers: { 'Content-Type': 'multipart/form-data' } });
            const alt = file.name.replace(/\.[^/.]+$/, '').replace(/[-_]+/g, ' ').replace(/[<>&"]/g, '');
            run('insertHTML', `<figure><img src="${data.url}" alt="${alt}" loading="lazy" decoding="async"><figcaption>${alt}</figcaption></figure><p><br></p>`);
        } finally { onUploading?.(false); if (fileInput.current) fileInput.current.value = ''; }
    };
    const toggleSource = () => {
        if (sourceMode) onChange(normalizeBlogHtml(value || ''));
        setSourceMode((current) => !current);
    };
    const toolClass = 'rounded-lg p-2 text-neutral-600 hover:bg-white hover:text-brand-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-500 dark:text-neutral-300 dark:hover:bg-neutral-700';

    return (
        <div className="overflow-hidden rounded-xl border border-neutral-300 bg-white dark:border-neutral-700 dark:bg-neutral-900">
            <div className="flex flex-wrap items-center gap-1 border-b border-neutral-200 bg-neutral-50 p-2 dark:border-neutral-700 dark:bg-neutral-800">
                {!sourceMode && actions.map(([command, Icon, label, commandValue], index) => <button key={`${command}-${index}`} type="button" title={label} aria-label={label} onMouseDown={(event) => { event.preventDefault(); run(command, commandValue); }} className={toolClass}><Icon className="h-4 w-4" /></button>)}
                {!sourceMode && <><button type="button" title="Code block" aria-label="Code block" onMouseDown={(event) => { event.preventDefault(); addCodeBlock(); }} className={toolClass}><Code className="h-4 w-4" /></button><button type="button" title="Horizontal rule" aria-label="Horizontal rule" onMouseDown={(event) => { event.preventDefault(); run('insertHorizontalRule'); }} className={toolClass}><Minus className="h-4 w-4" /></button><button type="button" title="Insert table" aria-label="Insert table" onMouseDown={(event) => { event.preventDefault(); addTable(); }} className={toolClass}><Table2 className="h-4 w-4" /></button><button type="button" title="Add link" aria-label="Add link" onMouseDown={(event) => { event.preventDefault(); addLink(); }} className={toolClass}><Link2 className="h-4 w-4" /></button><button type="button" title="Upload image" aria-label="Upload image" onMouseDown={(event) => { event.preventDefault(); fileInput.current?.click(); }} className={toolClass}><Image className="h-4 w-4" /></button></>}
                <button type="button" title={sourceMode ? 'Visual editor' : 'Edit HTML source'} aria-label={sourceMode ? 'Visual editor' : 'Edit HTML source'} onClick={toggleSource} className={`${toolClass} ml-auto ${sourceMode ? 'bg-brand-50 text-brand-700 dark:bg-brand-950/40' : ''}`}><CodeXml className="h-4 w-4" /></button>
                <input ref={fileInput} type="file" accept="image/jpeg,image/png,image/webp,image/gif" className="hidden" onChange={(event) => upload(event.target.files?.[0])} />
            </div>
            {sourceMode ? <textarea value={value || ''} onChange={(event) => onChange(event.target.value)} spellCheck={false} className="min-h-[440px] w-full resize-y bg-neutral-950 p-6 font-mono text-sm leading-6 text-neutral-100 outline-none" aria-label="Article HTML source" /> : <div ref={editor} contentEditable suppressContentEditableWarning onInput={commit} onBlur={commit} className="cms-prose blog-prose min-h-[440px] max-w-none p-6 text-neutral-800 outline-none dark:text-neutral-100" data-placeholder="Start writing your article…" />}
            <div className="flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-neutral-200 px-4 py-2 text-xs text-neutral-500 dark:border-neutral-700">
                <span>{health.words} words</span><span>{health.headings} headings</span><span>{health.images} images</span><span>{health.tables} tables</span><span>{health.questions} FAQ questions</span>
                {health.missingAlt > 0 && <span className="font-semibold text-red-600">{health.missingAlt} image{health.missingAlt === 1 ? '' : 's'} missing alt text</span>}
                {health.words > 150 && health.headings === 0 && <span className="font-semibold text-amber-600">Add an H2 for navigation and search clarity</span>}
            </div>
        </div>
    );
}
