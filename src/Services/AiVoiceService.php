<?php

namespace PhpVideoAutomator\Services;

use GuzzleHttp\Client;
use RuntimeException;
use InvalidArgumentException;
use Throwable;

class AiVoiceService
{
    protected Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'timeout'         => 120.0,
            'connect_timeout' => 15.0,
            'http_errors'     => true,
        ]);
    }

    public function generateVoiceoverWithTimestamps(string $text, string $provider, string $model, string $apiKey, string $outputFile, float $speed = 1.0): array
    {
        return match ($provider) {
            'eleven' => $this->generateElevenLabs($text, $model, $apiKey, $outputFile, $speed),
            'lmnt'   => $this->generateLmnt($text, $model, $apiKey, $outputFile, $speed),
            'openai' => $this->generateOpenAI($text, $model, $apiKey, $outputFile, $speed),
            default  => throw new InvalidArgumentException("Unsupported voice provider: {$provider}"),
        };
    }

    protected function generateElevenLabs(string $text, string $model, string $apiKey, string $outputFile, float $speed = 1.0): array
    {
        $elevenSpeed = max(0.7, min(1.2, $speed > 0 ? $speed : 1.0));
        $voiceId  = !empty($model) ? $model : '21m00Tcm4TlvDq8ikWAM';
        $response = $this->client->post("https://api.elevenlabs.io/v1/text-to-speech/{$voiceId}/with-timestamps", [
            'headers' => [
                'xi-api-key'   => $apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'text'           => $text,
                'model_id'       => 'eleven_multilingual_v2',
                'voice_settings' => [
                    'stability'        => 0.5,
                    'similarity_boost' => 0.75,
                    'speed'            => $elevenSpeed,
                ],
            ],
        ]);

        $data = json_decode((string) $response->getBody(), true);

        if (empty($data['audio_base64'])) {
            throw new RuntimeException('No audio returned from ElevenLabs.');
        }

        file_put_contents($outputFile, base64_decode($data['audio_base64']), LOCK_EX);
        $alignment = $data['alignment'] ?? [];
        unset($data);

        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }

        return $this->buildWordsFromCharacters(
            $alignment['characters']                    ?? [],
            $alignment['character_start_times_seconds'] ?? [],
            $alignment['character_end_times_seconds']   ?? []
        );
    }

    protected function generateLmnt(string $text, string $model, string $apiKey, string $outputFile, float $speed = 1.0): array
    {
        $lmntSpeed = max(0.5, min(2.0, $speed > 0 ? $speed : 1.0));
        $voice = !empty($model) ? $model : 'leah';

        try {
            $response = $this->client->post('https://api.lmnt.com/v1/ai/speech', [
                'headers' => [
                    'X-API-Key'    => $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'text'              => $text,
                    'voice'             => $voice,
                    'format'            => 'mp3',
                    'return_durations'  => true,
                    'return_timestamps' => true,
                    'speed'             => $lmntSpeed,
                ],
            ]);
        } catch (Throwable $e) {
            if ($voice !== 'leah') {
                $response = $this->client->post('https://api.lmnt.com/v1/ai/speech', [
                    'headers' => [
                        'X-API-Key'    => $apiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'text'              => $text,
                        'voice'             => 'leah',
                        'format'            => 'mp3',
                        'return_durations'  => true,
                        'return_timestamps' => true,
                        'speed'             => $lmntSpeed,
                    ],
                ]);
            } else {
                throw $e;
            }
        }

        $data = json_decode((string) $response->getBody(), true);

        if (empty($data['audio'])) {
            throw new RuntimeException('No audio returned from LMNT.');
        }

        file_put_contents($outputFile, base64_decode($data['audio']), LOCK_EX);
        $durations = $data['durations'] ?? $data['timestamps'] ?? [];
        unset($data);

        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }

        if (!empty($durations)) {
            $words  = [];
            $cursor = 0.0;

            foreach ($durations as $entry) {
                $wordText = trim((string) ($entry['text'] ?? $entry['word'] ?? ''));

                if ($wordText === '') {
                    continue;
                }

                $start   = isset($entry['start']) ? (float) $entry['start'] : $cursor;
                $dur     = (float) ($entry['duration'] ?? 0.3);
                $end     = $start + $dur;
                $words[] = [
                    'word'  => $wordText,
                    'start' => round($start, 4),
                    'end'   => round($end, 4),
                ];
                $cursor  = $end;
            }

            if (!empty($words)) {
                return $words;
            }
        }

        return $this->approximateWordTimestamps($text, $outputFile);
    }

    protected function generateOpenAI(string $text, string $model, string $apiKey, string $outputFile, float $speed = 1.0): array
    {
        $openAiSpeed = max(0.25, min(4.0, $speed > 0 ? $speed : 1.0));
        $validVoices = ['alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer', 'ash', 'ballad', 'coral', 'sage', 'verse'];
        $voice = in_array(strtolower($model), $validVoices) ? strtolower($model) : 'alloy';

        $this->client->post('https://api.openai.com/v1/audio/speech', [
            'sink'    => $outputFile,
            'headers' => [
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type'  => 'application/json',
            ],
            'json' => [
                'model'           => 'tts-1',
                'input'           => $text,
                'voice'           => $voice,
                'response_format' => 'mp3',
                'speed'           => $openAiSpeed,
            ],
        ]);

        return $this->approximateWordTimestamps($text, $outputFile);
    }

    protected function buildWordsFromCharacters(array $chars, array $starts, array $ends): array
    {
        $words        = [];
        $currentWord  = '';
        $currentStart = null;
        $currentEnd   = null;

        for ($i = 0; $i < count($chars); $i++) {
            $char = $chars[$i];

            if (trim((string) $char) === '') {
                if ($currentWord !== '') {
                    $words[]      = ['word' => $currentWord, 'start' => $currentStart, 'end' => $currentEnd];
                    $currentWord  = '';
                    $currentStart = null;
                }
                continue;
            }

            if ($currentWord === '') {
                $currentStart = $starts[$i] ?? 0;
            }

            $currentWord .= $char;
            $currentEnd   = $ends[$i] ?? 0;
        }

        if ($currentWord !== '') {
            $words[] = ['word' => $currentWord, 'start' => $currentStart, 'end' => $currentEnd];
        }

        return $words;
    }

    protected function approximateWordTimestamps(string $text, string $mp3File): array
    {
        $totalDuration = 5.0;

        try {
            if (file_exists($mp3File)) {
                $cmd    = sprintf(
                    'ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s 2>/dev/null',
                    escapeshellarg($mp3File)
                );
                $output = trim((string) shell_exec($cmd));
                $totalDuration = (is_numeric($output) && (float) $output > 0)
                    ? (float) $output
                    : filesize($mp3File) / 16000;
            }
        } catch (Throwable) {
            $totalDuration = 5.0;
        }

        $textWords       = array_values(array_filter(preg_split('/\s+/', trim($text))));
        $wordCount       = count($textWords);
        $durationPerWord = $wordCount > 0 ? $totalDuration / $wordCount : 0.43;
        $words           = [];
        $current         = 0.0;

        foreach ($textWords as $word) {
            $words[] = [
                'word'  => $word,
                'start' => round($current, 4),
                'end'   => round($current + $durationPerWord, 4),
            ];
            $current += $durationPerWord;
        }

        return $words;
    }
}
