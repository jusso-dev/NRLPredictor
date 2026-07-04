<?php

use App\Http\Controllers\Api\V1\MatchController;
use App\Http\Controllers\Api\V1\MethodologyController;
use App\Http\Controllers\Api\V1\MultiBetController;
use App\Http\Controllers\Api\V1\OddsController;
use App\Http\Controllers\Api\V1\PlayerController;
use App\Http\Controllers\Api\V1\PredictionController;
use App\Http\Controllers\Api\V1\RoundController;
use App\Http\Controllers\Api\V1\TeamController;
use App\Http\Controllers\ChatController;
use Illuminate\Support\Facades\Route;

// --- Chat (Codex CLI, in-process) ---
Route::post('/chat', [ChatController::class, 'send']);

// --- Public API v1 ---
Route::prefix('v1')->group(function () {
    Route::get('/rounds', [RoundController::class, 'index']);
    Route::get('/rounds/current', [RoundController::class, 'current']);

    Route::get('/matches', [MatchController::class, 'index']);
    Route::get('/matches/current', [MatchController::class, 'current']);
    Route::get('/matches/{match}', [MatchController::class, 'show']);

    Route::get('/matches/{match}/predictions', [PredictionController::class, 'forMatch']);
    Route::get('/predictions/leaderboard', [PredictionController::class, 'leaderboard']);

    Route::get('/teams', [TeamController::class, 'index']);
    Route::get('/teams/{team}', [TeamController::class, 'show']);

    Route::get('/players/{player}', [PlayerController::class, 'show']);

    Route::get('/multi-bet', [MultiBetController::class, 'build']);

    Route::get('/odds', [OddsController::class, 'index']);
    Route::get('/matches/{match}/odds', [OddsController::class, 'forMatch']);

    Route::get('/methodology', [MethodologyController::class, 'index']);
});
