<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;

Route::get('/test', function () {
    try {
        DB::connection()->getPdo();
        $status = 'Database terkoneksi: ' . DB::connection()->getDatabaseName();
    } catch (\Exception $e) {
        $status = 'Gagal koneksi ke database: ' . $e->getMessage();
    }

    return [
        'Laravel' => app()->version(),
        'Database' => $status
    ];
});

Route::get('/', function () {
    return ['Laravel' => app()->version()];
});

// require __DIR__.'/auth.php';
