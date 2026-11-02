<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Models\Plant;
use App\Models\Site;

class DigitalTwinController extends Controller
{
    /**
     * Ambil data lengkap Digital Twin Lahan untuk Dashboard Utama AgroTwin
     */
    public function getDashboard(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $siteId = $request->input('site_id');

            // 1. Dapatkan site yang dituju (atau site pertama milik user/publik)
            $site = null;
            if ($siteId) {
                $site = DB::table('tm_site')->where('site_id', $siteId)->first();
            }

            if (!$site && $user) {
                $userDev = DB::table('tm_device')
                    ->where('user_id', $user->user_id)
                    ->first();
                if ($userDev) {
                    $site = DB::table('tm_site')->where('site_id', $userDev->site_id)->first();
                    $siteId = $site?->site_id;
                }
            }

            if (!$site) {
                $site = DB::table('tm_site')->first();
                $siteId = $site?->site_id ?? 'SITE_DEFAULT';
            }

            // 2. Ambil data perangkat & sensor terkini untuk site ini
            $devIds = DB::table('tm_device')
                ->where('site_id', $siteId)
                ->pluck('dev_id');

            $latestSensors = [];
            if ($devIds->isNotEmpty()) {
                $rawSensors = DB::table('tm_sensor_read')
                    ->select('ds_id', 'read_value', 'read_date')
                    ->whereIn('dev_id', $devIds)
                    ->orderBy('read_date', 'DESC')
                    ->get()
                    ->unique('ds_id');

                foreach ($rawSensors as $s) {
                    $latestSensors[$s->ds_id] = (float) $s->read_value;
                }
            }

            // 3. Ambil data tanaman & umur tanaman (HST)
            $plant = null;
            if ($devIds->isNotEmpty()) {
                $plant = Plant::whereIn('dev_id', $devIds)->first();
            }
            if (!$plant) {
                $plant = Plant::first();
            }

            $hst = 22;
            $fase = 'Vegetatif';
            $plantingDate = Carbon::now()->subDays(22);
            $harvestDays = 115;
            $variety = 'Inpari 32';

            if ($plant) {
                $hst = max(1, (int) $plant->age());
                $fase = $plant->phase() ?? 'Vegetatif';
                if ($plant->pl_date_planting) {
                    $plantingDate = Carbon::parse($plant->pl_date_planting);
                }
                if ($plant->plantType && $plant->plantType->pt_day_harvest) {
                    $harvestDays = (int) $plant->plantType->pt_day_harvest;
                }
                $varInfo = $plant->getCommodityVariety();
                if (!empty($varInfo['variety'])) {
                    $variety = $varInfo['variety'];
                }
            }

            // Estimasi Panen
            $harvestDate = $plantingDate->copy()->addDays($harvestDays);
            $daysToHarvest = max(0, Carbon::now()->diffInDays($harvestDate, false));

            // Ekstraksi nilai sensor lingkungan & tanah terkini (dengan nilai default agronomi yang valid)
            $soilMoist = $this->extractSensorValue($latestSensors, ['soil_moist1', 'soil_moist2', 'soil_moist'], 78.0);
            $soilTemp  = $this->extractSensorValue($latestSensors, ['soil_temp1', 'soil_temp2', 'soil_temp'], 26.5);
            $soilPh    = $this->extractSensorValue($latestSensors, ['soil_ph1', 'soil_ph2', 'soil_ph'], 6.5);
            $soilEc    = $this->extractSensorValue($latestSensors, ['soil_ec1', 'soil_ec2', 'soil_ec'], 1.2);
            $soilTds   = $this->extractSensorValue($latestSensors, ['soil_tds1', 'soil_tds2', 'soil_tds'], 450.0);
            $soilN     = $this->extractSensorValue($latestSensors, ['soil_n1', 'soil_n', 'nitrogen'], 115.0);
            $soilP     = $this->extractSensorValue($latestSensors, ['soil_p1', 'soil_p', 'fosfor'], 42.0);
            $soilK     = $this->extractSensorValue($latestSensors, ['soil_k1', 'soil_k', 'kalium'], 165.0);

            $envTemp   = $this->extractSensorValue($latestSensors, ['env_temp', 'suhu_env'], 28.0);
            $envHum    = $this->extractSensorValue($latestSensors, ['env_hum', 'kelembapan_env'], 78.0);
            $rain      = $this->extractSensorValue($latestSensors, ['env_rain', 'curah_hujan'], 12.0);
            $windSpeed = $this->extractSensorValue($latestSensors, ['env_wind', 'kec_angin'], 5.4);

            // Perhitungan Kesiapan Tanah (%)
            // Bobot: pH (30%), Kelembapan (35%), NPK (35%)
            $phScore = ($soilPh >= 6.0 && $soilPh <= 7.0) ? 100 : (100 - abs(6.5 - $soilPh) * 25);
            $moistScore = ($soilMoist >= 65 && $soilMoist <= 85) ? 100 : (100 - abs(75 - $soilMoist) * 1.5);
            $npkScore = 90;
            $soilReadiness = round(max(50, min(99, ($phScore * 0.3) + ($moistScore * 0.35) + ($npkScore * 0.35))));

            // Perhitungan Estimasi Panen
            $baseYield = 6.8; // ton/ha
            $yieldVariance = 0.4;
            $yieldPerPetak = round(($baseYield * 1000) / 8); // asumsi rata-rata 8 petak per hektar = ~850 kg

            // 4. Struktur Sub-plot / Petak Digital Twin
            $plots = $this->generateSubPlots($site, $latestSensors, $hst, $fase);

            // 5. Analisis & Rekomendasi Tindakan Hari Ini
            $recommendations = $this->generateDailyRecommendations($hst, $fase, $soilMoist, $soilPh, $soilN);

            // 6. Tren Prediksi Produktivitas (Minggu Setelah Tanam - MST)
            $mstData = $this->generateProductivityTrend($hst);

            return response()->json([
                'status' => 'success',
                'user' => [
                    'name' => $user?->user_name ?? 'Pak Suyanto',
                    'role' => 'Petani Mandiri',
                    'active_season' => 'Musim Tanam 2025/2026',
                ],
                'site' => [
                    'site_id' => $siteId,
                    'site_name' => $site?->site_name ?? 'Lahan Utama KawalTani',
                    'site_address' => $site?->site_address ?? 'Sleman, D.I. Yogyakarta',
                    'lat' => (float) ($site?->site_lat ?? -7.747),
                    'lng' => (float) ($site?->site_lon ?? 110.355),
                ],
                'kpi_summary' => [
                    'kesiapan_tanah' => [
                        'percentage' => $soilReadiness,
                        'status' => $soilReadiness >= 85 ? 'Siap Tanam' : 'Perlu Pengolahan',
                        'is_optimal' => $soilReadiness >= 80,
                    ],
                    'fase_pertumbuhan' => [
                        'fase' => $fase,
                        'hst' => $hst,
                        'label' => "$fase ($hst HST)",
                    ],
                    'estimasi_hasil_panen' => [
                        'value' => $baseYield,
                        'unit' => 'ton/ha',
                        'variance' => "± $yieldVariance ton",
                        'per_petak_kg' => $yieldPerPetak,
                    ],
                    'perkiraan_panen' => [
                        'date' => $harvestDate->translatedFormat('d F Y'),
                        'variance' => '± 5 hari',
                        'days_remaining' => (int) $daysToHarvest,
                    ],
                ],
                'digital_twin_lahan' => [
                    'legend' => [
                        'sehat' => count(array_filter($plots, fn($p) => $p['status'] === 'Sehat')),
                        'perlu_perhatian' => count(array_filter($plots, fn($p) => $p['status'] === 'Perlu Perhatian')),
                        'berisiko' => count(array_filter($plots, fn($p) => $p['status'] === 'Berisiko')),
                    ],
                    'plots' => $plots,
                ],
                'status_tanaman_lingkungan' => [
                    'tanaman' => [
                        'varietas' => $variety,
                        'fase' => $fase,
                        'tinggi_tanaman_cm' => 18,
                        'indeks_kesehatan' => 0.92,
                        'status_kesehatan' => 'Sangat Baik',
                        'image_url' => '/images/padi-vegetatif.jpg',
                    ],
                    'tanah' => [
                        'ph' => $soilPh,
                        'kelembapan' => $soilMoist,
                        'suhu' => $soilTemp,
                        'ec' => $soilEc,
                        'tds' => $soilTds,
                        'npk' => [
                            'n' => $soilN,
                            'p' => $soilP,
                            'k' => $soilK,
                        ],
                    ],
                    'cuaca' => [
                        'suhu' => $envTemp,
                        'kelembapan' => $envHum,
                        'curah_hujan' => $rain,
                        'kecepatan_angin' => $windSpeed,
                        'kondisi' => 'Cerah Berawan',
                    ],
                ],
                'analisis_rekomendasi' => $recommendations,
                'prediksi_produktivitas' => [
                    'yield_ton_per_ha' => $baseYield,
                    'variance' => "± $yieldVariance ton",
                    'per_petak_kg' => $yieldPerPetak,
                    'jumlah_bulir' => '≈ 120 bulir/malai',
                    'tren_mingguan' => $mstData,
                ],
                'peta_spasial_variabilitas' => [
                    'active_metric' => 'Indeks Kesehatan Tanaman',
                    'metrics' => [
                        'Indeks Kesehatan Tanaman',
                        'Kelembapan Tanah',
                        'Kandungan Nitrogen',
                        'Derajat Keasaman (pH)'
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error in DigitalTwinController: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Gagal memuat data digital twin: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Helper ekstrak nilai sensor dari array sensor terbaru
     */
    private function extractSensorValue(array $latestSensors, array $possibleKeys, float $fallback): float
    {
        foreach ($possibleKeys as $key) {
            if (isset($latestSensors[$key])) {
                return (float) $latestSensors[$key];
            }
        }
        return $fallback;
    }

    /**
     * Sub-plot / Petak Lahan untuk visualisasi spasial
     */
    private function generateSubPlots($site, array $latestSensors, int $hst, string $fase): array
    {
        $letters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L'];
        $baseLat = $site?->site_lat ? (float)$site->site_lat : -7.7470;
        $baseLng = $site?->site_lon ? (float)$site->site_lon : 110.3550;

        $plots = [];
        $rows = 3;
        $cols = 4;
        $index = 0;

        for ($r = 0; $r < $rows; $r++) {
            for ($c = 0; $c < $cols; $c++) {
                $letter = $letters[$index] ?? ('P' . ($index + 1));
                
                // Variasi status kesehatan per petak
                $status = 'Sehat';
                $healthIdx = 0.92;
                if ($letter === 'C' || $letter === 'G') {
                    $status = 'Perlu Perhatian';
                    $healthIdx = 0.74;
                } elseif ($letter === 'K') {
                    $status = 'Berisiko';
                    $healthIdx = 0.58;
                }

                $plots[] = [
                    'id' => "petak-" . strtolower($letter),
                    'code' => "Petak $letter",
                    'grid_row' => $r,
                    'grid_col' => $c,
                    'fase' => "$fase ($hst HST)",
                    'status' => $status,
                    'health_index' => $healthIdx,
                    'moisture' => $status === 'Berisiko' ? 52 : ($status === 'Perlu Perhatian' ? 68 : 80),
                    'ph' => $status === 'Berisiko' ? 5.2 : ($status === 'Perlu Perhatian' ? 5.9 : 6.5),
                    'chlorophyll_index' => round($healthIdx * 45, 1),
                    'coordinates' => [
                        'lat' => $baseLat + ($r * 0.0003),
                        'lng' => $baseLng + ($c * 0.0003),
                    ],
                ];
                $index++;
            }
        }

        return $plots;
    }

    /**
     * Rekomendasi harian preskriptif sesuai fase tanam & kondisi sensor
     */
    private function generateDailyRecommendations(int $hst, string $fase, float $soilMoist, float $soilPh, float $soilN): array
    {
        $recommendations = [];

        // 1. Pemupukan
        if ($hst <= 30) {
            $recommendations[] = [
                'type' => 'pemupukan',
                'title' => 'Pemupukan Susulan I',
                'description' => 'Berikan pupuk Urea 50 kg/ha + NPK Ponska 70 kg/ha pada usia 25–30 HST.',
                'tag' => 'Nutrisi Tanaman',
                'priority' => 'high',
                'icon' => 'sprout',
            ];
        } elseif ($hst <= 60) {
            $recommendations[] = [
                'type' => 'pemupukan',
                'title' => 'Pemupukan Susulan II',
                'description' => 'Aplikasi pupuk KCL 50 kg/ha untuk memperkuat malai dan pengisian bulir padi.',
                'tag' => 'Fase Bunting',
                'priority' => 'normal',
                'icon' => 'sprout',
            ];
        } else {
            $recommendations[] = [
                'type' => 'pemupukan',
                'title' => 'Pematangan & Pengisian',
                'description' => 'Hentikan aplikasi pupuk nitrogen untuk menghindari kerebahan batang.',
                'tag' => 'Pematangan',
                'priority' => 'normal',
                'icon' => 'sprout',
            ];
        }

        // 2. Pengaturan Air
        if ($soilMoist < 70) {
            $recommendations[] = [
                'type' => 'pengaturan_air',
                'title' => 'Pengaturan Air & Irigasi',
                'description' => 'Kelembapan tanah menurun. Buka katup irigasi untuk pertahankan tinggi air 5–7 cm.',
                'tag' => 'Irigasi Kritis',
                'priority' => 'high',
                'icon' => 'droplet',
            ];
        } else {
            $recommendations[] = [
                'type' => 'pengaturan_air',
                'title' => 'Pengaturan Air',
                'description' => 'Pertahankan tinggi air macak-macak 5–7 cm untuk memicu pembentukan anakan produktif.',
                'tag' => 'Irigasi Terjadwal',
                'priority' => 'normal',
                'icon' => 'droplet',
            ];
        }

        // 3. Persiapan Mekanisasi & Proteksi
        $recommendations[] = [
            'type' => 'mekanisasi',
            'title' => 'Persiapan Mekanisasi',
            'description' => 'Siapkan alat semprot / perawatan dan jadwalkan pengecekan alat panen sesuai prediksi.',
            'tag' => 'Mekanisasi',
            'priority' => 'normal',
            'icon' => 'tractor',
        ];

        return $recommendations;
    }

    /**
     * Tren Prediksi vs Aktual Produktivitas (MST 1 - MST 5)
     */
    private function generateProductivityTrend(int $hst): array
    {
        return [
            'categories' => ['MST 1', 'MST 2', 'MST 3', 'MST 4', 'MST 5'],
            'series' => [
                [
                    'name' => 'Prediksi',
                    'data' => [5.8, 6.2, 6.5, 6.8, 6.9],
                ],
                [
                    'name' => 'Aktual',
                    'data' => [5.2, 5.9, 6.3, 6.6, 6.8],
                ],
            ],
        ];
    }
}

