<?php

use App\Modules\Inbox\Http\Controllers\Widget\ChatWidgetEmbedController;
use App\Modules\Inbox\Http\Controllers\Widget\ChatWidgetPublicController;
use Illuminate\Support\Facades\Route;

/*
 * Public website live-chat widget surface. Loaded by InboxServiceProvider
 * OUTSIDE the `web` middleware group, so there is no session/CSRF — these are
 * anonymous cross-origin calls from the client's own website. Security is by
 * widget_key + per-widget domain whitelist + signed visitor session token +
 * rate limiting. CORS is granted via config/cors.php (widget/v1/*, widgets/chat/*).
 */

// One-line embed script the client drops on their site.
Route::get('/widgets/chat/{key}.js', [ChatWidgetEmbedController::class, 'script'])
    ->middleware('widget.request_log')
    ->name('chat-widget.embed');

// Visitor API (JSON, polling).
Route::prefix('widget/v1')->middleware('widget.request_log')->name('widget.')->group(function () {
    Route::post('/session', [ChatWidgetPublicController::class, 'session'])
        ->middleware('throttle:60,1')->name('session');
    Route::post('/messages', [ChatWidgetPublicController::class, 'send'])
        ->middleware('throttle:120,1')->name('send');
    Route::get('/messages', [ChatWidgetPublicController::class, 'poll'])
        ->middleware('throttle:600,1')->name('poll');
    Route::post('/read', [ChatWidgetPublicController::class, 'markRead'])
        ->middleware('throttle:240,1')->name('read');
    Route::post('/delivered', [ChatWidgetPublicController::class, 'delivered'])
        ->middleware('throttle:600,1')->name('delivered');
    Route::get('/pusher-config', [ChatWidgetPublicController::class, 'pusherConfig'])
        ->middleware('throttle:240,1')->name('pusher-config');
    Route::post('/pusher-auth', [ChatWidgetPublicController::class, 'broadcastingAuth'])
        ->middleware('throttle:600,1')->name('pusher-auth');
    Route::post('/broadcasting/auth', [ChatWidgetPublicController::class, 'broadcastingAuth'])
        ->middleware('throttle:600,1')->name('broadcasting-auth');
    Route::post('/typing', [ChatWidgetPublicController::class, 'typing'])
        ->middleware('throttle:120,1')->name('typing');
    Route::post('/handoff', [ChatWidgetPublicController::class, 'handoff'])
        ->middleware('throttle:20,1')->name('handoff');
});
