import { router, useForm } from '@inertiajs/react';
import { AlertCircle, CheckCircle2, Clock, FileText, Globe, HelpCircle, Pencil, Plus, RefreshCw, ShieldCheck, Trash, Trash2, Type, Upload, Video, X, Zap } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Trans, useTranslation } from 'react-i18next';
import { confirmDialog } from '@/Components/ConfirmDialog';

const SOURCE_TYPES = {
    sitemap: { icon: Globe, labelKey: 'smart_bot.source_website' },
    url: { icon: FileText, labelKey: 'smart_bot.source_page' },
    file: { icon: Upload, labelKey: 'smart_bot.source_file' },
    text: { icon: Type, labelKey: 'smart_bot.source_text' },
    faq: { icon: HelpCircle, labelKey: 'smart_bot.source_faq' },
    video: { icon: Video, labelKey: 'ai.source_video' },
};

// Video records stay readable and editable; videos linked inside pages and
// files are found automatically, so they are not a separate choice.
const ADD_SOURCE_TYPES = ['sitemap', 'url', 'file', 'text', 'faq'];

const HINT_KEYS = {
    sitemap: 'smart_bot.source_website_hint',
    url: 'smart_bot.source_page_hint',
    file: 'smart_bot.source_file_hint',
    text: 'smart_bot.source_text_hint',
    faq: 'smart_bot.source_faq_hint',
};

