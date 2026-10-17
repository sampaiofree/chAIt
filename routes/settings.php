<?php

use App\Http\Controllers\Settings\AppearanceController;
use App\Http\Controllers\Settings\ContactDataController;
use App\Http\Controllers\Settings\OpenAiController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\TeamController;
use App\Http\Controllers\Settings\WhatsAppController;
use App\Http\Controllers\Settings\WorkspaceController;
use App\Http\Controllers\Workspace\ExternalWebhookController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('settings/appearance', AppearanceController::class)->name('appearance.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])->name('security.edit');
    Route::get('settings/team', [TeamController::class, 'index'])->name('team.edit');
    Route::get('settings/workspace', [WorkspaceController::class, 'edit'])->name('workspace.edit');
    Route::post('settings/workspace', [WorkspaceController::class, 'update'])->name('workspace.update');
    Route::delete('settings/workspace/icon', [WorkspaceController::class, 'destroyIcon'])->name('workspace.icon.destroy');
    Route::get('settings/webhooks', [ExternalWebhookController::class, 'settings'])->name('webhooks.settings');
    Route::get('settings/contact-data', [ContactDataController::class, 'index'])->name('contact-data.settings');
    Route::post('workspaces/{workspace}/contact-fields', [ContactDataController::class, 'storeField'])->name('workspaces.contact-fields.store');
    Route::patch('workspaces/{workspace}/contact-fields/{field}', [ContactDataController::class, 'updateField'])->name('workspaces.contact-fields.update');
    Route::delete('workspaces/{workspace}/contact-fields/{field}', [ContactDataController::class, 'destroyField'])->name('workspaces.contact-fields.destroy');
    Route::post('workspaces/{workspace}/tags', [ContactDataController::class, 'storeTag'])->name('workspaces.tags.store');
    Route::patch('workspaces/{workspace}/tags/{tag}', [ContactDataController::class, 'updateTag'])->name('workspaces.tags.update');
    Route::delete('workspaces/{workspace}/tags/{tag}', [ContactDataController::class, 'destroyTag'])->name('workspaces.tags.destroy');
    Route::post('workspaces/{workspace}/members', [TeamController::class, 'store'])->name('workspaces.members.store');
    Route::get('settings/whatsapp', WhatsAppController::class)->name('whatsapp.settings');
    Route::get('settings/openai', [OpenAiController::class, 'edit'])->name('openai.settings');
    Route::put('workspaces/{workspace}/openai', [OpenAiController::class, 'update'])->name('workspaces.openai.update');
    Route::delete('workspaces/{workspace}/openai', [OpenAiController::class, 'destroy'])->name('workspaces.openai.destroy');
    Route::patch('workspaces/{workspace}/members/{membership}/permissions', [TeamController::class, 'update'])->name('workspaces.members.permissions');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');
});
