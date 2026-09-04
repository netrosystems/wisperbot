import { Head, Link, useForm, router, usePage } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import { ArrowLeft, Plus, Pencil, RefreshCw, Trash, Trash2, Globe, FileText, Type, X, Upload, CheckCircle2, Clock, Zap, AlertCircle, HelpCircle, Video, ShieldCheck, FlaskConical, Rocket, Eye, ToggleLeft, RotateCcw } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation, Trans } from 'react-i18next';

const SOURCE_TYPES = {
    url:     { icon: Globe,    labelKey: 'ai.source_url' },
    file:    { icon: Upload,   labelKey: 'ai.source_file' },
    text:    { icon: Type,     labelKey: 'ai.source_text' },
    sitemap: { icon: Globe,    labelKey: 'ai.source_sitemap' },
    faq:     { icon: HelpCircle, labelKey: 'ai.source_faq' },
    video:   { icon: Video, labelKey: 'ai.source_video' },
};

// Text, FAQ, and dedicated Video records remain readable for existing
// workspaces and older API clients. New client authoring uses these three
// guided ingestion paths; supported video links are discovered automatically.
const ADD_SOURCE_TYPES = ['sitemap', 'file', 'url'];

const SOURCE_CHOICES = [
    {
        type: 'sitemap',
        icon: Globe,
        title: 'Entire website',
        description: 'Enter your homepage. WisperBot automatically finds and checks the public pages.',
        recommendation: 'Best for most businesses',
    },
    {
        type: 'url',
        icon: FileText,
        title: 'Specific web page',
        description: 'Add one focused help article, policy page, product page, or public document URL.',
    },
    {
        type: 'file',
        icon: Upload,
        title: 'Upload files',
        description: 'Use a reviewed PDF, Word document, plain-text file, or Markdown file.',
    },
];

