<?php

use App\Modules\AI\Http\Controllers\AiChatbotController;
use App\Modules\AI\Http\Controllers\AiKnowledgeBaseController;
use App\Modules\AI\Http\Controllers\AiProviderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'client-app'])->prefix('app/ai')->name('client.ai.')->group(function () {
    // Provider configs
    Route::get('/providers', [AiProviderController::class, 'index'])->name('providers.index');
    Route::put('/providers-mode', [AiProviderController::class, 'updateMode'])->name('providers.mode');
    Route::put('/providers/{provider}', [AiProviderController::class, 'update'])->name('providers.update');
    Route::post('/providers/{provider}/test', [AiProviderController::class, 'test'])->name('providers.test');

    // Knowledge bases
    Route::get('/knowledge-bases', [AiKnowledgeBaseController::class, 'index'])->name('knowledge-bases.index');
    Route::post('/knowledge-bases', [AiKnowledgeBaseController::class, 'store'])->name('knowledge-bases.store')->middleware('limit:knowledge_bases,knowledge_bases');
    Route::get('/knowledge-bases/{kb}', [AiKnowledgeBaseController::class, 'show'])->name('knowledge-bases.show');
    Route::put('/knowledge-bases/{kb}', [AiKnowledgeBaseController::class, 'update'])->name('knowledge-bases.update');
    Route::delete('/knowledge-bases/{kb}', [AiKnowledgeBaseController::class, 'destroy'])->name('knowledge-bases.destroy');
    Route::post('/knowledge-bases/{kb}/documents', [AiKnowledgeBaseController::class, 'addDocument'])->name('knowledge-bases.documents.add');
    Route::post('/knowledge-bases/{kb}/publish', [AiKnowledgeBaseController::class, 'publish'])->name('knowledge-bases.publish');
    Route::post('/knowledge-bases/{kb}/rollback/{revision}', [AiKnowledgeBaseController::class, 'rollback'])->name('knowledge-bases.rollback');
    Route::post('/knowledge-bases/{kb}/test', [AiKnowledgeBaseController::class, 'testQuery'])->name('knowledge-bases.test');
    Route::post('/knowledge-bases/{kb}/test-cases', [AiKnowledgeBaseController::class, 'storeTestCase'])->name('knowledge-bases.test-cases.store');
    Route::delete('/knowledge-bases/{kb}/test-cases/{testCase}', [AiKnowledgeBaseController::class, 'destroyTestCase'])->name('knowledge-bases.test-cases.destroy');
    Route::post('/documents/{document}/reindex', [AiKnowledgeBaseController::class, 'reindex'])->name('documents.reindex');
    Route::post('/documents/{document}/approve', [AiKnowledgeBaseController::class, 'approveDocument'])->name('documents.approve');
    Route::post('/documents/{document}/reject', [AiKnowledgeBaseController::class, 'rejectDocument'])->name('documents.reject');
    Route::post('/documents/{document}/toggle', [AiKnowledgeBaseController::class, 'toggleDocument'])->name('documents.toggle');
    Route::post('/documents/{document}/suggest-correction', [AiKnowledgeBaseController::class, 'suggestCorrection'])->name('documents.suggest-correction');
    Route::put('/documents/{document}', [AiKnowledgeBaseController::class, 'updateDocument'])->name('documents.update');
    Route::delete('/documents/{document}', [AiKnowledgeBaseController::class, 'destroyDocument'])->name('documents.destroy');

    // Chatbots
    Route::get('/chatbots', [AiChatbotController::class, 'index'])->name('chatbots.index');
    Route::post('/chatbots', [AiChatbotController::class, 'store'])->name('chatbots.store');
    Route::put('/chatbots/{chatbot}', [AiChatbotController::class, 'update'])->name('chatbots.update');
    Route::delete('/chatbots/{chatbot}', [AiChatbotController::class, 'destroy'])->name('chatbots.destroy');
    Route::post('/chatbots/{chatbot}/playground', [AiChatbotController::class, 'playground'])->name('chatbots.playground');
});
