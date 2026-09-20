<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'name' => 'RJAY Hotspot API',
    'status' => 'ok',
]));