const STATUS_CONFIG = {
    indexed:  { color: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',   icon: CheckCircle2 },
    pending:  { color: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300', icon: Clock },
    indexing: { color: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',        icon: Zap },
    extracting: { color: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300', icon: Zap },
    validating: { color: 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300', icon: ShieldCheck },
    degraded: { color: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300', icon: AlertCircle },
    error:    { color: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',            icon: AlertCircle },
};

export default function AiKnowledgeBaseShow({ kb, kbUploadMaxKb = 20480, kbUploadMaxMb = 20, health = {}, guardedPublishing = false, efficiency = {} }) {
    const { t } = useTranslation();
    const { props } = usePage();
    const flash = props.flash ?? {};
    const [showAdd, setShowAdd] = useState(false);
    const [showEdit, setShowEdit] = useState(false);
    const [editingVideo, setEditingVideo] = useState(null);
    const [editingText, setEditingText] = useState(null);
    const [dragOver, setDragOver] = useState(false);
    const [faqPairs, setFaqPairs] = useState([{ question: '', answer: '' }]);
    const [fileError, setFileError] = useState('');
    const fileRef = useRef();

    const { data, setData, reset, errors, clearErrors } = useForm({
        source_type: 'sitemap',
        source_ref: '',
        title: '',
        file: null,
        video_url: '',
        video_transcript: '',
        thumbnail_url: '',
        trigger_phrases: '',
        authoritative: false,
        priority: 50,
    });
    const renameForm = useForm({
        name: kb.name ?? '',
        purpose: kb.purpose ?? '',
        language: kb.language ?? 'en',
        brand: kb.brand ?? '',
        audience: kb.audience ?? '',
    });
    const videoEditForm = useForm({ title: '', video_url: '', video_transcript: '', thumbnail_url: '', trigger_phrases: '' });
    const textEditForm = useForm({ title: '', source_ref: '' });
    const [processing, setProcessing] = useState(false);
    const [statusFilter, setStatusFilter] = useState('all');
    const [query, setQuery] = useState('');
    const [testQuestion, setTestQuestion] = useState('');
    const [testResult, setTestResult] = useState(null);
    const [testing, setTesting] = useState(false);

    const addFaqPair = () => setFaqPairs(p => [...p, { question: '', answer: '' }]);
    const removeFaqPair = (i) => setFaqPairs(p => p.filter((_, idx) => idx !== i));
    const updateFaqPair = (i, field, value) => setFaqPairs(p => p.map((pair, idx) => idx === i ? { ...pair, [field]: value } : pair));
    const maxFileBytes = Number(kbUploadMaxKb) * 1024;

    const openAddSource = (sourceType = 'sitemap') => {
        setData({
            source_type: sourceType,
            source_ref: '',
            title: '',
            file: null,
            video_url: '',
            video_transcript: '',
            thumbnail_url: '',
            trigger_phrases: '',
            authoritative: false,
            priority: 50,
        });
        clearErrors();
        setFileError('');
        setShowAdd(true);
    };

    const selectFile = (file) => {
        if (!file) return;

        const extension = file.name.split('.').pop()?.toLowerCase();
        if (!['pdf', 'docx', 'txt', 'md'].includes(extension)) {
            setFileError('Use a PDF, DOCX, TXT, or Markdown file for the most reliable answers.');
            setData('file', null);
            if (fileRef.current) fileRef.current.value = '';
            return;
        }

        if (file.size > maxFileBytes) {
            setFileError(`This file is too large. Please upload a file up to ${kbUploadMaxMb} MB, or increase the server upload limit first.`);
            setData('file', null);
            if (fileRef.current) fileRef.current.value = '';
            return;
        }

        setFileError('');
        setData('file', file);
        setData('source_type', 'file');
    };

    const handleAdd = (e) => {
        e.preventDefault();
        if (data.source_type === 'file' && data.file?.size > maxFileBytes) {
            setFileError(`This file is too large. Please upload a file up to ${kbUploadMaxMb} MB, or increase the server upload limit first.`);
            return;
        }

        setProcessing(true);
        const formData = new FormData();
        formData.append('source_type', data.source_type);
        formData.append('title', data.title);
        if (data.source_type === 'faq') {
            formData.append('source_ref', JSON.stringify(faqPairs.filter(p => p.question.trim())));
        } else {
            formData.append('source_ref', data.source_ref);
        }
        if (data.file) formData.append('file', data.file);
        if (data.source_type === 'video') {
            formData.append('video_url', data.video_url);
            formData.append('video_transcript', data.video_transcript);
            formData.append('thumbnail_url', data.thumbnail_url);
            formData.append('trigger_phrases', data.trigger_phrases);
        }
        formData.append('authoritative', data.authoritative ? '1' : '0');
        formData.append('priority', String(data.priority));
        router.post(route('client.ai.knowledge-bases.documents.add', kb.uuid), formData, {
            preserveScroll: true,
            onSuccess: () => { reset(); setFaqPairs([{ question: '', answer: '' }]); setShowAdd(false); setProcessing(false); setActiveStep(2); },
            onError: () => setProcessing(false),
        });
    };

    const handleDelete = (docId) => {
        if (confirm(t('ai.remove_document_confirm'))) {
            router.delete(route('client.ai.documents.destroy', docId), { preserveScroll: true });
        }
    };

    const handleReindex = (docId) => {
        router.post(route('client.ai.documents.reindex', docId), {}, { preserveScroll: true });
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

    const handleVideoEdit = (e) => {
        e.preventDefault();
        videoEditForm.put(route('client.ai.documents.update', editingVideo.uuid), {
            preserveScroll: true,
            onSuccess: () => setEditingVideo(null),
        });
    };

    const openTextEdit = (doc, suggestion = null) => {
        textEditForm.setData({ title: doc.title || 'Knowledge source', source_ref: suggestion ?? doc.extracted_content ?? doc.source_ref ?? '' });
        textEditForm.clearErrors();
        setEditingText(doc);
    };
    const requestSuggestion = async (doc) => {
        const response = await fetch(route('client.ai.documents.suggest-correction', doc.uuid), { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' } });
        const result = await response.json();
        if (result.suggestion) openTextEdit(doc, result.suggestion);
    };
    const handleTextEdit = (e) => {
        e.preventDefault();
        if (!confirm('Save this factual content as a new draft? Review all numbers, policies, dates, and URLs before continuing.')) return;
        textEditForm.put(route('client.ai.documents.update', editingText.uuid), { preserveScroll: true, onSuccess: () => setEditingText(null) });
    };

    const openEdit = () => {
        renameForm.clearErrors();
        renameForm.setData({
            name: kb.name ?? '',
            purpose: kb.purpose ?? '',
            language: kb.language ?? 'en',
            brand: kb.brand ?? '',
            audience: kb.audience ?? '',
        });
        setShowEdit(true);
    };

    const handleEdit = (e) => {
        e.preventDefault();
        renameForm.put(route('client.ai.knowledge-bases.update', kb.uuid), {
            preserveScroll: true,
            onSuccess: () => { setShowEdit(false); setActiveStep(2); },
        });
    };

    const handleDeleteKnowledgeBase = () => {
        if (confirm(t('ai.delete_kb_confirm', { name: kb.name }))) {
            router.delete(route('client.ai.knowledge-bases.destroy', kb.uuid));
        }
    };

    const handleDrop = (e) => {
        e.preventDefault();
        setDragOver(false);
        const file = e.dataTransfer.files[0];
        selectFile(file);
    };

    const hasRunningDocuments = useMemo(
        () => kb.documents?.some(d => ['pending', 'extracting', 'validating', 'indexing'].includes(d.status)) ?? false,
        [kb.documents],
    );
    const totalTokens = kb.documents?.reduce((s, d) => s + (d.tokens ?? 0), 0) ?? 0;
    const indexedCount = kb.documents?.filter(d => d.status === 'indexed').length ?? 0;
    const filteredDocuments = useMemo(() => (kb.documents ?? []).filter(doc => {
        const matchesText = !query || `${doc.title ?? ''} ${doc.source_ref ?? ''}`.toLowerCase().includes(query.toLowerCase());
        const matchesStatus = statusFilter === 'all' || doc.review_status === statusFilter || doc.status === statusFilter;
        return matchesText && matchesStatus;
    }), [kb.documents, query, statusFilter]);
    const isPublished = Boolean(kb.published_revision?.version);
    const hasDraft = Boolean(kb.draft_revision?.id);
    const recommendedStep = !kb.purpose ? 1 : !(kb.documents?.length) || hasRunningDocuments ? 2 : (health.warning || health.blocked || health.failed) ? 3 : kb.regression_status !== 'passed' ? 4 : 5;
    const [activeStep, setActiveStep] = useState(() => guardedPublishing ? (isPublished ? 6 : recommendedStep) : 2);

    const runTest = async (e) => {
        e.preventDefault();
        setTesting(true);
        try {
            const response = await fetch(route('client.ai.knowledge-bases.test', kb.uuid), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
                body: JSON.stringify({ question: testQuestion }),
            });
            setTestResult(await response.json());
        } finally { setTesting(false); }
    };

    const canOpenStep = (step) => {
        if (!guardedPublishing || step === 1) return true;
        if (step === 2) return isPublished || Boolean(kb.purpose);
        if (step === 3) return (kb.documents?.length ?? 0) > 0 && !hasRunningDocuments;
        if (step === 4) return (kb.documents?.length ?? 0) > 0 && !hasRunningDocuments && ((health.warning ?? 0) + (health.blocked ?? 0) + (health.failed ?? 0) === 0);
        if (step === 5) return (isPublished && !hasDraft) || kb.regression_status === 'passed' || testResult?.decision === 'answer';
        if (step === 6) return isPublished;

        return false;
    };

    useEffect(() => {
        if (!hasRunningDocuments || showAdd || showEdit) return undefined;

        const timer = window.setInterval(() => {
            router.reload({
                only: ['kb'],
                preserveScroll: true,
                preserveState: true,
            });
        }, 8000);

        return () => window.clearInterval(timer);
    }, [hasRunningDocuments, showAdd, showEdit]);

    useEffect(() => {
        if (!guardedPublishing) {
            setActiveStep(2);
        }
    }, [guardedPublishing]);

    return (
        <ClientLayout title={kb.name}>
            <Head title={`${kb.name} · ${t('ai.kb_title')}`} />
            <div className="space-y-6">
                {/* Header */}
                <div className="flex items-center gap-3">
                    <Link href={route('client.ai.knowledge-bases.index')} className="rounded-lg p-1.5 text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200 hover:bg-neutral-100 dark:hover:bg-neutral-800 transition">
                        <ArrowLeft className="h-4 w-4" />
                    </Link>
                    <div className="flex-1 min-w-0">
                        <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100 truncate">{kb.name}</h2>
                    </div>
                    <button
                        type="button"
                        onClick={() => guardedPublishing ? setActiveStep(1) : openEdit()}
                        title={t('common.edit')}
                        aria-label={`${t('common.edit')} ${kb.name}`}
                        className="rounded-lg border border-neutral-200 dark:border-neutral-700 p-2 text-neutral-500 hover:text-brand-600 hover:border-brand-300 hover:bg-brand-50 dark:hover:bg-brand-900/20 transition"
                    >
                        <Pencil className="h-4 w-4" />
                    </button>
                    <button
                        type="button"
                        onClick={handleDeleteKnowledgeBase}
                        title={t('common.delete')}
                        aria-label={`${t('common.delete')} ${kb.name}`}
                        className="rounded-lg border border-neutral-200 dark:border-neutral-700 p-2 text-neutral-500 hover:text-red-500 hover:border-red-300 hover:bg-red-50 dark:hover:bg-red-900/20 transition"
                    >
                        <Trash2 className="h-4 w-4" />
                    </button>
                </div>

                {flash.success && (
                    <div className="rounded-lg bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 text-green-800 dark:text-green-200 px-4 py-2.5 text-sm">
                        {flash.success}
                    </div>
                )}

                {guardedPublishing && (
                    <section className="rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900" aria-labelledby="setup-title">
                        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                            <div><p className="text-xs font-semibold uppercase tracking-wider text-brand-600">{isPublished ? 'Knowledge Base management' : 'Guided setup'}</p><h3 id="setup-title" className="mt-1 text-lg font-semibold text-neutral-900 dark:text-white">{isPublished ? 'Keep answers accurate and safely published' : 'Complete one step at a time'}</h3><p className="mt-1 text-sm text-neutral-500">Only the selected step is shown below. Draft changes never affect the current live revision.</p></div>
                            <div className="flex items-center gap-3"><div className="text-right"><p className="text-2xl font-semibold text-neutral-900 dark:text-white">{health.readiness ?? 0}%</p><p className="text-xs text-neutral-500">draft readiness</p></div><div className="h-12 w-12 rounded-full border-4 border-brand-200 p-1"><div className="h-full w-full rounded-full bg-brand-500" /></div></div>
                        </div>
                        <ol className="mt-5 grid gap-2 sm:grid-cols-3 xl:grid-cols-6" aria-label="Knowledge Base steps">
                            {[['Define', Pencil], ['Sources', Plus], ['Review', ShieldCheck], ['Test', FlaskConical], ['Publish', Rocket], ['Monitor', Eye]].map(([label, Icon], index) => {
                                const step = index + 1;
                                const done = step < recommendedStep || (isPublished && step <= 5);
                                const disabled = !canOpenStep(step);
                                return <li key={label}><button type="button" disabled={disabled} onClick={() => setActiveStep(step)} aria-current={step === activeStep ? 'step' : undefined} className={`w-full rounded-xl border p-3 text-left transition ${step === activeStep ? 'border-brand-400 bg-brand-50 ring-1 ring-brand-200 dark:bg-brand-900/20' : 'border-neutral-200 hover:border-brand-200 hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800'} disabled:cursor-not-allowed disabled:opacity-40`}><div className="flex items-center gap-2"><span className={`flex h-6 w-6 items-center justify-center rounded-full text-xs font-bold ${done ? 'bg-green-100 text-green-700' : 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300'}`}>{done ? '✓' : step}</span><Icon className="h-3.5 w-3.5 text-neutral-400" /></div><p className="mt-2 text-xs font-semibold text-neutral-800 dark:text-neutral-200">{label}</p></button></li>;
                            })}
                        </ol>
                    </section>
                )}

                {guardedPublishing && activeStep === 1 && (
                    <section className="mx-auto w-full max-w-3xl rounded-2xl border border-neutral-200 bg-white p-6 shadow-sm dark:border-neutral-700 dark:bg-neutral-900" aria-labelledby="define-step-title">
                        <div className="flex items-start gap-3"><div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-600"><Pencil className="h-4 w-4" /></div><div><p className="text-xs font-semibold uppercase tracking-wider text-brand-600">Step 1 of 5</p><h3 id="define-step-title" className="text-lg font-semibold">Define this Knowledge Base</h3><p className="mt-1 text-sm text-neutral-500">A focused purpose helps WisperBot reject unrelated content and answer consistently.</p></div></div>
                        <form onSubmit={handleEdit} className="mt-6 grid gap-4 sm:grid-cols-2">
                            <label className="text-sm font-medium text-neutral-700 dark:text-neutral-200">Name<input required value={renameForm.data.name} onChange={e => renameForm.setData('name', e.target.value)} className="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-2 dark:border-neutral-700 dark:bg-neutral-800" />{renameForm.errors.name && <span className="mt-1 block text-xs text-red-500">{renameForm.errors.name}</span>}</label>
                            <label className="text-sm font-medium text-neutral-700 dark:text-neutral-200">Language<input value={renameForm.data.language} onChange={e => renameForm.setData('language', e.target.value)} placeholder="en" className="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-2 dark:border-neutral-700 dark:bg-neutral-800" /></label>
                            <label className="text-sm font-medium text-neutral-700 dark:text-neutral-200">Brand or product<input value={renameForm.data.brand} onChange={e => renameForm.setData('brand', e.target.value)} placeholder="Example Support" className="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-2 dark:border-neutral-700 dark:bg-neutral-800" /></label>
                            <label className="text-sm font-medium text-neutral-700 dark:text-neutral-200">Intended customers<input value={renameForm.data.audience} onChange={e => renameForm.setData('audience', e.target.value)} placeholder="Customers using our web application" className="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-2 dark:border-neutral-700 dark:bg-neutral-800" /></label>
                            <label className="text-sm font-medium text-neutral-700 dark:text-neutral-200 sm:col-span-2">Purpose<textarea required rows={4} value={renameForm.data.purpose} onChange={e => renameForm.setData('purpose', e.target.value)} placeholder="Answer setup, billing, and troubleshooting questions for…" className="mt-1 w-full resize-none rounded-lg border border-neutral-300 px-3 py-2 dark:border-neutral-700 dark:bg-neutral-800" />{renameForm.errors.purpose && <span className="mt-1 block text-xs text-red-500">{renameForm.errors.purpose}</span>}</label>
                            <div className="flex justify-end sm:col-span-2"><button disabled={renameForm.processing} className="rounded-lg bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50">{renameForm.processing ? 'Saving…' : 'Save and add sources'} <span aria-hidden="true">→</span></button></div>
                        </form>
                    </section>
                )}

                {/* Stats */}
                {(activeStep === 2 || !guardedPublishing) && (kb.documents?.length ?? 0) > 0 && (
                    <div className="grid grid-cols-3 gap-3">
                        {[
                            { label: t('ai.total_documents'), value: kb.documents?.length ?? 0 },
                            { label: t('ai.indexed'), value: indexedCount },
                            { label: t('ai.total_tokens'), value: totalTokens.toLocaleString() },
                        ].map(({ label, value }) => (
                            <div key={label} className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-4 py-3">
                                <p className="text-lg font-semibold text-neutral-900 dark:text-neutral-100 leading-none">{value}</p>
                                <p className="text-xs text-neutral-500 dark:text-neutral-400 mt-1">{label}</p>
                            </div>
                        ))}
                    </div>
                )}

                {/* Documents Table */}
                {(activeStep === 2 || activeStep === 3 || !guardedPublishing) && (
                <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 overflow-hidden">
                    <div className="flex flex-col gap-3 border-b border-neutral-100 p-5 dark:border-neutral-800 sm:flex-row sm:items-center sm:justify-between">
                        <div><p className="text-xs font-semibold uppercase tracking-wider text-brand-600">{activeStep === 3 ? 'Step 3 of 5' : 'Step 2 of 5'}</p><h3 className="mt-1 text-lg font-semibold">{activeStep === 3 ? 'Review source quality' : (kb.documents?.length ?? 0) > 0 ? 'Knowledge sources' : 'Add your first source'}</h3><p className="mt-1 text-sm text-neutral-500">{activeStep === 3 ? 'Resolve every warning or blocker before testing. Open a finding to see the affected passage.' : (kb.documents?.length ?? 0) > 0 ? 'Manage the information WisperBot can use. Changes remain in draft until you publish.' : 'Choose one place to start. You can add more sources later.'}</p></div>
                        {activeStep === 2 && (kb.documents?.length ?? 0) > 0 && <button type="button" onClick={() => openAddSource()} className="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700"><Plus className="h-4 w-4" />Add source</button>}
                    </div>
                    {activeStep === 3 && <div className="grid gap-2 border-b border-neutral-100 p-4 dark:border-neutral-800 sm:grid-cols-4">{[['Ready', health.ready ?? 0, 'text-green-700 bg-green-50'], ['Needs review', health.warning ?? 0, 'text-amber-700 bg-amber-50'], ['Blocked', health.blocked ?? 0, 'text-red-700 bg-red-50'], ['Failed', health.failed ?? 0, 'text-red-700 bg-red-50']].map(([label, value, color]) => <div key={label} className={`rounded-lg px-3 py-2 ${color}`}><p className="text-base font-semibold">{value}</p><p className="text-xs">{label}</p></div>)}</div>}
                    {(kb.documents?.length ?? 0) > 0 && <div className="flex flex-col gap-2 border-b border-neutral-100 p-3 dark:border-neutral-800 sm:flex-row"><input value={query} onChange={e => setQuery(e.target.value)} placeholder="Search sources" aria-label="Search sources" className="flex-1 rounded-lg border border-neutral-200 bg-neutral-50 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800" /><select value={statusFilter} onChange={e => setStatusFilter(e.target.value)} aria-label="Filter sources by status" className="rounded-lg border border-neutral-200 bg-neutral-50 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800"><option value="all">All sources</option><option value="auto_approved">Ready</option><option value="needs_review">Needs review</option><option value="blocked">Blocked</option><option value="degraded">Failed</option></select></div>}
                    {(kb.documents?.length ?? 0) > 0 ? (
                        <div className="max-h-[32rem] overflow-auto">
                        <table className="min-w-full divide-y divide-neutral-100 dark:divide-neutral-800 text-sm">
                            <thead className="sticky top-0 z-10 bg-neutral-50 dark:bg-neutral-800">
                                <tr>
                                    {[
                                        { key: 'document', label: t('ai.col_document') },
                                        { key: 'type', label: t('ai.col_type') },
                                        { key: 'status', label: t('ai.col_status') },
                                        { key: 'tokens', label: t('ai.col_tokens') },
                                        { key: 'actions', label: '' },
                                    ].map(h => (
                                        <th key={h.key} className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-400 dark:text-neutral-500">{h.label}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-neutral-100 dark:divide-neutral-800">
                                {filteredDocuments.map(doc => {
                                    const { icon: TypeIcon, labelKey: typeLabelKey } = SOURCE_TYPES[doc.source_type] ?? SOURCE_TYPES.file;
                                    const { color, icon: StatusIcon } = STATUS_CONFIG[doc.status] ?? STATUS_CONFIG.pending;
                                    return (
                                        <tr key={doc.id} className="hover:bg-neutral-50 dark:hover:bg-neutral-800/40 transition">
                                            <td className="px-4 py-3 max-w-xs">
                                                <div className="flex items-center gap-3">
                                                    {doc.source_type === 'video' && doc.resource_json?.thumbnail_url && (
                                                        <img src={doc.resource_json.thumbnail_url} alt="" className="h-10 w-16 rounded-md object-cover bg-neutral-100" />
                                                    )}
                                                    <div className="min-w-0">
                                                        <p className="font-medium text-neutral-900 dark:text-neutral-100 truncate">{doc.title || doc.source_ref || '—'}</p>
                                                        {doc.source_type === 'video' ? (
                                                            <a href={doc.resource_json?.canonical_url} target="_blank" rel="noreferrer" className="text-xs text-brand-600 hover:underline truncate block mt-0.5">{doc.resource_json?.provider} · {t('ai.preview_video')}</a>
                                                        ) : doc.title && doc.source_ref && (
                                                            <p className="text-xs text-neutral-400 dark:text-neutral-500 truncate mt-0.5">{doc.source_ref}</p>
                                                        )}
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">
                                                <span className="inline-flex items-center gap-1 rounded-md bg-neutral-100 dark:bg-neutral-800 px-2 py-0.5 text-xs text-neutral-600 dark:text-neutral-400">
                                                    <TypeIcon className="h-3 w-3" /> {t(typeLabelKey)}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="max-w-xs space-y-1.5">
                                                    <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${color}`}>
                                                        <StatusIcon className="h-3 w-3" /> {t(`ai.doc_status_${doc.status}`)}
                                                    </span>
                                                    {doc.error_message && (
                                                        <p className="text-xs leading-relaxed text-red-600 dark:text-red-300">{doc.error_message}</p>
                                                    )}
                                                    {guardedPublishing && <p className="text-[11px] text-neutral-500">Quality {doc.quality_score ?? 0}% · {String(doc.review_status ?? 'needs_review').replaceAll('_', ' ')} · {doc.publication_status ?? 'draft'}</p>}
                                                    {(doc.quality_findings?.length ?? 0) > 0 && <details className="rounded-md bg-neutral-50 p-2 text-xs dark:bg-neutral-800"><summary className="cursor-pointer font-medium">Review {doc.quality_findings.length} finding{doc.quality_findings.length === 1 ? '' : 's'}</summary><div className="mt-2 space-y-2">{doc.quality_findings.map((finding, i) => <div key={`${finding.code}-${i}`} className={finding.severity === 'blocker' ? 'text-red-700 dark:text-red-300' : 'text-amber-700 dark:text-amber-300'}><p className="font-semibold">{finding.message}</p>{finding.excerpt && <blockquote className="my-1 border-l-2 pl-2 text-neutral-500">{finding.excerpt}</blockquote>}<p>{finding.suggestion}</p></div>)}</div></details>}
                                                    {guardedPublishing && doc.extracted_content && <details className="rounded-md border border-neutral-200 p-2 text-xs dark:border-neutral-700"><summary className="cursor-pointer font-medium"><Eye className="mr-1 inline h-3 w-3" />View extracted content</summary><pre className="mt-2 max-h-56 overflow-auto whitespace-pre-wrap font-sans leading-5 text-neutral-600 dark:text-neutral-300">{doc.extracted_content}</pre></details>}
                                                    {doc.status === 'pending' && !doc.error_message && (
                                                        <p className="text-xs leading-relaxed text-amber-600 dark:text-amber-300">{t('ai.doc_pending_hint')}</p>
                                                    )}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 text-neutral-500 dark:text-neutral-400 tabular-nums">
                                                {(doc.tokens ?? 0).toLocaleString()}
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-1 justify-end">
                                                    {guardedPublishing && doc.review_status === 'needs_review' && <button onClick={() => confirm('Keep the original wording and approve this source after reviewing every warning?') && router.post(route('client.ai.documents.approve', doc.uuid), {}, { preserveScroll: true })} title="Keep original and approve" className="rounded-md px-2 py-1.5 text-xs font-medium text-green-700 hover:bg-green-50"><CheckCircle2 className="mr-1 inline h-3.5 w-3.5" />Keep original</button>}
                                                    {guardedPublishing && ['needs_review', 'blocked'].includes(doc.review_status) && <button onClick={() => confirm('Reject and disable this source? It will not be published.') && router.post(route('client.ai.documents.reject', doc.uuid), {}, { preserveScroll: true })} title="Reject source" className="rounded-md px-2 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50">Reject</button>}
                                                    {guardedPublishing && doc.source_type === 'text' && <button onClick={() => openTextEdit(doc)} className="rounded-md px-2 py-1.5 text-xs font-medium text-brand-600 hover:bg-brand-50">Edit myself</button>}
                                                    {guardedPublishing && doc.source_type === 'text' && doc.extracted_content && <button onClick={() => requestSuggestion(doc)} className="rounded-md px-2 py-1.5 text-xs font-medium text-violet-600 hover:bg-violet-50">Suggest clarity</button>}
                                                    {guardedPublishing && <button onClick={() => router.post(route('client.ai.documents.toggle', doc.uuid), {}, { preserveScroll: true })} title={doc.enabled ? 'Disable source' : 'Enable source'} className="rounded-md p-1.5 text-neutral-400 hover:bg-neutral-100"><ToggleLeft className="h-3.5 w-3.5" /></button>}
                                                    {doc.source_type === 'video' && (
                                                        <button onClick={() => openVideoEdit(doc)} title={t('common.edit')} className="rounded-md p-1.5 text-neutral-400 hover:text-brand-600 hover:bg-brand-50 dark:hover:bg-brand-900/20 transition">
                                                            <Pencil className="h-3.5 w-3.5" />
                                                        </button>
                                                    )}
                                                    <button
                                                        onClick={() => handleReindex(doc.uuid)}
                                                        title={t('ai.reindex')}
                                                        className="rounded-md p-1.5 text-neutral-400 hover:text-brand-600 hover:bg-brand-50 dark:hover:bg-brand-900/20 transition"
                                                    >
                                                        <RefreshCw className="h-3.5 w-3.5" />
                                                    </button>
                                                    <button
                                                        onClick={() => handleDelete(doc.uuid)}
                                                        className="rounded-md p-1.5 text-neutral-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition"
                                                    >
                                                        <Trash2 className="h-3.5 w-3.5" />
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                        </div>
                    ) : (
                        <div className="mx-auto w-full max-w-5xl px-5 py-8 sm:px-8 sm:py-10">
                            <div className="text-center">
                                <h4 className="text-base font-semibold text-neutral-900 dark:text-white">Where should WisperBot learn from?</h4>
                                <p className="mx-auto mt-1 max-w-2xl text-sm text-neutral-500">Start with the source that contains your clearest, most accurate customer information.</p>
                            </div>
                            <div className="mt-6 grid gap-3 md:grid-cols-3">
                                {SOURCE_CHOICES.map(({ type, icon: SourceIcon, title, description, recommendation }) => (
                                    <button key={type} type="button" onClick={() => openAddSource(type)} className="group relative min-h-44 rounded-xl border border-neutral-200 bg-white p-5 text-left transition hover:-translate-y-0.5 hover:border-brand-300 hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:border-brand-700">
                                        {recommendation && <span className="absolute right-3 top-3 rounded-full bg-brand-50 px-2 py-1 text-[10px] font-semibold text-brand-700 dark:bg-brand-900/30 dark:text-brand-300">Recommended</span>}
                                        <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-neutral-100 text-neutral-600 transition group-hover:bg-brand-50 group-hover:text-brand-600 dark:bg-neutral-800 dark:text-neutral-300"><SourceIcon className="h-5 w-5" /></span>
                                        <span className="mt-4 block text-sm font-semibold text-neutral-900 dark:text-white">{title}</span>
                                        <span className="mt-1.5 block text-xs leading-5 text-neutral-500">{description}</span>
                                        <span className="mt-4 inline-flex items-center text-xs font-semibold text-brand-600">Choose <span className="ml-1" aria-hidden="true">→</span></span>
                                    </button>
                                ))}
                            </div>
                            <p className="mt-5 text-center text-xs text-neutral-400">Videos found inside supported pages and files are included automatically.</p>
                        </div>
                    )}
                    {guardedPublishing && <div className="flex flex-col gap-3 border-t border-neutral-100 bg-neutral-50 p-4 dark:border-neutral-800 dark:bg-neutral-900 sm:flex-row sm:items-center sm:justify-between"><p className="text-xs text-neutral-500">{hasRunningDocuments ? 'WisperBot is extracting and checking your sources. This page refreshes automatically.' : activeStep === 3 && ((health.warning ?? 0) + (health.blocked ?? 0) + (health.failed ?? 0) > 0) ? 'Resolve the highlighted sources before continuing.' : (kb.documents?.length ?? 0) > 0 ? (activeStep === 2 ? 'Add more sources, or continue when everything is ready.' : 'All enabled sources are ready for answer testing.') : 'You can return to the previous step without adding a source.'}</p><div className="flex gap-2 self-end"><button type="button" onClick={() => setActiveStep(activeStep === 3 ? 2 : 1)} className="rounded-lg border border-neutral-300 px-4 py-2 text-sm font-medium dark:border-neutral-700">Back</button>{(kb.documents?.length ?? 0) > 0 && <button type="button" disabled={hasRunningDocuments || (activeStep === 3 && ((health.warning ?? 0) + (health.blocked ?? 0) + (health.failed ?? 0) > 0))} onClick={() => setActiveStep(activeStep === 2 ? 3 : 4)} className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-40">{activeStep === 2 ? 'Review quality' : 'Test answers'} <span aria-hidden="true">→</span></button>}</div></div>}
                </div>
                )}

                {guardedPublishing && activeStep === 4 && (
                    <section className="mx-auto w-full max-w-3xl rounded-2xl border border-neutral-200 bg-white p-6 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"><div className="flex items-start gap-3"><div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-600"><FlaskConical className="h-4 w-4" /></div><div><p className="text-xs font-semibold uppercase tracking-wider text-brand-600">Step 4 of 5</p><h3 className="text-lg font-semibold">Test a real customer question</h3><p className="mt-1 text-sm text-neutral-500">Confirm that the answer uses the intended source before publishing. Try multiple questions when the Knowledge Base covers different topics.</p></div></div><form onSubmit={runTest} className="mt-6 flex flex-col gap-2 sm:flex-row"><input required value={testQuestion} onChange={e => setTestQuestion(e.target.value)} placeholder="How do I configure the widget?" className="min-w-0 flex-1 rounded-lg border border-neutral-300 px-3 py-2.5 text-sm dark:border-neutral-700 dark:bg-neutral-800" /><button disabled={testing} className="rounded-lg bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white">{testing ? 'Testing…' : 'Run test'}</button></form>{testResult && <div className={`mt-4 rounded-xl border p-4 text-sm ${testResult.decision === 'answer' ? 'border-green-200 bg-green-50 dark:border-green-900 dark:bg-green-900/20' : 'border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-900/20'}`}><div className="flex justify-between gap-3"><span className="font-semibold capitalize">{testResult.decision}</span><span>{Math.round((testResult.confidence ?? 0) * 100)}% confidence</span></div><p className="mt-2 text-neutral-700 dark:text-neutral-200">{testResult.answer || testResult.warnings?.[0]}</p><p className="mt-2 text-xs text-neutral-500">Estimated prompt: {testResult.estimated_prompt_tokens ?? 0} tokens · {testResult.sources?.length ?? 0} matched passages</p>{testResult.sources?.map(source => <details key={`${source.document_id}-${source.score}`} className="mt-2"><summary className="cursor-pointer text-xs font-medium text-brand-600">{source.title || 'Source'} · {Math.round(source.score * 100)}%</summary><p className="mt-1 text-xs text-neutral-500">{source.excerpt}</p></details>)}</div>}<div className="mt-6 flex items-center justify-between border-t border-neutral-100 pt-4 dark:border-neutral-800"><button type="button" onClick={() => setActiveStep(3)} className="rounded-lg border border-neutral-300 px-4 py-2 text-sm font-medium dark:border-neutral-700">Back</button><button type="button" disabled={!testResult || testResult.decision !== 'answer'} onClick={() => setActiveStep(5)} className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-40">Continue to publish <span aria-hidden="true">→</span></button></div></section>
                )}

                {guardedPublishing && activeStep === 5 && (
                    <section className="mx-auto w-full max-w-3xl rounded-2xl border border-neutral-200 bg-white p-6 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"><div className="flex items-start gap-3"><div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-600"><Rocket className="h-4 w-4" /></div><div><p className="text-xs font-semibold uppercase tracking-wider text-brand-600">Step 5 of 5</p><h3 className="text-lg font-semibold">Publish the safe revision</h3><p className="mt-1 text-sm text-neutral-500">Publishing runs saved regression tests and replaces the live revision only when every check passes.</p></div></div><div className="mt-6 grid gap-3 rounded-xl bg-neutral-50 p-4 text-sm dark:bg-neutral-800 sm:grid-cols-3"><p><strong className="block text-xs text-neutral-500">Live revision</strong>{kb.published_revision?.version ? `v${kb.published_revision.version}` : 'None yet'}</p><p><strong className="block text-xs text-neutral-500">Draft readiness</strong>{health.readiness ?? 0}%</p><p><strong className="block text-xs text-neutral-500">Connected chatbot</strong>{kb.chatbots?.[0]?.name ?? 'Not connected yet'}</p></div>{isPublished && !hasDraft && <p className="mt-4 rounded-lg bg-green-50 p-3 text-sm text-green-800">Everything published is already live. Return to Sources to add, remove, or reindex content before publishing another revision.</p>}{((health.blocked ?? 0) + (health.warning ?? 0) + (health.failed ?? 0) > 0) && <p className="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">Return to Review and resolve all warnings, blockers, and failures before publishing.</p>}<div className="mt-6 flex items-center justify-between border-t border-neutral-100 pt-4 dark:border-neutral-800"><button type="button" onClick={() => setActiveStep(4)} className="rounded-lg border border-neutral-300 px-4 py-2 text-sm font-medium dark:border-neutral-700">Back</button><button disabled={(isPublished && !hasDraft) || (kb.documents?.length ?? 0) === 0 || hasRunningDocuments || (health.blocked ?? 0) > 0 || (health.warning ?? 0) > 0 || (health.failed ?? 0) > 0} onClick={() => router.post(route('client.ai.knowledge-bases.publish', kb.uuid), {}, { preserveScroll: true, onSuccess: () => setActiveStep(6) })} className="rounded-lg bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-40"><Rocket className="mr-2 inline h-4 w-4" />{isPublished ? 'Publish updated revision' : 'Publish Knowledge Base'}</button></div></section>
                )}

                {guardedPublishing && activeStep === 6 && isPublished && (
                    <section className="rounded-2xl border border-neutral-200 bg-white p-6 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"><div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div><p className="text-xs font-semibold uppercase tracking-wider text-green-600">Live and monitored</p><h3 className="mt-1 text-lg font-semibold">Knowledge Base health</h3><p className="mt-1 text-sm text-neutral-500">Review usage and knowledge gaps, or return to Sources to prepare the next safe revision.</p></div><button type="button" onClick={() => setActiveStep(2)} className="inline-flex items-center justify-center gap-1.5 rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white"><Plus className="h-4 w-4" />Manage sources</button></div><div className="mt-6 grid gap-4 lg:grid-cols-2"><div><h4 className="font-semibold">Last 30 days</h4><div className="mt-3 grid grid-cols-2 gap-3">{[['Questions', efficiency.queries ?? 0], ['Cache hit rate', `${efficiency.cache_hit_rate ?? 0}%`], ['Context tokens', (efficiency.context_tokens ?? 0).toLocaleString()], ['Handoffs', efficiency.handoffs ?? 0]].map(([label, value]) => <div key={label} className="rounded-lg bg-neutral-50 p-3 dark:bg-neutral-800"><p className="text-lg font-semibold">{value}</p><p className="text-xs text-neutral-500">{label}</p></div>)}</div></div><div><h4 className="font-semibold">Knowledge gaps</h4><div className="mt-3 max-h-56 space-y-2 overflow-auto">{(kb.knowledge_gaps?.length ?? 0) === 0 ? <p className="rounded-lg bg-green-50 p-3 text-sm text-green-700">No open knowledge gaps.</p> : kb.knowledge_gaps.map(gap => <div key={gap.id} className="rounded-lg border border-neutral-200 p-3 text-sm dark:border-neutral-700"><p className="font-medium">{gap.question_sample}</p><p className="mt-1 text-xs text-neutral-500">Asked {gap.occurrences} time{gap.occurrences === 1 ? '' : 's'} · best match {Math.round((gap.best_score ?? 0) * 100)}%</p><button onClick={() => { reset(); setActiveStep(2); setShowAdd(true); }} className="mt-2 text-xs font-semibold text-brand-600">Add or update a source</button></div>)}</div></div></div>{(kb.revisions?.filter(r => r.status === 'superseded').length ?? 0) > 0 && <details className="mt-6 border-t border-neutral-100 pt-4 dark:border-neutral-800"><summary className="cursor-pointer text-sm font-medium text-neutral-600">Revision history and rollback</summary><div className="mt-3 space-y-2">{kb.revisions.filter(r => r.status === 'superseded').map(revision => <div key={revision.id} className="flex items-center justify-between rounded-lg border p-3 text-xs dark:border-neutral-700"><span>Revision {revision.version} · {revision.published_at ?? 'previously published'}</span><button onClick={() => router.post(route('client.ai.knowledge-bases.rollback', [kb.uuid, revision.id]))} className="font-semibold text-brand-600"><RotateCcw className="mr-1 inline h-3 w-3" />Rollback</button></div>)}</div></details>}</section>
                )}
            </div>

            {/* Edit Knowledge Base Modal */}
            {showEdit && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
                    <div className="w-full max-w-sm rounded-2xl bg-white dark:bg-neutral-900 shadow-2xl">
                        <div className="flex items-center justify-between px-6 pt-5 pb-4 border-b border-neutral-100 dark:border-neutral-800">
                            <h3 className="text-base font-semibold text-neutral-900 dark:text-neutral-100">{t('ai.edit_kb')}</h3>
                            <button onClick={() => setShowEdit(false)} className="text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200 transition">
                                <X className="h-4 w-4" />
                            </button>
                        </div>
                        <form onSubmit={handleEdit} className="px-6 py-4 space-y-4">
                            <div>
                                <label className="block text-xs font-medium text-neutral-700 dark:text-neutral-300 mb-1">{t('common.name')}</label>
                                <input
                                    type="text"
                                    value={renameForm.data.name}
                                    onChange={e => renameForm.setData('name', e.target.value)}
                                    required
                                    autoFocus
                                    className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100 placeholder-neutral-400 focus:outline-none focus:ring-2 focus:ring-brand-500 transition"
                                />
                                {renameForm.errors.name && <p className="mt-1 text-xs text-red-500">{renameForm.errors.name}</p>}
                            </div>
                            <div className="flex gap-2 pt-1 pb-2">
                                <button
                                    type="submit"
                                    disabled={renameForm.processing}
                                    className="flex-1 rounded-lg bg-brand-600 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-60 transition"
                                >
                                    {renameForm.processing ? t('common.saving') : t('common.save')}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setShowEdit(false)}
                                    className="rounded-lg border border-neutral-300 dark:border-neutral-600 px-4 py-2 text-sm text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition"
                                >
                                    {t('common.cancel')}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Add Document Modal */}
            {editingText && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="edit-source-title"><form onSubmit={handleTextEdit} className="w-full max-w-2xl space-y-4 rounded-2xl bg-white p-6 shadow-2xl dark:bg-neutral-900"><div className="flex items-center justify-between"><div><h3 id="edit-source-title" className="font-semibold">Review and edit source</h3><p className="mt-1 text-xs text-neutral-500">Suggestions may improve wording but never verify facts. You must confirm every factual value.</p></div><button type="button" aria-label="Close" onClick={() => setEditingText(null)}><X className="h-4 w-4" /></button></div><input required value={textEditForm.data.title} onChange={e => textEditForm.setData('title', e.target.value)} className="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800" /><textarea required autoFocus rows={14} value={textEditForm.data.source_ref} onChange={e => textEditForm.setData('source_ref', e.target.value)} className="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm leading-6 dark:border-neutral-700 dark:bg-neutral-800" />{Object.values(textEditForm.errors).map((error, i) => <p key={i} className="text-xs text-red-500">{error}</p>)}<div className="flex gap-2"><button disabled={textEditForm.processing} className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white">Accept edits into draft</button><button type="button" onClick={() => setEditingText(null)} className="rounded-lg border px-4 py-2 text-sm">Cancel</button></div></form></div>
            )}

            {/* Add Document Modal */}
            {editingVideo && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
                    <form onSubmit={handleVideoEdit} className="w-full max-w-md rounded-2xl bg-white dark:bg-neutral-900 shadow-2xl p-6 space-y-4">
                        <div className="flex items-center justify-between"><h3 className="font-semibold">{t('ai.edit_video')}</h3><button type="button" onClick={() => setEditingVideo(null)}><X className="h-4 w-4" /></button></div>
                        <input required value={videoEditForm.data.title} onChange={e => videoEditForm.setData('title', e.target.value)} placeholder={t('ai.title_label')} className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm" />
                        <input required type="url" value={videoEditForm.data.video_url} onChange={e => videoEditForm.setData('video_url', e.target.value)} placeholder={t('ai.video_url')} className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm" />
                        <textarea required rows={6} value={videoEditForm.data.video_transcript} onChange={e => videoEditForm.setData('video_transcript', e.target.value)} placeholder={t('ai.video_transcript')} className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm resize-none" />
                        <input type="url" value={videoEditForm.data.thumbnail_url} onChange={e => videoEditForm.setData('thumbnail_url', e.target.value)} placeholder={t('ai.thumbnail_url')} className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm" />
                        <input value={videoEditForm.data.trigger_phrases} onChange={e => videoEditForm.setData('trigger_phrases', e.target.value)} placeholder={t('ai.trigger_phrases')} className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm" />
                        {Object.values(videoEditForm.errors).map((error, i) => <p key={i} className="text-xs text-red-500">{error}</p>)}
                        <div className="flex gap-2"><button disabled={videoEditForm.processing} className="flex-1 rounded-lg bg-brand-600 py-2 text-sm font-medium text-white">{t('common.save')}</button><button type="button" onClick={() => setEditingVideo(null)} className="rounded-lg border px-4 py-2 text-sm">{t('common.cancel')}</button></div>
                    </form>
                </div>
            )}

            {/* Add Document Modal */}
            {showAdd && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
                    <div className="w-full max-w-2xl rounded-2xl bg-white dark:bg-neutral-900 shadow-2xl">
                        <div className="flex items-center justify-between px-6 pt-5 pb-4 border-b border-neutral-100 dark:border-neutral-800">
                            <h3 className="text-base font-semibold text-neutral-900 dark:text-neutral-100">{t('ai.add_document')}</h3>
                            <button onClick={() => setShowAdd(false)} className="text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200 transition">
                                <X className="h-4 w-4" />
                            </button>
                        </div>

                        <form onSubmit={handleAdd} className="max-h-[82vh] overflow-y-auto px-6 py-4 space-y-4">
                            {/* Source Type Tabs */}
                            <div>
                                <label className="block text-xs font-medium text-neutral-700 dark:text-neutral-300 mb-2">{t('ai.source_type')}</label>
                                <div className="grid grid-cols-3 gap-1 rounded-lg bg-neutral-100 dark:bg-neutral-800 p-1">
                                    {ADD_SOURCE_TYPES.map(type => {
                                        const { icon: Icon, labelKey } = SOURCE_TYPES[type];
                                        return (
                                        <button
                                            key={type}
                                            type="button"
                                            onClick={() => { setData('source_type', type); clearErrors('source_ref', 'file'); setFileError(''); }}
                                            className={`flex flex-col items-center gap-0.5 rounded-md py-1.5 px-1 text-xs font-medium transition ${
                                                data.source_type === type
                                                    ? 'bg-white dark:bg-neutral-700 text-brand-600 dark:text-brand-400 shadow-sm'
                                                    : 'text-neutral-500 dark:text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200'
                                            }`}
                                        >
                                            <Icon className="h-3.5 w-3.5" />
                                            {type === 'url' ? 'Specific page URL' : t(labelKey)}
                                        </button>
                                        );
                                    })}
                                </div>
                                <p className="mt-2 rounded-lg bg-neutral-50 px-3 py-2 text-xs leading-5 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">{{ file: `For the most reliable answers, upload a reviewed PDF, DOCX, TXT, or Markdown file up to ${kbUploadMaxMb} MB.`, url: 'Use one focused public HTTPS page. WisperBot extracts its guidance and supported video links automatically.', sitemap: 'Paste the website homepage—no sitemap knowledge required. WisperBot finds robots.txt, standard sitemaps, redirects, and up to 200 safe same-site pages automatically.' }[data.source_type]}</p>
                                {data.source_type === 'file' && <div className="mt-2 flex gap-2 rounded-lg border border-brand-200 bg-brand-50 px-3 py-2.5 text-xs leading-5 text-brand-900 dark:border-brand-800 dark:bg-brand-900/20 dark:text-brand-100"><Video className="mt-0.5 h-4 w-4 shrink-0 text-brand-600" /><p><strong className="font-bold">Add video URLs to help customers solve problems faster.</strong><br />YouTube, Vimeo, and direct HTTPS MP4 links inside the document can appear as playable videos in the chat.</p></div>}
                            </div>

                            {/* Input Area */}
                            {data.source_type === 'video' ? (
                                <div className="space-y-3">
                                    <div>
                                        <label className="block text-xs font-medium text-neutral-700 dark:text-neutral-300 mb-1">{t('ai.video_url')}</label>
                                        <input type="url" required value={data.video_url} onChange={e => setData('video_url', e.target.value)} placeholder="https://youtube.com/watch?v=..." className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100 focus:outline-none focus:ring-2 focus:ring-brand-500" />
                                        {errors.video_url && <p className="mt-1 text-xs text-red-500">{errors.video_url}</p>}
                                    </div>
                                    <div>
                                        <label className="block text-xs font-medium text-neutral-700 dark:text-neutral-300 mb-1">{t('ai.video_transcript')}</label>
                                        <textarea required rows={5} value={data.video_transcript} onChange={e => setData('video_transcript', e.target.value)} placeholder={t('ai.video_transcript_hint')} className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100 focus:outline-none focus:ring-2 focus:ring-brand-500 resize-none" />
                                        {errors.video_transcript && <p className="mt-1 text-xs text-red-500">{errors.video_transcript}</p>}
                                    </div>
                                    <div className="grid grid-cols-2 gap-2">
                                        <div>
                                            <label className="block text-xs font-medium text-neutral-700 dark:text-neutral-300 mb-1">{t('ai.thumbnail_url')}</label>
                                            <input type="url" value={data.thumbnail_url} onChange={e => setData('thumbnail_url', e.target.value)} placeholder="https://..." className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm" />
                                        </div>
                                        <div>
                                            <label className="block text-xs font-medium text-neutral-700 dark:text-neutral-300 mb-1">{t('ai.trigger_phrases')}</label>
                                            <input type="text" value={data.trigger_phrases} onChange={e => setData('trigger_phrases', e.target.value)} placeholder="setup, onboarding" className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm" />
                                        </div>
                                    </div>
                                </div>
                            ) : data.source_type === 'file' ? (
                                <div
                                    onDragOver={e => { e.preventDefault(); setDragOver(true); }}
                                    onDragLeave={() => setDragOver(false)}
                                    onDrop={handleDrop}
                                    onClick={() => fileRef.current?.click()}
                                    className={`relative cursor-pointer rounded-lg border-2 border-dashed p-6 text-center transition ${
                                        dragOver
                                            ? 'border-brand-500 bg-brand-50 dark:bg-brand-900/20'
                                            : 'border-neutral-300 dark:border-neutral-600 hover:border-brand-400 dark:hover:border-brand-600'
                                    }`}
                                >
                                    <input ref={fileRef} type="file" accept=".pdf,.docx,.txt,.md" className="hidden" onChange={e => selectFile(e.target.files[0])} />
                                    <Upload className="h-6 w-6 mx-auto mb-2 text-neutral-400" />
                                    {data.file ? (
                                        <p className="text-sm font-medium text-brand-600 dark:text-brand-400">{data.file.name}</p>
                                    ) : (
                                        <>
                                            <p className="text-sm text-neutral-600 dark:text-neutral-400"><Trans i18nKey="ai.drop_a_file_or_browse" components={{ 1: <span className="text-brand-600 dark:text-brand-400 font-medium" /> }} /></p>
                                            <p className="text-xs text-neutral-400 mt-1">PDF, DOCX, TXT, Markdown · max {kbUploadMaxMb} MB</p>
                                        </>
                                    )}
                                    {fileError && (
                                        <p className="mt-2 text-xs font-medium text-red-500">{fileError}</p>
                                    )}
                                    {errors.file && (
                                        <p className="mt-2 text-xs font-medium text-red-500">{errors.file}</p>
                                    )}
                                </div>
                            ) : data.source_type === 'text' ? (
                                <div>
                                    <label className="block text-xs font-medium text-neutral-700 dark:text-neutral-300 mb-1">{t('ai.text_content')}</label>
                                    <textarea
                                        value={data.source_ref}
                                        onChange={e => setData('source_ref', e.target.value)}
                                        rows={5}
                                        placeholder={t('ai.text_content_placeholder')}
                                        className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100 placeholder-neutral-400 focus:outline-none focus:ring-2 focus:ring-brand-500 resize-none transition"
                                    />
                                    {errors.source_ref && (
                                        <p className="mt-1 text-xs font-medium text-red-500">{errors.source_ref}</p>
                                    )}
                                </div>
                            ) : data.source_type === 'faq' ? (
                                <div className="space-y-2">
                                    <label className="block text-xs font-medium text-neutral-700 dark:text-neutral-300">{t('ai.questions_and_answers')}</label>
                                    <div className="space-y-3 max-h-52 overflow-y-auto pr-1">
                                        {faqPairs.map((pair, i) => (
                                            <div key={i} className="rounded-lg border border-neutral-200 dark:border-neutral-700 bg-neutral-50 dark:bg-neutral-800/50 p-3 space-y-2">
                                                <div className="flex items-center gap-2">
                                                    <span className="text-xs font-semibold text-neutral-400 w-4 shrink-0">Q{i + 1}</span>
                                                    <input
                                                        type="text"
                                                        value={pair.question}
                                                        onChange={e => updateFaqPair(i, 'question', e.target.value)}
                                                        placeholder={t('ai.faq_question_placeholder')}
                                                        className="flex-1 rounded-md border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-2.5 py-1.5 text-xs text-neutral-900 dark:text-neutral-100 placeholder-neutral-400 focus:outline-none focus:ring-2 focus:ring-brand-500 transition"
                                                    />
                                                    {faqPairs.length > 1 && (
                                                        <button type="button" onClick={() => removeFaqPair(i)} className="text-neutral-300 hover:text-red-400 transition">
                                                            <Trash className="h-3.5 w-3.5" />
                                                        </button>
                                                    )}
                                                </div>
                                                <div className="flex items-start gap-2">
                                                    <span className="text-xs font-semibold text-neutral-400 w-4 shrink-0 mt-1.5">A</span>
                                                    <textarea
                                                        value={pair.answer}
                                                        onChange={e => updateFaqPair(i, 'answer', e.target.value)}
                                                        rows={2}
                                                        placeholder={t('ai.faq_answer_placeholder')}
                                                        className="flex-1 rounded-md border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-2.5 py-1.5 text-xs text-neutral-900 dark:text-neutral-100 placeholder-neutral-400 focus:outline-none focus:ring-2 focus:ring-brand-500 resize-none transition"
                                                    />
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                    <button
                                        type="button"
                                        onClick={addFaqPair}
                                        className="w-full rounded-lg border border-dashed border-neutral-300 dark:border-neutral-600 py-1.5 text-xs text-neutral-500 dark:text-neutral-400 hover:border-brand-400 hover:text-brand-600 dark:hover:text-brand-400 transition"
                                    >
                                        {t('ai.add_another_qa')}
                                    </button>
                                    {errors.source_ref && (
                                        <p className="text-xs font-medium text-red-500">{errors.source_ref}</p>
                                    )}
                                </div>
                            ) : (
                                <div>
                                    <label className="block text-xs font-medium text-neutral-700 dark:text-neutral-300 mb-1">
                                        {data.source_type === 'sitemap' ? t('ai.sitemap_url') : t('ai.source_url')}
                                    </label>
                                    <input
                                        type="text"
                                        inputMode="url"
                                        value={data.source_ref}
                                        onChange={e => setData('source_ref', e.target.value)}
                                        placeholder={data.source_type === 'sitemap' ? 'https://example.com' : 'https://example.com/help/article'}
                                        className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100 placeholder-neutral-400 focus:outline-none focus:ring-2 focus:ring-brand-500 transition"
                                    />
                                    {data.source_type === 'sitemap' && (
                                        <p className="mt-1 text-xs text-neutral-400">{t('ai.sitemap_hint')}</p>
                                    )}
                                    {errors.source_ref && (
                                        <p className="mt-1 text-xs font-medium text-red-500">{errors.source_ref}</p>
                                    )}
                                </div>
                            )}

                            {data.source_type !== 'faq' && (
                                <div>
                                    <label className="block text-xs font-medium text-neutral-700 dark:text-neutral-300 mb-1">{t('ai.title_label')} {data.source_type !== 'video' && <span className="text-neutral-400 font-normal">({t('common.optional')})</span>}</label>
                                    <input
                                        type="text"
                                        value={data.title}
                                        onChange={e => setData('title', e.target.value)}
                                        placeholder={t('ai.title_placeholder')}
                                        required={data.source_type === 'video'}
                                        className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100 placeholder-neutral-400 focus:outline-none focus:ring-2 focus:ring-brand-500 transition"
                                    />
                                </div>
                            )}

                            <div className="grid gap-3 rounded-xl border border-neutral-200 p-3 dark:border-neutral-700 sm:grid-cols-2"><label className="flex items-start gap-2 text-xs text-neutral-600 dark:text-neutral-300"><input type="checkbox" checked={data.authoritative} onChange={e => setData('authoritative', e.target.checked)} className="mt-0.5 rounded border-neutral-300 text-brand-600" /><span><strong className="block text-neutral-800 dark:text-neutral-100">Authoritative source</strong>Prefer this source when similar sources disagree.</span></label><label className="text-xs text-neutral-600 dark:text-neutral-300"><span className="mb-1 block font-semibold text-neutral-800 dark:text-neutral-100">Priority</span><select value={data.priority} onChange={e => setData('priority', Number(e.target.value))} className="w-full rounded-lg border border-neutral-300 bg-white px-2 py-1.5 dark:border-neutral-600 dark:bg-neutral-800"><option value={25}>Low</option><option value={50}>Normal</option><option value={75}>High</option><option value={100}>Critical policy</option></select></label></div>

                            <div className="flex gap-2 pt-1 pb-2">
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="flex-1 rounded-lg bg-brand-600 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-60 transition"
                                >
                                    {processing ? t('ai.adding') : t('ai.add_and_index')}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setShowAdd(false)}
                                    className="rounded-lg border border-neutral-300 dark:border-neutral-600 px-4 py-2 text-sm text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition"
                                >
                                    {t('common.cancel')}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </ClientLayout>
    );
}
