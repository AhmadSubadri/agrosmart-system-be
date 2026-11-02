<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "--- SITES ---\n";
foreach (DB::table('tm_site')->get() as $s) {
    echo "site_id: {$s->site_id}, name: {$s->site_name}, lat: {$s->site_lat}, lon: {$s->site_lon}\n";
}

echo "\n--- DEVICES ---\n";
foreach (DB::table('tm_device')->get() as $d) {
    echo "dev_id: {$d->dev_id}, site_id: {$d->site_id}, user_id: {$d->user_id}\n";
}

echo "\n--- SENSORS (td_device_sensors) ---\n";
foreach (DB::table('td_device_sensors')->get() as $ds) {
    echo "ds_id: {$ds->ds_id}, dev_id: {$ds->dev_id}, ds_name: {$ds->ds_name}\n";
}

echo "\n--- RECENT SENSOR READS (tm_sensor_read) ---\n";
$reads = DB::table('tm_sensor_read')->orderBy('read_date', 'desc')->limit(20)->get();
foreach ($reads as $r) {
    echo "dev: {$r->dev_id}, sensor: {$r->ds_id}, val: {$r->read_value}, date: {$r->read_date}\n";
}

echo "\n--- PLANTS (tm_plant) ---\n";
foreach (DB::table('tm_plant')->get() as $p) {
    echo "pl_id: {$p->pl_id}, dev: {$p->dev_id}, name: {$p->pl_name}, date: {$p->pl_date_planting}\n";
}

echo "\n--- USERS ---\n";
foreach (DB::table('tm_user')->get() as $u) {
    echo "user_id: {$u->user_id}, name: {$u->user_name}\n";
}

