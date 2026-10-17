<?php

use App\Http\Controllers\Chat\ChatController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Workspace\CurrentWorkspaceController;
use App\Http\Controllers\Workspace\ExternalWebhookController;
use App\Http\Controllers\Workspace\FlowAssistantController;
use App\Http\Controllers\Workspace\FlowController;
use App\Http\Controllers\Workspace\FlowTransportController;
use App\Http\Controllers\Workspace\SequenceController;
use App\Http\Controllers\Workspace\UazapiChatController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('workspaces/{workspace}/uazapi/import', [ChatController::class, 'import'])->name('workspaces.uazapi.import');
    Route::post('workspaces/{workspace}/uazapi/diagnose', [UazapiChatController::class, 'diagnose'])->name('workspaces.uazapi.diagnose');
    Route::get('workspaces/{workspace}/webhooks', [ExternalWebhookController::class, 'index'])->name('workspaces.webhooks.index');
    Route::post('workspaces/{workspace}/webhooks', [ExternalWebhookController::class, 'store'])->name('workspaces.webhooks.store');
    Route::get('workspaces/{workspace}/webhooks/{webhook}', [ExternalWebhookController::class, 'edit'])->name('workspaces.webhooks.edit');
    Route::put('workspaces/{workspace}/webhooks/{webhook}', [ExternalWebhookController::class, 'update'])->name('workspaces.webhooks.update');
    Route::get('workspaces/{workspace}/webhooks/{webhook}/snapshot', [ExternalWebhookController::class, 'poll'])->name('workspaces.webhooks.snapshot');
    Route::post('workspaces/{workspace}/webhooks/{webhook}/sample', [ExternalWebhookController::class, 'sample'])->name('workspaces.webhooks.sample');
    Route::delete('workspaces/{workspace}/webhooks/{webhook}/sample', [ExternalWebhookController::class, 'clearSample'])->name('workspaces.webhooks.sample.clear');
    Route::post('workspaces/{workspace}/webhooks/{webhook}/capture', [ExternalWebhookController::class, 'capture'])->name('workspaces.webhooks.capture');
    Route::post('workspaces/{workspace}/webhooks/{webhook}/simulate', [ExternalWebhookController::class, 'simulate'])->name('workspaces.webhooks.simulate');
    Route::post('workspaces/{workspace}/current', CurrentWorkspaceController::class)->name('workspaces.current');
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('workspaces/{workspace}/uazapi/connection', [UazapiChatController::class, 'connection'])->name('workspaces.uazapi.connection');
    Route::get('workspaces/{workspace}/sequences', [SequenceController::class, 'index'])->name('workspaces.sequences.index');
    Route::post('workspaces/{workspace}/sequences', [SequenceController::class, 'store'])->name('workspaces.sequences.store');
    Route::get('workspaces/{workspace}/sequences/{sequence}', [SequenceController::class, 'edit'])->name('workspaces.sequences.edit');
    Route::put('workspaces/{workspace}/sequences/{sequence}', [SequenceController::class, 'update'])->name('workspaces.sequences.update');
    Route::post('workspaces/{workspace}/sequences/{sequence}/publish', [SequenceController::class, 'publish'])->name('workspaces.sequences.publish');
    Route::post('workspaces/{workspace}/sequences/{sequence}/deactivate', [SequenceController::class, 'deactivate'])->name('workspaces.sequences.deactivate');
    Route::post('workspaces/{workspace}/sequences/{sequence}/activate', [SequenceController::class, 'activate'])->name('workspaces.sequences.activate');
    Route::get('workspaces/{workspace}/contacts/{contact}/sequence-enrollment', [SequenceController::class, 'enrollment'])->name('workspaces.contacts.sequence-enrollment');
    Route::post('workspaces/{workspace}/contacts/{contact}/sequence-enrollment', [SequenceController::class, 'enroll'])->name('workspaces.contacts.sequence-enroll');
    Route::prefix('workspaces/{workspace}/flows/{flow}')->name('workspaces.flows.')->group(function () {
        Route::post('import/validate', [FlowTransportController::class, 'validateImport'])->name('import.validate');
        Route::post('import', [FlowTransportController::class, 'import'])->name('import');
        Route::get('export', [FlowTransportController::class, 'export'])->name('export');
        Route::get('ai-guide', [FlowTransportController::class, 'guide'])->name('ai-guide');
        Route::get('assistant', [FlowAssistantController::class, 'state'])->name('assistant.state');
        Route::get('assistant/models', [FlowAssistantController::class, 'models'])->name('assistant.models');
        Route::post('assistant/messages', [FlowAssistantController::class, 'message'])->name('assistant.message');
        Route::post('assistant/plans/{plan}/approve', [FlowAssistantController::class, 'approve'])->name('assistant.approve');
        Route::post('assistant/runs/{run}/stop', [FlowAssistantController::class, 'stop'])->name('assistant.stop');
        Route::post('assistant/runs/{run}/resume', [FlowAssistantController::class, 'resume'])->name('assistant.resume');
        Route::put('draft-session', [FlowAssistantController::class, 'updateSession'])->name('draft-session.update');
        Route::delete('draft-session', [FlowAssistantController::class, 'discard'])->name('draft-session.discard');
    });
    Route::get('workspaces/{workspace}/flows', [FlowController::class, 'index'])->name('workspaces.flows.index');
    Route::post('workspaces/{workspace}/flows', [FlowController::class, 'store'])->name('workspaces.flows.store');
    Route::post('workspaces/{workspace}/flow-folders', [FlowController::class, 'storeFolder'])->name('workspaces.flow-folders.store');
    Route::patch('workspaces/{workspace}/flow-folders/{folder}', [FlowController::class, 'updateFolder'])->name('workspaces.flow-folders.update');
    Route::delete('workspaces/{workspace}/flow-folders/{folder}', [FlowController::class, 'deleteFolder'])->name('workspaces.flow-folders.delete');
    Route::patch('workspaces/{workspace}/flows/{flow}/folder', [FlowController::class, 'moveToFolder'])->name('workspaces.flows.folder');
    Route::get('workspaces/{workspace}/flows/{flow}/events', [FlowController::class, 'events'])->name('workspaces.flows.events');
    Route::get('workspaces/{workspace}/flows/{flow}', [FlowController::class, 'edit'])->name('workspaces.flows.edit');
    Route::patch('workspaces/{workspace}/flows/{flow}', [FlowController::class, 'rename'])->name('workspaces.flows.rename');
    Route::put('workspaces/{workspace}/flows/{flow}', [FlowController::class, 'update'])->name('workspaces.flows.update');
    Route::delete('workspaces/{workspace}/flows/{flow}', [FlowController::class, 'destroy'])->name('workspaces.flows.destroy');
    Route::post('workspaces/{workspace}/flows/{flow}/publish', [FlowController::class, 'publish'])->name('workspaces.flows.publish');
    Route::post('workspaces/{workspace}/flows/{flow}/deactivate', [FlowController::class, 'deactivate'])->name('workspaces.flows.deactivate');
    Route::post('workspaces/{workspace}/flows/{flow}/assets', [FlowController::class, 'upload'])->name('workspaces.flows.assets');
    Route::post('workspaces/{workspace}/flow-fields', [FlowController::class, 'storeField'])->name('workspaces.flow-fields.store');
    Route::post('workspaces/{workspace}/flow-tags', [FlowController::class, 'storeTag'])->name('workspaces.flow-tags.store');
    Route::get('workspaces/{workspace}/flow-triggers', [FlowController::class, 'triggers'])->name('workspaces.flow-triggers.index');
    Route::put('workspaces/{workspace}/flow-triggers', [FlowController::class, 'saveTriggers'])->name('workspaces.flow-triggers.update');
    Route::post('workspaces/{workspace}/uazapi/connect', [UazapiChatController::class, 'start'])->name('workspaces.uazapi.connect');
    Route::get('workspaces/{workspace}/uazapi/status', [UazapiChatController::class, 'status'])->name('workspaces.uazapi.status');
    Route::get('workspaces/{workspace}/conversations', [UazapiChatController::class, 'index'])->name('workspaces.conversations.index');
    Route::get('workspaces/{workspace}/conversations/{thread}/messages', [UazapiChatController::class, 'history'])->name('workspaces.conversations.messages');
    Route::post('workspaces/{workspace}/conversations/{thread}/read', [UazapiChatController::class, 'markRead'])->name('workspaces.conversations.read');
    Route::get('workspaces/{workspace}/conversations/{thread}/avatar', [UazapiChatController::class, 'avatar'])->name('workspaces.conversations.avatar');
    Route::get('workspaces/{workspace}/conversations/{thread}/media/{messageId}', [UazapiChatController::class, 'media'])->name('workspaces.conversations.media');
    Route::post('workspaces/{workspace}/conversations/{thread}/open', [UazapiChatController::class, 'open'])->name('workspaces.conversations.open');
    Route::post('workspaces/{workspace}/conversations/{thread}/claim', [UazapiChatController::class, 'claim'])->name('workspaces.conversations.claim');
    Route::post('workspaces/{workspace}/conversations/{thread}/assign', [UazapiChatController::class, 'assignTo'])->name('workspaces.conversations.assign');
    Route::post('workspaces/{workspace}/conversations/{thread}/finish', [UazapiChatController::class, 'finish'])->name('workspaces.conversations.finish');
    Route::post('workspaces/{workspace}/conversations/{thread}/automations/pause', [UazapiChatController::class, 'pauseAutomations'])->name('workspaces.conversations.automations.pause');
    Route::post('workspaces/{workspace}/conversations/{thread}/automations/resume', [UazapiChatController::class, 'resumeAutomations'])->name('workspaces.conversations.automations.resume');
    Route::post('workspaces/{workspace}/conversations/{thread}/send', [UazapiChatController::class, 'send'])->name('workspaces.conversations.send');
    Route::put('workspaces/{workspace}/conversations/{thread}/contact', [UazapiChatController::class, 'updateContact'])->name('workspaces.conversations.contact');
    Route::post('workspaces/{workspace}/conversations/{thread}/notes', [UazapiChatController::class, 'addNote'])->name('workspaces.conversations.notes');
});

Route::post('webhooks/uazapi/{connection}/{secret}', [UazapiChatController::class, 'webhook'])
    ->name('webhooks.uazapi');

Route::post('webhooks/{endpointKey}', [ExternalWebhookController::class, 'receive'])
    ->where('endpointKey', '[a-f0-9]{64}')
    ->withoutMiddleware(PreventRequestForgery::class)
    ->name('webhooks.external.receive');

require __DIR__.'/settings.php';
