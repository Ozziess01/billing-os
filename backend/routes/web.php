<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['name' => 'BillingOS', 'api' => '/api/v1']));
