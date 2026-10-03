<?php

namespace App\Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Inbox\Services\SmartBotPlacements;
use App\Modules\Inbox\Services\WidgetAiPlacement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Where it answers" on the bot page: the website widget. Inbox channels and
 * email use Channel Setup's own endpoint (`client.inbox.ai-answering.update`).
 */
class AiChatbotPlacementController extends Controller
{
    public function widget(Request $request, AiChatbot $chatbot, ChatWidget $chatWidget, SmartBotPlacements $placements, WidgetAiPlacement $widgets): RedirectResponse
    {
        $workspaceId = (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
        abort_unless((int) $chatbot->workspace_id === $workspaceId && (int) $chatWidget->workspace_id === $workspaceId, 403);
        abort_unless($placements->canManage($request->user()), 403);
        $on = $request->validate(['on' => ['required', 'boolean']])['on'];

        // Turning it off only stops this bot; another bot's widget is left alone.
        if ($on || (int) $chatWidget->ai_chatbot_id === (int) $chatbot->id) {
            $widgets->apply($chatWidget, $on ? (int) $chatbot->id : null);
        }

        return back()->with('success', $on ? 'This Smart Bot now answers your website chat.' : 'This Smart Bot no longer answers your website chat.');
    }
}
