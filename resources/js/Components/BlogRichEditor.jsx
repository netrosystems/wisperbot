import { useEffect, useRef } from 'react';
import axios from 'axios';
import { Bold, Code, Heading2, Heading3, Image, Italic, Link2, List, ListOrdered, Quote, Redo2, Undo2 } from 'lucide-react';

const actions = [
    ['bold', Bold, 'Bold'], ['italic', Italic, 'Italic'], ['formatBlock', Heading2, 'Heading 2', 'h2'],
    ['formatBlock', Heading3, 'Heading 3', 'h3'], ['insertUnorderedList', List, 'Bulleted list'],
    ['insertOrderedList', ListOrdered, 'Numbered list'], ['formatBlock', Quote, 'Quote', 'blockquote'],
    ['formatBlock', Code, 'Code block', 'pre'], ['undo', Undo2, 'Undo'], ['redo', Redo2, 'Redo'],
];

export default function BlogRichEditor({ value, onChange, onUploading }) {
    const editor = useRef(null);
    const fileInput = useRef(null);
    useEffect(() => { if (editor.current && editor.current.innerHTML !== value) editor.current.innerHTML = value || ''; }, [value]);

    const run = (command, commandValue = null) => {
        editor.current?.focus();
        document.execCommand(command, false, commandValue);
        onChange(editor.current?.innerHTML || '');
    };
    const addLink = () => {
        const url = window.prompt('Paste a secure link (https://)');
        if (url && /^(https?:\/\/|mailto:|\/|#)/i.test(url)) run('createLink', url);
    };
    const upload = async (file) => {
        if (!file) return;
        onUploading?.(true);
        const form = new FormData(); form.append('image', file);
        try {
            const { data } = await axios.post(route('admin.blog.upload'), form, { headers: { 'Content-Type': 'multipart/form-data' } });
            const alt = file.name.replace(/\.[^/.]+$/, '').replace(/[-_]+/g, ' ').replace(/[<>&"]/g, '');
            run('insertHTML', `<figure><img src="${data.url}" alt="${alt}" loading="lazy"><figcaption>${alt}</figcaption></figure>`);
        } finally { onUploading?.(false); if (fileInput.current) fileInput.current.value = ''; }
    };
    return (
        <div className="overflow-hidden rounded-xl border border-neutral-300 bg-white dark:border-neutral-700 dark:bg-neutral-900">
            <div className="flex flex-wrap items-center gap-1 border-b border-neutral-200 bg-neutral-50 p-2 dark:border-neutral-700 dark:bg-neutral-800">
                {actions.map(([command, Icon, label, commandValue], index) => <button key={`${command}-${index}`} type="button" title={label} onMouseDown={(event) => { event.preventDefault(); run(command, commandValue); }} className="rounded-lg p-2 text-neutral-600 hover:bg-white hover:text-brand-600 dark:text-neutral-300 dark:hover:bg-neutral-700"><Icon className="h-4 w-4" /></button>)}
                <button type="button" title="Add link" onClick={addLink} className="rounded-lg p-2 text-neutral-600 hover:bg-white hover:text-brand-600 dark:text-neutral-300 dark:hover:bg-neutral-700"><Link2 className="h-4 w-4" /></button>
                <button type="button" title="Upload image" onClick={() => fileInput.current?.click()} className="rounded-lg p-2 text-neutral-600 hover:bg-white hover:text-brand-600 dark:text-neutral-300 dark:hover:bg-neutral-700"><Image className="h-4 w-4" /></button>
                <input ref={fileInput} type="file" accept="image/jpeg,image/png,image/webp,image/gif" className="hidden" onChange={(event) => upload(event.target.files?.[0])} />
            </div>
            <div ref={editor} contentEditable suppressContentEditableWarning onInput={() => onChange(editor.current?.innerHTML || '')} className="cms-prose min-h-[440px] max-w-none p-6 text-neutral-800 outline-none dark:text-neutral-100" data-placeholder="Start writing your article…" />
            <div className="border-t border-neutral-200 px-4 py-2 text-xs text-neutral-400 dark:border-neutral-700">Rich HTML is sanitized on save. Use headings in order and add descriptive alt text to images for accessibility and SEO.</div>
        </div>
    );
}
