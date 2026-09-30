<?php

use App\Http\Controllers\Api\CourtController;
use App\Http\Controllers\Api\GameController;
use App\Http\Controllers\Api\PlayerController;
use App\Http\Controllers\Api\QueueController;
use Illuminate\Support\Facades\Route;

Route::get('players', [PlayerController::class, 'index']);
Route::post('players', [PlayerController::class, 'store']);
Route::get('players/{player}', [PlayerController::class, 'show']);

Route::get('courts', [CourtController::class, 'index']);
Route::post('courts', [CourtController::class, 'store']);
Route::patch('courts/{court}/status', [CourtController::class, 'updateStatus']);

Route::get('queue', [QueueController::class, 'index']);
Route::post('queue', [QueueController::class, 'store']);
Route::post('queue/call-next', [QueueController::class, 'callNext']);
Route::get('queue/{queue}', [QueueController::class, 'show']);
Route::post('queue/{queue}/cancel', [QueueController::class, 'cancel']);

Route::get('games', [GameController::class, 'index']);
Route::post('games', [GameController::class, 'store']);
Route::post('games/{game}/finish', [GameController::class, 'finish']);
