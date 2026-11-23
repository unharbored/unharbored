<?php

declare(strict_types=1);

use App\Http\Controllers\StatusController;
use Illuminate\Support\Facades\Route;

/*
 * Three endpoints, each answering a different question.
 *
 * /        What is running, and is every part of the deploy actually working.
 * /health  Is PHP answering. Touches nothing else, so a database blip does
 *          not pull a healthy node out of the load balancer.
 * /ready   Should this node receive traffic. May touch dependencies.
 */

Route::get('/', [StatusController::class, 'show']);
Route::get('/health', [StatusController::class, 'health']);
Route::get('/ready', [StatusController::class, 'ready']);
