<?php

use App\Modules\Social\Http\Controllers\SocialAccountController;
use App\Modules\Social\Http\Controllers\SocialAutomationController;
use App\Modules\Social\Http\Controllers\SocialPostController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'client-app'])->prefix('app/social')->name('client.social.')->group(function () {
    Route::get('/automation', [SocialAutomationController::class, 'index'])->name('automation.index');
    Route::get('/automation/schedule', [SocialPostController::class, 'composer'])->name('automation.schedule');

    Route::get('/accounts', fn (Request $request) => redirect()->route('client.social.automation.index', $request->query()))->name('accounts.index');
    Route::get('/accounts/connect/{network}', [SocialAccountController::class, 'connect'])->name('accounts.connect');
    Route::get('/accounts/callback/{network}', [SocialAccountController::class, 'callback'])->name('oauth.callback');
    Route::delete('/accounts/{account}', [SocialAccountController::class, 'disconnect'])->name('accounts.disconnect');

    Route::get('/posts', function (Request $request) {
        $query = $request->query();
        $status = $query['status'] ?? null;
        unset($query['status']);
        if (! isset($query['tab']) && $status) {
            $query['tab'] = match ($status) {
                'draft' => 'drafts',
                'published' => 'published',
                'failed' => 'failed',
                'scheduled', 'publishing' => 'upcoming',
                default => 'all',
            };
        }

        return redirect()->route('client.social.automation.index', $query);
    })->name('posts.index');
    Route::get('/composer', fn (Request $request) => redirect()->route('client.social.automation.schedule', $request->query()))->name('composer');
    Route::post('/posts', [SocialPostController::class, 'store'])->name('posts.store')->middleware('limit:social_posts_per_month,social_posts');
    Route::get('/posts/{post}/edit', [SocialPostController::class, 'edit'])->name('posts.edit');
    Route::put('/posts/{post}', [SocialPostController::class, 'update'])->name('posts.update');
    Route::delete('/posts/{post}', [SocialPostController::class, 'destroy'])->name('posts.destroy');
    Route::delete('/posts/{post}/local', [SocialPostController::class, 'removeLocal'])->name('posts.remove-local');
    Route::post('/posts/{post}/publish-now', [SocialPostController::class, 'publishNow'])->name('posts.publish-now');
    Route::post('/posts/{post}/cancel', [SocialPostController::class, 'cancel'])->name('posts.cancel');
    Route::post('/ai-generate', [SocialPostController::class, 'aiGenerate'])->name('ai-generate');
    Route::post('/ai-plan', [SocialPostController::class, 'aiPlan'])->name('ai-plan');
    Route::post('/posts/bulk', [SocialPostController::class, 'bulkStore'])->name('posts.bulk')->middleware('limit:social_posts_per_month,social_posts');
    Route::get('/calendar', function (Request $request) {
        $query = $request->query();
        $status = $query['status'] ?? null;
        unset($query['status']);
        if (! isset($query['tab']) && $status) {
            $query['tab'] = match ($status) {
                'draft' => 'drafts',
                'published' => 'published',
                'failed' => 'failed',
                'scheduled', 'publishing' => 'upcoming',
                default => 'all',
            };
        }

        return redirect()->route('client.social.automation.index', array_merge($query, ['view' => 'calendar']));
    })->name('calendar');
});
