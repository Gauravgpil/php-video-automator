<?php

namespace PhpVideoAutomator\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;
use InvalidArgumentException;
use Throwable;

class AiVoiceService
{
    protected Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 60.0,
        ]);
    }

    public function generateVoiceoverWithTimestamps(string $text, string $provider, string $model, string $apiKey, string $outputFile): array
    {
        return match ($provider) {
            'eleven' => $this->generateElevenLabs($text, $model, $apiKey, $outputFile),
            'lmnt' => $this->generateLmnt($text, $model, $apiKey, $outputFile),
            'openai' => $this->generateOpenAI($text, $model, $apiKey, $outputFile),
            default => throw new InvalidArgumentException("Unsupported voice provider: {$provider}"),
        };
    }

    protected function generateElevenLabs(string $text, string $model, string $apiKey, string $outputFile): array
    {
        $voiceId = '21m00Tcm4TlvDq8ikWAM';
        
        $response = $this->client->post("https://api.elevenlabs.io/v1/text-to-speech/{$voiceId}/with-timestamps", [
            'headers' => [
                'xi-api-key' => $apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'text' => $text,
                'model_id' => $model ?: 'eleven_multilingual_v2',
                'voice_settings' => [
                    'stability' => 0.5,
                    'similarity_boost' => 0.75,
                ]
            ]
        ]);

        $data = json_decode((string)$response->getBody(), true);
        $audioBase64 = $data['audio_base64'] ?? '';
        
        if (empty($audioBase64)) {
            throw new RuntimeException("No audio returned from ElevenLabs.");
        }

        file_put_contents($outputFile, base64_decode($audioBase64));

        $alignment = $data['alignment'] ?? [];
        
        return $this->buildWordsFromCharacters(
            $alignment['characters'] ?? [],
            $alignment['character_start_times_seconds'] ?? [],
            $alignment['character_end_times_seconds'] ?? []
        );
    }

    protected function generateLmnt(string $text, string $model, string $apiKey, string $outputFile): array
    {
        $response = $this->client->post('https://api.lmnt.com/v1/ai/speech', [
            'headers' => [
                'X-API-Key' => $apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'text' => $text,
                'voice' => $model ?: 'lily',
                'format' => 'mp3',
                'return_durations' => true,
            ]
        ]);

        $data = json_decode((string)$response->getBody(), true);
        $audioBase64 = $data['audio'] ?? '';
        
        if (empty($audioBase64)) {
            throw new RuntimeException("No audio returned from LMNT.");
        }

        file_put_contents($outputFile, base64_decode($audioBase64));

        return $this->approximateWordTimestamps($text, $outputFile);
    }

    protected function generateOpenAI(string $text, string $model, string $apiKey, string $outputFile): array
    {
        $response = $this->client->post('https://api.openai.com/v1/audio/speech', [
            'headers' => [
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $model ?: 'tts-1',
                'input' => $text,
                'voice' => 'alloy',
                'response_format' => 'mp3'
            ]
        ]);

        file_put_contents($outputFile, (string)$response->getBody());

        return $this->approximateWordTimestamps($text, $outputFile);
    }

    protected function buildWordsFromCharacters(array $chars, array $starts, array $ends): array
    {
        $words = [];
        $currentWord = '';
        $currentStart = null;
        $currentEnd = null;

        for ($i = 0; $i < count($chars); $i++) {
            $char = $chars[$i];
            
            if (trim((string)$char) === '') {
                if ($currentWord !== '') {
                    $words[] = [
                        'word' => $currentWord,
                        'start' => $currentStart,
                        'end' => $currentEnd,
                    ];
                    $currentWord = '';
                    $currentStart = null;
                }
                continue;
            }

            if ($currentWord === '') {
                $currentStart = $starts[$i] ?? 0;
            }
            $currentWord .= $char;
            $currentEnd = $ends[$i] ?? 0;
        }

        if ($currentWord !== '') {
            $words[] = [
                'word' => $currentWord,
                'start' => $currentStart,
                'end' => $currentEnd,
            ];
        }

        return $words;
    }

    protected function approximateWordTimestamps(string $text, string $mp3File): array
    {
        $totalDuration = 5.0;
        
        try {
            if (file_exists($mp3File)) {
                $size = filesize($mp3File);
                $totalDuration = $size / 16000;
            }
        } catch (Throwable $e) {
            $totalDuration = 5.0;
        }

        $textWords = array_values(array_filter(preg_split('/\s+/', $text)));
        $wordCount = count($textWords);
        
        $durationPerWord = $wordCount > 0 ? $totalDuration / $wordCount : 0;

        $words = [];
        $current = 0.0;
        
        foreach ($textWords as $word) {
            $words[] = [
                'word' => $word,
                'start' => $current,
                'end' => $current + $durationPerWord,
            ];
            $current += $durationPerWord;
        }

        return $words;
    }
}
