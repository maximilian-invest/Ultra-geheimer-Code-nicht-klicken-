<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiImageService
{
    private string $apiKey;

    public function __construct()
    {
        $this->apiKey = env('OPENAI_API_KEY', '');
    }

    public function generate(string $prompt, string $size = '1792x1024', string $quality = 'standard'): ?string
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(120)->post('https://api.openai.com/v1/images/generations', [
                'model' => 'dall-e-3',
                'prompt' => $prompt,
                'n' => 1,
                'size' => $size,
                'quality' => $quality,
                'response_format' => 'b64_json',
            ]);

            if ($response->successful()) {
                return $response->json('data.0.b64_json');
            }
            Log::error('DALL-E failed: ' . $response->body());
            return null;
        } catch (\Throwable $e) {
            Log::error('DALL-E error: ' . $e->getMessage());
            return null;
        }
    }
}
