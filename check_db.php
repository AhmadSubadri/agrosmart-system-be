<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$summary = Illuminate\Support\Facades\DB::table('tm_sensor_read')
    ->select('ds_id', 
        Illuminate\Support\Facades\DB::raw('COUNT(*) as total'),
        Illuminate\Support\Facades\DB::raw('MIN(read_date) as min_date'),
        Illuminate\Support\Facades\DB::raw('MAX(read_date) as max_date'),
        Illuminate\Support\Facades\DB::raw('MIN(read_value) as min_val'),
        Illuminate\Support\Facades\DB::raw('MAX(read_value) as max_val')
    )
    ->groupBy('ds_id')
    ->get();

echo json_encode($summary, JSON_PRETTY_PRINT);
