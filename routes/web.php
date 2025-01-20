<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/success', function () {
    return response()->json(['message' => 'Success']);
});