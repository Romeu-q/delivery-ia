<?php

use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\StoreController;
use App\Http\Controllers\Api\UsageController;
use App\Http\Middleware\IdentifyVisitor;
use Illuminate\Support\Facades\Route;

// SPA Vue
Route::view('/', 'app');

// API usada pelo front. Fica no grupo "web" para ter sessão (visitante) e proteção CSRF.
Route::prefix('api')->middleware(IdentifyVisitor::class)->group(function () {
    Route::get('dashboard', DashboardController::class);
    Route::get('stores', [StoreController::class, 'index']);
    Route::post('stores/select', [StoreController::class, 'select']);
    Route::get('conversations', [ConversationController::class, 'index']);
    Route::get('conversations/{id}', [ConversationController::class, 'show'])->whereUuid('id');
    Route::delete('conversations/{id}', [ConversationController::class, 'destroy'])->whereUuid('id');
    Route::get('campaigns', [CampaignController::class, 'index']);
    Route::get('usage', UsageController::class);

    Route::post('chat', ChatController::class)->middleware('throttle:chat');
});
