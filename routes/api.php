<?php

use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CourtController;
use App\Http\Controllers\Api\GameController;
use App\Http\Controllers\Api\MatchupController;
use App\Http\Controllers\Api\PlayerController;
use App\Http\Controllers\Api\PlayerStatusController;
use App\Http\Controllers\Api\QueueController;
use App\Http\Controllers\Api\RankingController;
use Illuminate\Support\Facades\Route;

// Public: login, player self-registration, and the QR status page
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1,login');
Route::post('queue/self-register', [QueueController::class, 'selfRegister'])->middleware('throttle:20,1,register');
Route::get('status/{token}', [PlayerStatusController::class, 'show'])->middleware('throttle:120,1,status');
Route::post('status/{token}/cancel', [PlayerStatusController::class, 'cancel'])->middleware('throttle:20,1,cancel');

// Staff only
Route::middleware('auth:sanctum')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);

    Route::get('players', [PlayerController::class, 'index']);
    Route::post('players', [PlayerController::class, 'store']);
    Route::get('players/{player}', [PlayerController::class, 'show']);

    Route::get('courts', [CourtController::class, 'index']);
    Route::post('courts', [CourtController::class, 'store']);
    Route::patch('courts/{court}/status', [CourtController::class, 'updateStatus']);

    Route::get('queue', [QueueController::class, 'index']);
    Route::post('queue', [QueueController::class, 'store']);
    Route::get('queue/pending', [QueueController::class, 'pending']);
    Route::get('queue/{queue}', [QueueController::class, 'show']);
    Route::post('queue/{queue}/cancel', [QueueController::class, 'cancel']);
    Route::post('queue/{queue}/approve', [QueueController::class, 'approve']);
    Route::post('queue/{queue}/reject', [QueueController::class, 'reject']);
    Route::post('queue/{queue}/link-player', [QueueController::class, 'linkPlayer']);

    Route::get('games', [GameController::class, 'index']);
    Route::post('games', [GameController::class, 'store']);
    Route::post('games/{game}/finish', [GameController::class, 'finish']);
    Route::post('games/{game}/score', [GameController::class, 'updateScore']);

    Route::get('matchups', [MatchupController::class, 'index']);
    Route::post('matchups', [MatchupController::class, 'store']);
    Route::post('matchups/smart', [MatchupController::class, 'smart']);
    Route::post('matchups/{matchup}/start', [MatchupController::class, 'start']);
    Route::post('matchups/{matchup}/cancel', [MatchupController::class, 'cancel']);

    Route::get('rankings', [RankingController::class, 'index']);
    Route::get('analytics', [AnalyticsController::class, 'index']);
});
