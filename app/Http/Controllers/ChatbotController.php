<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use App\Models\Chatbot;
use App\Models\Device;
use Carbon\Carbon;
use DB;

class ChatbotController extends Controller
{
    /* ===================== ENTRY POINT ===================== */
    public function send(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'message' => 'required|string',
                'name_chat' => 'nullable|string|max:64',
            ]);

            $user = Auth::user();
            if (!$user) {
                return response()->json(['message' => 'Unauthenticated'], 401);
            }

            $message = trim($request->message);
            $nameChat = $request->name_chat ?? Str::limit($message, 40, '...');
            $userId = $user->user_id;

            Log::info('[CHATBOT START]', [
                'user_id' => $userId,
                'message' => $message,
            ]);

            // Ambil riwayat percakapan untuk context
            $conversationHistory = $this->getConversationHistory($userId, $nameChat, 3);

            $intent = $this->detectIntent($message);
            Log::info('[INTENT DETECTED]', ['intent' => $intent]);

            if ($intent === 'general') {
                $reply = $this->sendGeneralMessage($message, $conversationHistory);
            } else {
                $reply = $this->handleDataAndAnalysis($message, $intent, $userId, $conversationHistory);
            }

            if (empty($reply)) {
                $reply = 'Maaf, saya tidak dapat memberikan jawaban saat ini. Silakan coba lagi.';
            }

            // Simpan ke database
            Chatbot::create([
                'user_id' => $userId,
                'name_chat' => $nameChat,
                'message' => $message,
                'response' => $reply,
            ]);

            Log::info('[CHATBOT SUCCESS]', ['response_length' => strlen($reply)]);

            return response()->json([
                'name_chat' => $nameChat,
                'response' => $reply,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('[VALIDATION ERROR]', ['errors' => $e->errors()]);
            return response()->json(['error' => 'Data tidak valid', 'details' => $e->errors()], 422);
        } catch (\Throwable $e) {
            Log::error('[CHATBOT CRITICAL ERROR]', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'error' => 'Maaf, sistem chatbot sedang mengalami gangguan. Silakan coba lagi.',
                'debug' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /* ===================== CORE LOGIC ===================== */
    private function handleDataAndAnalysis(
        string $message,
        string $intent,
        string $userId,
        array $conversationHistory
    ): string {
        try {
            // Ambil site_id dari user
            $siteId = $this->getSiteIdByUserId($userId);
            
            if (!$siteId) {
                return "Maaf, saya tidak menemukan data lahan yang terhubung dengan akun Anda. Pastikan perangkat sensor sudah terdaftar.";
            }

            Log::info('[SITE ID]', ['site_id' => $siteId]);

            // Extract date dari pertanyaan
            $date = $this->extractDate($message);
            Log::info('[DATE EXTRACTED]', ['date' => $date]);

            // Query data sensor dengan JOIN
            $sensorData = $this->getSensorData($siteId, $date);

            if (!$sensorData || count($sensorData) === 0) {
                $dateInfo = $date ? " pada tanggal " . Carbon::parse($date)->format('d F Y') : "";
                return "Maaf, belum ada data sensor yang tersedia{$dateInfo}. Pastikan perangkat sensor Anda aktif dan mengirim data.";
            }

            Log::info('[SENSOR DATA FOUND]', ['count' => count($sensorData)]);

            $formattedData = $this->formatSensorDataFromReadings($sensorData, $date);

            return $this->sendDataAwareMessage(
                $message,
                $formattedData,
                $intent,
                $date,
                $conversationHistory
            );

        } catch (\Exception $e) {
            Log::error('[HANDLE DATA ERROR]', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 'Maaf, terjadi kesalahan saat mengambil data sensor. Silakan coba lagi.';
        }
    }

    /* ===================== DATABASE QUERIES ===================== */
    private function getSensorData(string $siteId, ?string $date): array
    {
        try {
            $query = DB::table('tm_sensor_read as sr')
                ->join('tm_device as d', 'sr.dev_id', '=', 'd.dev_id')
                ->join('td_device_sensors as ds', function($join) {
                    $join->on('sr.dev_id', '=', 'ds.dev_id')
                         ->on('sr.ds_id', '=', 'ds.ds_id');
                })
                ->where('d.site_id', $siteId)
                ->where('sr.read_sts', 1) // Hanya data yang valid
                ->select(
                    'sr.read_id',
                    'sr.read_date',
                    'sr.read_value',
                    'ds.ds_name',
                    'ds.ds_address',
                    'd.dev_name'
                );

            if ($date) {
                $query->whereDate('sr.read_date', $date);
            }

            // Ambil data terakhir untuk setiap sensor
            $results = $query->orderBy('sr.read_date', 'desc')
                ->get()
                ->groupBy('ds_name')
                ->map(function($items) {
                    return $items->first(); // Ambil yang terbaru per sensor
                })
                ->values()
                ->toArray();

            Log::info('[SENSOR QUERY RESULT]', [
                'count' => count($results),
                'sample' => $results[0] ?? null
            ]);

            return $results;

        } catch (\Exception $e) {
            Log::error('[GET SENSOR DATA ERROR]', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }

    private function formatSensorDataFromReadings(array $readings, ?string $date): array
    {
        $formattedData = [
            'Nitrogen (N)' => 'tidak tersedia',
            'Fosfor (P)' => 'tidak tersedia',
            'Kalium (K)' => 'tidak tersedia',
            'pH Tanah' => 'tidak tersedia',
            'Suhu Tanah' => 'tidak tersedia',
            'Kelembapan Tanah' => 'tidak tersedia',
            'EC (Konduktivitas)' => 'tidak tersedia',
            'TDS (Total Dissolved Solids)' => 'tidak tersedia',
            'Waktu Pengukuran' => 'tidak tersedia'
        ];

        $latestDate = null;

        foreach ($readings as $reading) {
            $sensorName = strtolower($reading->ds_name ?? '');
            $value = $reading->read_value ?? null;
            $readDate = $reading->read_date ?? null;

            if ($readDate && (!$latestDate || $readDate > $latestDate)) {
                $latestDate = $readDate;
            }

            // Mapping sensor name ke format yang sesuai
            if (Str::contains($sensorName, ['nitrogen', 'n', 'nitro'])) {
                $formattedData['Nitrogen (N)'] = $this->formatValue($value, 'mg/kg');
            } 
            elseif (Str::contains($sensorName, ['fosfor', 'phosphor', 'p', 'phos'])) {
                $formattedData['Fosfor (P)'] = $this->formatValue($value, 'mg/kg');
            }
            elseif (Str::contains($sensorName, ['kalium', 'potassium', 'k', 'kali'])) {
                $formattedData['Kalium (K)'] = $this->formatValue($value, 'mg/kg');
            }
            elseif (Str::contains($sensorName, ['ph', 'keasaman'])) {
                $formattedData['pH Tanah'] = $this->formatValue($value, '');
            }
            elseif (Str::contains($sensorName, ['suhu', 'temp', 'temperature'])) {
                $formattedData['Suhu Tanah'] = $this->formatValue($value, '°C');
            }
            elseif (Str::contains($sensorName, ['kelembapan', 'kelembaban', 'humidity', 'lembab'])) {
                $formattedData['Kelembapan Tanah'] = $this->formatValue($value, '%');
            }
            elseif (Str::contains($sensorName, ['ec', 'conductivity', 'konduktivitas'])) {
                $formattedData['EC (Konduktivitas)'] = $this->formatValue($value, 'µS/cm');
            }
            elseif (Str::contains($sensorName, ['tds', 'dissolved', 'solid'])) {
                $formattedData['TDS (Total Dissolved Solids)'] = $this->formatValue($value, 'ppm');
            }
        }

        if ($latestDate) {
            $formattedData['Waktu Pengukuran'] = Carbon::parse($latestDate)->format('d M Y H:i');
        }

        return $formattedData;
    }

    private function formatValue($value, string $unit): string
    {
        if ($value === null || $value === '') {
            return 'tidak tersedia';
        }

        if (is_string($value)) {
            $value = floatval($value);
        }

        // Format angka dengan 2 desimal jika perlu
        if (is_numeric($value)) {
            $value = round($value, 2);
        }

        return $value . ($unit ? ' ' . $unit : '');
    }

    private function getSiteIdByUserId(string $userId): ?string
    {
        try {
            return DB::table('tm_device')
                ->where('user_id', $userId)
                ->where('dev_sts', 1) // Device aktif
                ->value('site_id');
        } catch (\Exception $e) {
            Log::error('[GET SITE ID ERROR]', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /* ===================== OPENAI ===================== */
    private function sendDataAwareMessage(
        string $userMessage,
        array $sensorData,
        string $intent,
        ?string $date,
        array $conversationHistory
    ): string {
        try {
            $apiKey = env('OPENAI_API_KEY');
            if (empty($apiKey)) {
                Log::error('[OPENAI] API Key not configured');
                return 'Maaf, sistem AI belum dikonfigurasi dengan benar. Silakan hubungi administrator.';
            }

            $dataContext = $this->formatSensorDataContext($sensorData, $date);
            $systemPrompt = $this->buildSystemPrompt($intent);

            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'system', 'content' => $dataContext],
            ];

            // Tambahkan riwayat percakapan
            $historyCount = 0;
            foreach ($conversationHistory as $history) {
                if ($historyCount >= 3) break;
                $messages[] = ['role' => 'user', 'content' => $history['message']];
                $messages[] = ['role' => 'assistant', 'content' => $history['response']];
                $historyCount++;
            }

            $messages[] = ['role' => 'user', 'content' => $userMessage];

            Log::info('[OPENAI REQUEST]', ['message_count' => count($messages)]);

            $response = Http::timeout(45)
                ->retry(2, 100)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-3.5-turbo',
                    'messages' => $messages,
                    'temperature' => 0.4,
                    'max_tokens' => 800,
                ]);

            if (!$response->successful()) {
                Log::error('[OPENAI API ERROR]', [
                    'status' => $response->status(),
                    'body' => $response->json()
                ]);

                if ($response->status() === 401) {
                    return 'Maaf, konfigurasi API tidak valid. Silakan hubungi administrator.';
                } elseif ($response->status() === 429) {
                    return 'Maaf, sistem sedang sibuk. Silakan coba lagi dalam beberapa saat.';
                }

                return 'Maaf, terjadi kesalahan saat memproses permintaan Anda. Silakan coba lagi.';
            }

            $result = $response->json();

            if (!isset($result['choices'][0]['message']['content'])) {
                Log::error('[OPENAI RESPONSE ERROR]', ['response' => $result]);
                return 'Maaf, saya tidak dapat memberikan jawaban saat ini. Silakan coba lagi.';
            }

            $answer = trim($result['choices'][0]['message']['content']);

            if (empty($answer)) {
                return 'Maaf, saya tidak dapat memberikan jawaban yang tepat. Bisakah Anda mengulang pertanyaan dengan cara yang berbeda?';
            }

            Log::info('[OPENAI SUCCESS]', ['answer_length' => strlen($answer)]);

            return $answer;

        } catch (\Exception $e) {
            Log::error('[OPENAI EXCEPTION]', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 'Maaf, gagal memproses analisis data. Silakan coba beberapa saat lagi.';
        }
    }

    private function sendGeneralMessage(string $message, array $conversationHistory): string
    {
        try {
            $apiKey = env('OPENAI_API_KEY');
            if (empty($apiKey)) {
                return 'Maaf, sistem AI belum dikonfigurasi. Silakan hubungi administrator.';
            }

            $systemPrompt = "Kamu adalah KawalTani, asisten pertanian pintar untuk petani Indonesia.

TUGAS KAMU:
- Menjawab pertanyaan seputar pertanian dengan ramah dan mudah dipahami
- Memberikan tips praktis dan edukatif
- Menggunakan bahasa yang sederhana tapi informatif
- Fokus pada solusi praktis untuk petani

ATURAN:
- Jangan berikan informasi medis
- Jika ditanya data sensor spesifik, arahkan untuk bertanya dengan kata kunci 'data', 'sensor', atau 'berapa'
- Berikan jawaban yang konkret dan actionable
- Jika tidak yakin, akui dengan jujur
- Jawab dalam bahasa Indonesia";

            $messages = [['role' => 'system', 'content' => $systemPrompt]];

            $historyCount = 0;
            foreach ($conversationHistory as $history) {
                if ($historyCount >= 3) break;
                $messages[] = ['role' => 'user', 'content' => $history['message']];
                $messages[] = ['role' => 'assistant', 'content' => $history['response']];
                $historyCount++;
            }

            $messages[] = ['role' => 'user', 'content' => $message];

            $response = Http::timeout(45)
                ->retry(2, 100)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-3.5-turbo',
                    'messages' => $messages,
                    'temperature' => 0.7,
                    'max_tokens' => 600,
                ]);

            if (!$response->successful()) {
                return 'Maaf, KawalTani sedang mengalami gangguan. Silakan coba lagi.';
            }

            $result = $response->json();
            $answer = isset($result['choices'][0]['message']['content']) 
                ? trim($result['choices'][0]['message']['content'])
                : 'Maaf, tidak ada jawaban yang tersedia saat ini.';

            if (empty($answer)) {
                return 'Maaf, saya tidak dapat menjawab saat ini. Silakan coba lagi.';
            }

            return $answer;

        } catch (\Exception $e) {
            Log::error('[OPENAI ERROR - GENERAL]', ['error' => $e->getMessage()]);
            return 'Maaf, KawalTani sedang mengalami gangguan. Silakan coba beberapa saat lagi.';
        }
    }

    /* ===================== UTILITIES ===================== */
    private function detectIntent(string $message): string
    {
        $message = strtolower($message);

        $analysisKeywords = [
            'kenapa', 'mengapa', 'penyebab', 'sebab', 'alasan',
            'dampak', 'pengaruh', 'akibat', 'efek', 'analisis'
        ];

        foreach ($analysisKeywords as $keyword) {
            if (Str::contains($message, $keyword)) {
                return 'analysis';
            }
        }

        $recommendationKeywords = [
            'rekomendasi', 'saran', 'solusi', 'sebaiknya', 'bagaimana',
            'cara', 'tips', 'apa yang harus', 'gimana'
        ];

        foreach ($recommendationKeywords as $keyword) {
            if (Str::contains($message, $keyword)) {
                return 'recommendation';
            }
        }

        $dataKeywords = [
            'data', 'berapa', 'nilai', 'sensor', 'hasil', 'cek', 'lihat',
            'ph', 'nitrogen', 'fosfor', 'kalium', 'npk',
            'suhu', 'kelembapan', 'kelembaban', 'lembab',
            'ec', 'tds', 'tanah', 'kondisi', 'status'
        ];

        foreach ($dataKeywords as $keyword) {
            if (Str::contains($message, $keyword)) {
                return 'data';
            }
        }

        return 'general';
    }

    private function extractDate(string $message): ?string
    {
        // Format YYYY-MM-DD
        if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $message, $matches)) {
            try {
                $date = Carbon::createFromFormat('Y-m-d', $matches[0]);
                if ($date && $date->year >= 2020 && $date->year <= 2030) {
                    return $date->toDateString();
                }
            } catch (\Exception $e) {
                Log::warning('[DATE PARSE ERROR]', ['date' => $matches[0]]);
            }
        }

        // Format DD-MM-YYYY atau DD/MM/YYYY
        if (preg_match('/(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})/', $message, $matches)) {
            try {
                $date = Carbon::createFromFormat('d-m-Y', "{$matches[1]}-{$matches[2]}-{$matches[3]}");
                if ($date && $date->year >= 2020 && $date->year <= 2030) {
                    return $date->toDateString();
                }
            } catch (\Exception $e) {
                Log::warning('[DATE PARSE ERROR]', ['date' => $matches[0]]);
            }
        }

        $message = strtolower($message);

        if (Str::contains($message, ['hari ini', 'sekarang', 'saat ini'])) {
            return Carbon::today()->toDateString();
        }

        if (Str::contains($message, ['kemarin'])) {
            return Carbon::yesterday()->toDateString();
        }

        if (Str::contains($message, ['kemarin lusa', '2 hari lalu'])) {
            return Carbon::today()->subDays(2)->toDateString();
        }

        return null;
    }

    private function formatSensorDataContext(array $sensorData, ?string $date): string
    {
        $context = "=== DATA SENSOR LAHAN PETANI ===\n\n";

        foreach ($sensorData as $parameter => $value) {
            $context .= "{$parameter}: {$value}\n";
        }

        if ($date) {
            $context .= "\n📅 Data dari tanggal: " . Carbon::parse($date)->format('d F Y') . "\n";
        } else {
            $context .= "\n📅 Data sensor terbaru\n";
        }

        $context .= "\n=== PENTING ===\n";
        $context .= "- Gunakan HANYA data di atas untuk menjawab\n";
        $context .= "- Jika data tidak tersedia, katakan dengan jelas\n";
        $context .= "- JANGAN mengarang angka atau data\n";
        $context .= "- Berikan penjelasan yang mudah dipahami petani\n";
        $context .= "- Jawab dalam bahasa Indonesia\n";

        return $context;
    }

    private function buildSystemPrompt(string $intent): string
    {
        $basePrompt = "Kamu adalah KawalTani, asisten pertanian pintar untuk petani Indonesia. Jawab SELALU dalam bahasa Indonesia.";

        switch ($intent) {
            case 'analysis':
                return $basePrompt . "

TUGAS: ANALISIS & PENJELASAN

CARA MENJAWAB:
1. Lihat data sensor yang tersedia
2. Jelaskan kondisi (normal/tidak normal)
3. Berikan PENYEBAB berdasarkan data
4. Jelaskan DAMPAK jika dibiarkan
5. Berikan saran singkat

ATURAN:
- Gunakan HANYA data yang tersedia
- Jika data tidak ada, katakan 'data tidak tersedia'
- JANGAN mengarang angka
- Gunakan bahasa sederhana
- Jawab dalam bahasa Indonesia";

            case 'recommendation':
                return $basePrompt . "

TUGAS: REKOMENDASI & SOLUSI

CARA MENJAWAB:
1. Analisis data sensor
2. Tentukan kondisi tanah
3. Berikan 3-5 rekomendasi PRAKTIS
4. Prioritaskan solusi mudah dilakukan
5. Sertakan estimasi jika relevan

ATURAN:
- Rekomendasi berdasarkan DATA
- JANGAN rekomendasikan brand spesifik
- Fokus pada tindakan praktis
- Gunakan bahasa jelas
- Jawab dalam bahasa Indonesia";

            case 'data':
                return $basePrompt . "

TUGAS: INFORMASI DATA

CARA MENJAWAB:
1. Tampilkan data yang ditanyakan
2. Interpretasi singkat (normal/tidak)
3. Jelaskan standar ideal
4. Bandingkan dengan standar

ATURAN:
- Gunakan HANYA angka dari data tersedia
- Format: 'Nilai X adalah Y satuan'
- Jika tidak ada, katakan 'Data X belum tersedia'
- JANGAN mengarang angka
- Jawab singkat dan faktual
- Jawab dalam bahasa Indonesia";

            default:
                return $basePrompt;
        }
    }

    private function getConversationHistory(string $userId, string $nameChat, int $limit = 3): array
    {
        try {
            return Chatbot::where('user_id', $userId)
                ->where('name_chat', $nameChat)
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get()
                ->reverse()
                ->map(function ($chat) {
                    return [
                        'message' => $chat->message,
                        'response' => $chat->response
                    ];
                })
                ->toArray();
        } catch (\Exception $e) {
            Log::error('[CONVERSATION HISTORY ERROR]', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /* ===================== API ENDPOINTS ===================== */
    public function getHistoryByNameChat($nameChat): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $history = Chatbot::where('user_id', $user->user_id)
            ->where('name_chat', $nameChat)
            ->orderBy('created_at')
            ->get();

        return response()->json($history);
    }

    public function deleteByNameChat($nameChat): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        Chatbot::where('user_id', $user->user_id)
            ->where('name_chat', $nameChat)
            ->delete();

        return response()->json(['message' => 'Percakapan berhasil dihapus']);
    }

    public function listChats(): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $latestChats = Chatbot::selectRaw('MAX(id) as id, name_chat, MAX(created_at) as created_at')
            ->where('user_id', $user->user_id)
            ->groupBy('name_chat')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($latestChats);
    }

    public function renameChat(Request $request, $nameChat): JsonResponse
    {
        $request->validate([
            'newName' => 'required|string|max:64',
        ]);

        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (Chatbot::where('user_id', $user->user_id)->where('name_chat', $request->newName)->exists()) {
            return response()->json(['error' => 'Nama chat sudah digunakan'], 422);
        }

        Chatbot::where('user_id', $user->user_id)
            ->where('name_chat', $nameChat)
            ->update(['name_chat' => $request->newName]);

        return response()->json(['message' => 'Nama chat berhasil diganti']);
    }

    public function newChat(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $message = $request->input('message');
        $userId = $user->user_id;

        if (!$message || trim($message) === '') {
            return response()->json(['name_chat' => '', 'response' => '']);
        }

        $chatName = Str::limit($message, 40, '...');

        try {
            $apiKey = env('OPENAI_API_KEY');
            if (empty($apiKey)) {
                $reply = 'Maaf, sistem AI belum dikonfigurasi. Silakan hubungi administrator.';
            } else {
                $systemPrompt = "Kamu adalah KawalTani, asisten pertanian pintar untuk petani Indonesia. Jawab dalam bahasa Indonesia dengan ramah dan informatif.";

                $response = Http::timeout(45)
                    ->retry(2, 100)
                    ->withHeaders([
                        'Authorization' => 'Bearer ' . $apiKey,
                        'Content-Type' => 'application/json',
                    ])
                    ->post('https://api.openai.com/v1/chat/completions', [
                        'model' => 'gpt-3.5-turbo',
                        'messages' => [
                            ['role' => 'system', 'content' => $systemPrompt],
                            ['role' => 'user', 'content' => $message],
                        ],
                        'temperature' => 0.7,
                        'max_tokens' => 600,
                    ]);

                if (!$response->successful()) {
                    $reply = 'Maaf, KawalTani sedang mengalami gangguan. Silakan coba lagi.';
                } else {
                    $result = $response->json();
                    $reply = isset($result['choices'][0]['message']['content'])
                        ? trim($result['choices'][0]['message']['content'])
                        : 'Maaf, tidak ada balasan.';

                    if (empty($reply)) {
                        $reply = 'Maaf, saya tidak dapat menjawab saat ini. Silakan coba lagi.';
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('[OPENAI ERROR in newChat]', ['error' => $e->getMessage()]);
            $reply = 'Maaf, KawalTani sedang mengalami masalah. Silakan coba lagi nanti.';
        }

        Chatbot::create([
            'user_id' => $userId,
            'name_chat' => $chatName,
            'message' => $message,
            'response' => $reply,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'name_chat' => $chatName,
            'response' => $reply,
        ]);
    }
}