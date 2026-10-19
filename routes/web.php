<?php

use Illuminate\Support\Facades\Route;

// Serves the built React app for every page that isn't an API call
Route::get('/{any?}', function () {
    return response()->file(public_path('index.html'));
})->where('any', '^(?!api/).*$');
