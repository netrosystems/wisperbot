<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignId('workspace_id')->nullable()->after('notifiable_id')->constrained()->nullOnDelete();
            $table->index(['notifiable_type', 'notifiable_id', 'workspace_id', 'read_at'], 'notifications_user_workspace_unread_idx');
        });

        DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->whereNull('workspace_id')
            ->orderBy('id')
            ->chunkById(250, function ($notifications): void {
                $workspaceIds = User::whereIn('id', $notifications->pluck('notifiable_id')->unique())
                    ->pluck('workspace_id', 'id');

                foreach ($notifications as $notification) {
                    $workspaceId = (int) ($workspaceIds[$notification->notifiable_id] ?? 0);
                    if ($workspaceId > 0) {
                        DB::table('notifications')->where('id', $notification->id)->update(['workspace_id' => $workspaceId]);
                    }
                }
            }, 'id');
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_user_workspace_unread_idx');
            $table->dropConstrainedForeignId('workspace_id');
        });
    }
};
