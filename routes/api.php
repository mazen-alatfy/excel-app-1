<?php

use App\Http\Controllers\ContactController;
use Illuminate\Support\Facades\Route;

Route::post('/contacts/upload', [ContactController::class, 'upload']);
Route::get('/contacts', [ContactController::class, 'index']);
