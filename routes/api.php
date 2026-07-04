<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\EmailVerificationController;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::delete('/logout', [AuthController::class, 'logout']);

});

Route::post('/business/administrator/register', [RegisterController::class, 'register']);

Route::get(
    '/email/verify/{id}/{hash}',
    [EmailVerificationController::class, 'verify']
)->middleware('signed')->name('verification.verify');
