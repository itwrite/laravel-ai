<?php

use App\Http\Controllers\Ai\ChatController;
use App\Http\Controllers\Ai\ChatConversationController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware('auth')->group(function () {
    Route::get('chat', [ChatController::class, 'index'])->name('chat');

    Route::post('chat', [ChatController::class, 'stream'])
        ->middleware('throttle:10,1')
        ->name('chat.stream');

    Route::delete('chat/conversations/{conversation}', [ChatConversationController::class, 'destroy'])
        ->name('chat.conversations.destroy');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