const STATUS_CONFIG = {
    indexed: { color: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300', icon: CheckCircle2 },
    pending: { color: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300', icon: Clock },
    indexing: { color: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300', icon: Zap },
    extracting: { color: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300', icon: Zap },
    validating: { color: 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300', icon: ShieldCheck },
    degraded: { color: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300', icon: AlertCircle },
    error: { color: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300', icon: AlertCircle },
};

const READING = ['pending', 'extracting', 'validating', 'indexing'];

const inputCls = 'w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm text-neutral-900 placeholder-neutral-400 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/25 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-100';

const emptySource = (sourceType) => ({ source_type: sourceType, source_ref: '', title: '', file: null, authoritative: false });

/**
 * What the bot knows: its sources, how far each has been read, and one
 * "Add source" dialog for a website, a page, a file, pasted text or Q&A.
 * Sources are used as soon as they are read.
 */
export default function KnowledgeSources({ kb, kbUploadMaxKb = 20480, kbUploadMaxMb = 20 }) {
    const { t } = useTranslation();
    const documents = useMemo(() => kb.documents ?? [], [kb.documents]);
    const [adding, setAdding] = useState(null);
    const [editingVideo, setEditingVideo] = useState(null);
    const [faqPairs, setFaqPairs] = useState([{ question: '', answer: '' }]);
    const [fileError, setFileError] = useState('');
    const [dragOver, setDragOver] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState({});
    const [query, setQuery] = useState('');
    const fileRef = useRef();
    const videoEditForm = useForm({ title: '', video_url: '', video_transcript: '', thumbnail_url: '', trigger_phrases: '' });
    const maxFileBytes = Number(kbUploadMaxKb) * 1024;

    const reading = documents.some(doc => READING.includes(doc.status));
    const ready = documents.filter(doc => doc.status === 'indexed').length;
    const visible = useMemo(() => documents.filter(doc => !query || `${doc.title ?? ''} ${doc.source_ref ?? ''}`.toLowerCase().includes(query.toLowerCase())), [documents, query]);

    // Sources are read in the background: refresh until every one is done.
    useEffect(() => {
        if (!reading || adding || editingVideo) return undefined;
        const timer = window.setInterval(() => router.reload({ only: ['kb'], preserveScroll: true, preserveState: true }), 8000);

        return () => window.clearInterval(timer);
    }, [reading, adding, editingVideo]);

    const openAdd = (sourceType = 'sitemap') => {
        setAdding(emptySource(sourceType));
        setFaqPairs([{ question: '', answer: '' }]);
        setFileError('');
        setErrors({});
    };
    const setField = (field, value) => setAdding(previous => ({ ...previous, [field]: value }));

    const selectFile = (file) => {
        if (!file) return;
        const extension = file.name.split('.').pop()?.toLowerCase();
        if (!['pdf', 'docx', 'txt', 'md'].includes(extension)) {
            setFileError(t('smart_bot.file_type_error'));
            if (fileRef.current) fileRef.current.value = '';
            return;
        }
        if (file.size > maxFileBytes) {
            setFileError(t('smart_bot.file_size_error', { size: kbUploadMaxMb }));
            if (fileRef.current) fileRef.current.value = '';
            return;
        }
        setFileError('');
        setField('file', file);
    };

    const submit = (e) => {
        e.preventDefault();
        const formData = new FormData();
        formData.append('source_type', adding.source_type);
        formData.append('title', adding.title);
        formData.append('source_ref', adding.source_type === 'faq' ? JSON.stringify(faqPairs.filter(pair => pair.question.trim())) : adding.source_ref);
        if (adding.file) formData.append('file', adding.file);
        formData.append('authoritative', adding.authoritative ? '1' : '0');
        setProcessing(true);
        router.post(route('client.ai.knowledge-bases.documents.add', kb.uuid), formData, {
            preserveScroll: true,
            onSuccess: () => setAdding(null),
            onError: (bag) => setErrors(bag),
            onFinish: () => setProcessing(false),
        });
    };

    const remove = async (doc) => {
        if (await confirmDialog({ message: t('ai.remove_document_confirm'), confirmLabel: t('common.remove') })) {
            router.delete(route('client.ai.documents.destroy', doc.uuid), { preserveScroll: true });
        }
    };

    const openVideoEdit = (doc) => {
        const resource = doc.resource_json ?? {};
        videoEditForm.setData({
            title: doc.title ?? '',
            video_url: resource.canonical_url ?? '',
            video_transcript: resource.transcript ?? '',
            thumbnail_url: resource.thumbnail_url ?? '',
            trigger_phrases: resource.trigger_phrases ?? '',
        });
        videoEditForm.clearErrors();
        setEditingVideo(doc);
    };
    const saveVideo = (e) => {
        e.preventDefault();
        videoEditForm.put(route('client.ai.documents.update', editingVideo.uuid), { preserveScroll: true, onSuccess: () => setEditingVideo(null) });
    };

    return (
        <div className="space-y-4">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p className="text-sm text-neutral-500 dark:text-neutral-400">
                    {documents.length === 0
                        ? t('smart_bot.knowledge_empty_summary')
                        : reading
                            ? t('smart_bot.knowledge_reading_summary', { ready, total: documents.length })
                            : t('smart_bot.knowledge_ready_summary', { ready, total: documents.length })}
                </p>
                {documents.length > 0 && (
                    <button type="button" onClick={() => openAdd()} className="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                        <Plus className="h-4 w-4" /> {t('smart_bot.add_source')}
                    </button>
                )}
            </div>

            {documents.length === 0 ? (
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {ADD_SOURCE_TYPES.map(type => {
                        const { icon: Icon, labelKey } = SOURCE_TYPES[type];

                        return (
                            <button key={type} type="button" onClick={() => openAdd(type)} className="group relative rounded-xl border border-neutral-200 bg-white p-4 text-left transition hover:border-brand-300 hover:shadow-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:border-brand-700">
                                {type === 'sitemap' && <span className="absolute right-3 top-3 rounded-full bg-brand-50 px-2 py-0.5 text-[10px] font-semibold text-brand-700 dark:bg-brand-900/30 dark:text-brand-300">{t('smart_bot.recommended')}</span>}
                                <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-neutral-100 text-neutral-600 group-hover:bg-brand-50 group-hover:text-brand-600 dark:bg-neutral-800 dark:text-neutral-300"><Icon className="h-4 w-4" /></span>
                                <span className="mt-3 block text-sm font-semibold text-neutral-900 dark:text-white">{t(labelKey)}</span>
                                <span className="mt-1 block text-xs leading-5 text-neutral-500">{t(HINT_KEYS[type], { size: kbUploadMaxMb })}</span>
                            </button>
                        );
                    })}
                </div>
            ) : (
                <div className="overflow-hidden rounded-xl border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
                    {documents.length > 6 && (
                        <div className="border-b border-neutral-100 p-3 dark:border-neutral-800">
                            <input value={query} onChange={e => setQuery(e.target.value)} placeholder={t('smart_bot.search_sources')} aria-label={t('smart_bot.search_sources')} className={inputCls} />
                        </div>
                    )}
                    <ul className="max-h-[32rem] divide-y divide-neutral-100 overflow-auto dark:divide-neutral-800">
                        {visible.map(doc => {
                            const { icon: TypeIcon, labelKey } = SOURCE_TYPES[doc.source_type] ?? SOURCE_TYPES.file;
                            const { color, icon: StatusIcon } = STATUS_CONFIG[doc.status] ?? STATUS_CONFIG.pending;

                            return (
                                <li key={doc.id} className="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:gap-4">
                                    <div className="flex min-w-0 flex-1 items-start gap-3">
                                        <span className="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-neutral-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400" title={t(labelKey)}><TypeIcon className="h-3.5 w-3.5" /></span>
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-medium text-neutral-900 dark:text-neutral-100">
                                                {doc.title || (['text', 'faq'].includes(doc.source_type) ? t(labelKey) : doc.source_ref) || '—'}
                                                {doc.authoritative && <span className="ml-2 rounded-full bg-brand-50 px-1.5 py-0.5 align-middle text-[10px] font-semibold text-brand-700 dark:bg-brand-900/30 dark:text-brand-300">{t('smart_bot.official_badge')}</span>}
                                            </p>
                                            {doc.source_type === 'video' ? (
                                                <a href={doc.resource_json?.canonical_url} target="_blank" rel="noreferrer" className="mt-0.5 block truncate text-xs text-brand-600 hover:underline">{doc.resource_json?.provider} · {t('ai.preview_video')}</a>
                                            ) : ['url', 'sitemap'].includes(doc.source_type) && doc.title && (
                                                <p className="mt-0.5 truncate text-xs text-neutral-400">{doc.canonical_url || doc.source_ref}</p>
                                            )}
                                            {doc.error_message && <p className="mt-1 text-xs text-red-600 dark:text-red-300">{doc.error_message}</p>}
                                            {doc.status === 'pending' && !doc.error_message && <p className="mt-1 text-xs text-amber-600 dark:text-amber-300">{t('ai.doc_pending_hint')}</p>}
                                        </div>
                                    </div>
                                    <div className="flex items-center justify-between gap-2 pl-10 sm:justify-end sm:pl-0">
                                        <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${color}`}>
                                            <StatusIcon className="h-3 w-3" /> {t(`ai.doc_status_${doc.status}`)}
                                        </span>
                                        <div className="flex items-center">
                                            {doc.source_type === 'video' && (
                                                <button type="button" onClick={() => openVideoEdit(doc)} title={t('common.edit')} aria-label={t('common.edit')} className="rounded-md p-1.5 text-neutral-400 hover:bg-brand-50 hover:text-brand-600 dark:hover:bg-brand-900/20"><Pencil className="h-3.5 w-3.5" /></button>
                                            )}
                                            <button type="button" onClick={() => router.post(route('client.ai.documents.reindex', doc.uuid), {}, { preserveScroll: true })} title={t('ai.reindex')} aria-label={t('ai.reindex')} className="rounded-md p-1.5 text-neutral-400 hover:bg-brand-50 hover:text-brand-600 dark:hover:bg-brand-900/20"><RefreshCw className="h-3.5 w-3.5" /></button>
                                            <button type="button" onClick={() => remove(doc)} title={t('common.remove')} aria-label={t('common.remove')} className="rounded-md p-1.5 text-neutral-400 hover:bg-red-50 hover:text-red-500 dark:hover:bg-red-900/20"><Trash2 className="h-3.5 w-3.5" /></button>
                                        </div>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                </div>
            )}

            {adding && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="add-source-title">
                    <form onSubmit={submit} className="max-h-[90vh] w-full max-w-xl overflow-y-auto rounded-2xl bg-white shadow-2xl dark:bg-neutral-900">
                        <div className="flex items-center justify-between border-b border-neutral-100 px-6 pb-4 pt-5 dark:border-neutral-800">
                            <h3 id="add-source-title" className="text-base font-semibold text-neutral-900 dark:text-neutral-100">{t('smart_bot.add_source')}</h3>
                            <button type="button" onClick={() => setAdding(null)} aria-label={t('common.cancel')} className="rounded-lg p-1 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-600 dark:hover:bg-neutral-800"><X className="h-4 w-4" /></button>
                        </div>
                        <div className="space-y-4 px-6 py-4">
                            <div role="radiogroup" aria-label={t('ai.source_type')} className="grid grid-cols-5 gap-1 rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800">
                                {ADD_SOURCE_TYPES.map(type => {
                                    const { icon: Icon, labelKey } = SOURCE_TYPES[type];

                                    return (
                                        <button key={type} type="button" role="radio" aria-checked={adding.source_type === type}
                                            onClick={() => { setAdding(previous => ({ ...emptySource(type), title: previous.title, authoritative: previous.authoritative })); setErrors({}); setFileError(''); }}
                                            className={`flex flex-col items-center gap-0.5 rounded-md px-1 py-1.5 text-[11px] font-medium transition ${adding.source_type === type ? 'bg-white text-brand-600 shadow-sm dark:bg-neutral-700 dark:text-brand-400' : 'text-neutral-500 hover:text-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-200'}`}>
                                            <Icon className="h-3.5 w-3.5" /> {t(labelKey)}
                                        </button>
                                    );
                                })}
                            </div>
                            <p className="text-xs leading-5 text-neutral-500 dark:text-neutral-400">{t(HINT_KEYS[adding.source_type], { size: kbUploadMaxMb })}</p>

                            {adding.source_type === 'file' ? (
                                <div
                                    onDragOver={e => { e.preventDefault(); setDragOver(true); }}
                                    onDragLeave={() => setDragOver(false)}
                                    onDrop={e => { e.preventDefault(); setDragOver(false); selectFile(e.dataTransfer.files[0]); }}
                                    onClick={() => fileRef.current?.click()}
                                    className={`cursor-pointer rounded-lg border-2 border-dashed p-6 text-center transition ${dragOver ? 'border-brand-500 bg-brand-50 dark:bg-brand-900/20' : 'border-neutral-300 hover:border-brand-400 dark:border-neutral-600'}`}
                                >
                                    <input ref={fileRef} type="file" accept=".pdf,.docx,.txt,.md" className="hidden" onChange={e => selectFile(e.target.files[0])} />
                                    <Upload className="mx-auto mb-2 h-6 w-6 text-neutral-400" />
                                    {adding.file
                                        ? <p className="text-sm font-medium text-brand-600 dark:text-brand-400">{adding.file.name}</p>
                                        : <p className="text-sm text-neutral-600 dark:text-neutral-400"><Trans i18nKey="ai.drop_a_file_or_browse" components={{ 1: <span className="font-medium text-brand-600 dark:text-brand-400" /> }} /></p>}
                                    {(fileError || errors.file) && <p className="mt-2 text-xs font-medium text-red-500">{fileError || errors.file}</p>}
                                </div>
                            ) : adding.source_type === 'text' ? (
                                <label className="block">
                                    <span className="mb-1 block text-xs font-medium text-neutral-700 dark:text-neutral-300">{t('ai.text_content')}</span>
                                    <textarea required rows={8} value={adding.source_ref} onChange={e => setField('source_ref', e.target.value)} placeholder={t('ai.text_content_placeholder')} className={`${inputCls} resize-y`} />
                                </label>
                            ) : adding.source_type === 'faq' ? (
                                <div className="space-y-2">
                                    <div className="max-h-64 space-y-3 overflow-y-auto pr-1">
                                        {faqPairs.map((pair, i) => (
                                            <div key={i} className="space-y-2 rounded-lg border border-neutral-200 bg-neutral-50 p-3 dark:border-neutral-700 dark:bg-neutral-800/50">
                                                <div className="flex items-center gap-2">
                                                    <span className="w-5 shrink-0 text-xs font-semibold text-neutral-400">Q{i + 1}</span>
                                                    <input required={i === 0} value={pair.question} onChange={e => setFaqPairs(pairs => pairs.map((p, idx) => idx === i ? { ...p, question: e.target.value } : p))} placeholder={t('ai.faq_question_placeholder')} aria-label={`Q${i + 1}`} className={inputCls} />
                                                    {faqPairs.length > 1 && <button type="button" onClick={() => setFaqPairs(pairs => pairs.filter((_, idx) => idx !== i))} aria-label={t('common.remove')} className="text-neutral-300 hover:text-red-400"><Trash className="h-3.5 w-3.5" /></button>}
                                                </div>
                                                <div className="flex items-start gap-2">
                                                    <span className="mt-2 w-5 shrink-0 text-xs font-semibold text-neutral-400">A</span>
                                                    <textarea required={i === 0} rows={2} value={pair.answer} onChange={e => setFaqPairs(pairs => pairs.map((p, idx) => idx === i ? { ...p, answer: e.target.value } : p))} placeholder={t('ai.faq_answer_placeholder')} aria-label={`A${i + 1}`} className={`${inputCls} resize-none`} />
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                    <button type="button" onClick={() => setFaqPairs(pairs => [...pairs, { question: '', answer: '' }])} className="w-full rounded-lg border border-dashed border-neutral-300 py-1.5 text-xs text-neutral-500 hover:border-brand-400 hover:text-brand-600 dark:border-neutral-600">{t('ai.add_another_qa')}</button>
                                </div>
                            ) : (
                                <label className="block">
                                    <span className="mb-1 block text-xs font-medium text-neutral-700 dark:text-neutral-300">{adding.source_type === 'sitemap' ? t('smart_bot.website_address') : t('smart_bot.page_address')}</span>
                                    <input required type="text" inputMode="url" value={adding.source_ref} onChange={e => setField('source_ref', e.target.value)} placeholder={adding.source_type === 'sitemap' ? 'https://example.com' : 'https://example.com/help/article'} className={inputCls} />
                                </label>
                            )}
                            {errors.source_ref && <p className="text-xs font-medium text-red-500">{errors.source_ref}</p>}

                            {adding.source_type !== 'faq' && (
                                <label className="block">
                                    <span className="mb-1 block text-xs font-medium text-neutral-700 dark:text-neutral-300">{t('ai.title_label')} <span className="font-normal text-neutral-400">({t('common.optional')})</span></span>
                                    <input value={adding.title} onChange={e => setField('title', e.target.value)} placeholder={t('ai.title_placeholder')} className={inputCls} />
                                </label>
                            )}

                            <label className="flex items-start gap-2 rounded-lg border border-neutral-200 p-3 text-xs text-neutral-600 dark:border-neutral-700 dark:text-neutral-300">
                                <input type="checkbox" checked={adding.authoritative} onChange={e => setField('authoritative', e.target.checked)} className="mt-0.5 rounded border-neutral-300 text-brand-600" />
                                <span><strong className="block text-neutral-800 dark:text-neutral-100">{t('smart_bot.official_source')}</strong>{t('smart_bot.official_source_hint')}</span>
                            </label>
                        </div>
                        <div className="flex gap-2 border-t border-neutral-100 px-6 py-4 dark:border-neutral-800">
                            <button type="submit" disabled={processing || (adding.source_type === 'file' && !adding.file)} className="flex-1 rounded-lg bg-brand-600 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-60">{processing ? t('ai.adding') : t('smart_bot.add_and_read')}</button>
                            <button type="button" onClick={() => setAdding(null)} className="rounded-lg border border-neutral-300 px-4 py-2 text-sm text-neutral-700 hover:bg-neutral-50 dark:border-neutral-600 dark:text-neutral-300 dark:hover:bg-neutral-800">{t('common.cancel')}</button>
                        </div>
                    </form>
                </div>
            )}

            {editingVideo && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm" role="dialog" aria-modal="true">
                    <form onSubmit={saveVideo} className="w-full max-w-md space-y-4 rounded-2xl bg-white p-6 shadow-2xl dark:bg-neutral-900">
                        <div className="flex items-center justify-between"><h3 className="font-semibold">{t('ai.edit_video')}</h3><button type="button" onClick={() => setEditingVideo(null)} aria-label={t('common.cancel')}><X className="h-4 w-4" /></button></div>
                        <input required value={videoEditForm.data.title} onChange={e => videoEditForm.setData('title', e.target.value)} placeholder={t('ai.title_label')} className={inputCls} />
                        <input required type="url" value={videoEditForm.data.video_url} onChange={e => videoEditForm.setData('video_url', e.target.value)} placeholder={t('ai.video_url')} className={inputCls} />
                        <textarea required rows={6} value={videoEditForm.data.video_transcript} onChange={e => videoEditForm.setData('video_transcript', e.target.value)} placeholder={t('ai.video_transcript')} className={`${inputCls} resize-none`} />
                        <input type="url" value={videoEditForm.data.thumbnail_url} onChange={e => videoEditForm.setData('thumbnail_url', e.target.value)} placeholder={t('ai.thumbnail_url')} className={inputCls} />
                        <input value={videoEditForm.data.trigger_phrases} onChange={e => videoEditForm.setData('trigger_phrases', e.target.value)} placeholder={t('ai.trigger_phrases')} className={inputCls} />
                        {Object.values(videoEditForm.errors).map((error, i) => <p key={i} className="text-xs text-red-500">{error}</p>)}
                        <div className="flex gap-2"><button disabled={videoEditForm.processing} className="flex-1 rounded-lg bg-brand-600 py-2 text-sm font-medium text-white">{t('common.save')}</button><button type="button" onClick={() => setEditingVideo(null)} className="rounded-lg border px-4 py-2 text-sm">{t('common.cancel')}</button></div>
                    </form>
                </div>
            )}
        </div>
    );
}
