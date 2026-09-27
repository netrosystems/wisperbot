<?php

namespace App\Services;

use App\Models\Workspace;
use App\Modules\AI\Services\EmbeddingStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Permanently erases a deleted workspace after its 30-day restore window.
 *
 * Most workspace tables hold `workspace_id` without a database foreign key, so
 * nothing cascades by itself: this deletes child rows first (messages, KB
 * chunks, run logs, pivots), then every table with a `workspace_id`, then the
 * workspace. Knowledge Base vectors are removed from Qdrant before any row,
 * so a Qdrant failure leaves the workspace intact for the next run.
 *
 * Kept on purpose: user accounts (only moved off the workspace), audit logs,
 * AI credit ledgers and usage meters (billing history; erasing them would
 * reset plan usage), and media-library files, which belong to their uploader.
 */
class WorkspacePurger
{
    private const CHUNK = 500;

    /** Tables kept when a workspace is erased. */
    public const KEPT_TABLES = ['users', 'audit_logs', 'ai_credit_ledgers', 'usage_meters', 'notifications'];

    public function __construct(
        private readonly EmbeddingStore $embeddings,
        private readonly StorageManager $storage,
    ) {}

    public function purge(Workspace $workspace): void
    {
        $id = (int) $workspace->id;

        $this->deleteKnowledgeBaseVectorsAndFiles($id);

        DB::transaction(function () use ($id, $workspace): void {
            $this->deleteChildren($id);
            $this->deleteWorkspaceRows($id);
            DB::table('users')->where('workspace_id', $id)->update(['workspace_id' => null]);
            $workspace->forceDelete();
        });

        Workspace::forgetDeletedIds();
        Log::info('Workspace erased after its restore window', ['workspace_id' => $id]);
    }

    private function deleteKnowledgeBaseVectorsAndFiles(int $workspaceId): void
    {
        if (! $this->hasTables('ai_knowledge_bases', 'ai_kb_documents')) {
            return;
        }

        $files = [];
        DB::table('ai_kb_documents')
            ->whereIn('kb_id', DB::table('ai_knowledge_bases')->where('workspace_id', $workspaceId)->select('id'))
            ->orderBy('id')
            ->chunk(self::CHUNK, function ($documents) use (&$files): void {
                foreach ($documents as $document) {
                    // Throws on a Qdrant failure, before any row is deleted.
                    $this->embeddings->deleteDocumentEmbeddings((int) $document->id);
                    if ($document->source_type === 'file' && $document->source_ref) {
                        $files[] = $document->source_ref;
                    }
                }
            });

        if ($files !== []) {
            try {
                $this->storage->disk()->delete($files);
            } catch (\Throwable $e) {
                Log::warning('Knowledge base file cleanup failed while erasing a workspace', ['workspace_id' => $workspaceId, 'error' => $e->getMessage()]);
            }
        }
    }

    private function deleteChildren(int $workspaceId): void
    {
        $of = fn (string $table) => DB::table($table)->where('workspace_id', $workspaceId)->select('id');

        // Conversations and everything hanging off them.
        if ($this->hasTables('conversations')) {
            DB::table('conversations')->where('workspace_id', $workspaceId)->orderBy('id')
                ->chunkById(self::CHUNK, function ($conversations): void {
                    $ids = $conversations->pluck('id')->all();
                    if ($this->hasTables('messages', 'media')) {
                        $mediaIds = DB::table('messages')->whereIn('conversation_id', $ids)->whereNotNull('media_id')->pluck('media_id')->all();
                        $this->deleteMedia($mediaIds);
                    }
                    foreach (['messages', 'inbox_assignments', 'inbox_notes', 'internal_notes', 'inbox_label_conversation', 'ai_runs'] as $table) {
                        if ($this->hasTables($table)) {
                            DB::table($table)->whereIn('conversation_id', $ids)->delete();
                        }
                    }
                });
        }

        $children = [
            ['ai_runs', 'chatbot_id', 'ai_chatbots'],
            ['inbox_label_conversation', 'label_id', 'inbox_labels'],
            ['ai_kb_chunks', 'kb_id', 'ai_knowledge_bases'],
            ['ai_kb_test_cases', 'kb_id', 'ai_knowledge_bases'],
            ['ai_kb_documents', 'kb_id', 'ai_knowledge_bases'],
            ['ai_kb_product_offers', 'product_id', 'ai_kb_products'],
            ['campaign_recipients', 'campaign_id', 'campaigns'],
            ['contact_tag_pivot', 'contact_id', 'contacts'],
            ['contact_tag_pivot', 'tag_id', 'contact_tags'],
            ['segment_contact', 'segment_id', 'segments'],
            ['segment_contact', 'contact_id', 'contacts'],
            ['social_media_post_accounts', 'post_id', 'social_media_posts'],
            ['social_media_post_accounts', 'social_account_id', 'social_media_accounts'],
            ['whatsapp_template_submissions', 'template_id', 'whatsapp_templates'],
            ['whatsapp_phone_numbers', 'waba_id_fk', 'whatsapp_business_accounts'],
        ];

        // KB revisions: their document links first, then the revisions.
        if ($this->hasTables('ai_kb_revisions', 'ai_kb_revision_documents', 'ai_knowledge_bases')) {
            DB::table('ai_kb_revision_documents')
                ->whereIn('revision_id', DB::table('ai_kb_revisions')->whereIn('kb_id', $of('ai_knowledge_bases'))->select('id'))
                ->delete();
        }
        $children[] = ['ai_kb_revisions', 'kb_id', 'ai_knowledge_bases'];

        // Automation run logs, then runs.
        if ($this->hasTables('automation_run_logs', 'automation_runs', 'automations')) {
            DB::table('automation_run_logs')
                ->whereIn('run_id', DB::table('automation_runs')->whereIn('automation_id', $of('automations'))->select('id'))
                ->delete();
        }
        $children[] = ['automation_runs', 'automation_id', 'automations'];

        foreach ($children as [$table, $column, $parent]) {
            if ($this->hasTables($table, $parent) && Schema::hasColumn($table, $column)) {
                DB::table($table)->whereIn($column, $of($parent))->delete();
            }
        }
    }

    /** Every table with a `workspace_id`, except the ones kept on purpose. */
    private function deleteWorkspaceRows(int $workspaceId): void
    {
        foreach ($this->workspaceTables() as $table) {
            DB::table($table)->where('workspace_id', $workspaceId)->delete();
        }
    }

    /** @return list<string> */
    public function workspaceTables(): array
    {
        return collect(Schema::getTables())
            ->pluck('name')
            ->reject(fn (string $table) => in_array($table, self::KEPT_TABLES, true) || $table === 'workspaces')
            ->filter(fn (string $table) => Schema::hasColumn($table, 'workspace_id'))
            ->values()
            ->all();
    }

    /** @param  list<int|string>  $mediaIds */
    private function deleteMedia(array $mediaIds): void
    {
        if ($mediaIds === []) {
            return;
        }

        foreach (DB::table('media')->whereIn('id', $mediaIds)->get(['id', 'disk', 'path']) as $media) {
            try {
                if ($media->path) {
                    Storage::disk($media->disk ?: 'public')->delete($media->path);
                }
            } catch (\Throwable $e) {
                Log::warning('Message media file cleanup failed while erasing a workspace', ['media_id' => $media->id, 'error' => $e->getMessage()]);
            }
        }
        DB::table('media')->whereIn('id', $mediaIds)->delete();
    }

    private function hasTables(string ...$tables): bool
    {
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }
}
