<?php

namespace PhpVideoAutomator\Services;

use Exception;
use GuzzleHttp\Client;

class AiTextService
{
    protected string $apiKey;
    protected string $provider;
    protected string $model;
    protected Client $client;

    public function __construct(string $apiKey, string $provider = 'openai', string $model = 'gpt-4o-mini')
    {
        $this->apiKey = $apiKey;
        $this->provider = $provider;
        $this->model = $model;
        $this->client = new Client(['timeout' => 30]);
    }

    public function extractStockVideoKeywords(string $prompt): string
    {
        if ($this->provider === 'openai') {
            return $this->extractWithOpenAi($prompt);
        }

        return $prompt;
    }

    protected function extractWithOpenAi(string $prompt): string
    {
        if (empty($this->apiKey)) {
            return $prompt;
        }

        try {
            $response = $this->client->post('https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => "Extract 1 to 2 highly visual, concrete, noun-based search terms representing the scene for a stock video search. Only output the keywords. Do not include abstract concepts, punctuation, or conversational text. Output example: 'sunny beach', 'businessman typing'.",
                        ],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'max_completion_tokens' => 20,
                    'temperature' => 0.3,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $content = $data['choices'][0]['message']['content'] ?? null;

            if (!empty($content)) {
                $keywords = trim(str_replace(["'", '"', '.', ',', "\n"], '', $content));
                return !empty($keywords) ? $keywords : $prompt;
            }
        } catch (Exception $e) {
            error_log('[PhpVideoAutomator] OpenAI keyword extraction error: ' . $e->getMessage());
        }

        return $prompt;
    }

    public function selectBestMediaIndex(string $scene, array $options): int
    {
        if (count($options) <= 1) {
            return 0;
        }

        if ($this->provider === 'openai') {
            return $this->selectBestWithOpenAi($scene, $options);
        }

        return 0;
    }

    protected function selectBestWithOpenAi(string $scene, array $options): int
    {
        if (empty($this->apiKey)) {
            return 0;
        }

        $optionsText = '';
        foreach ($options as $index => $tags) {
            $optionsText .= "Option {$index}: {$tags}\n";
        }

        $systemPrompt = "You are a smart stock footage selector. You will be given a 'Scene' and a list of 'Options' (which are tags/keywords of stock media). Your job is to select the single best option that accurately represents the Scene. Return ONLY the integer index of the winning option (e.g., '0' or '2'). Do not output any other text.";
        $userPrompt = "Scene: \"{$scene}\"\n\nOptions:\n{$optionsText}";

        try {
            $response = $this->client->post('https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                    'max_completion_tokens' => 10,
                    'temperature' => 0.1,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $content = trim((string) ($data['choices'][0]['message']['content'] ?? ''));

            if ($content !== '' && preg_match('/\d+/', $content, $matches)) {
                $idx = (int) $matches[0];
                if (isset($options[$idx])) {
                    return $idx;
                }
            }
        } catch (Exception $e) {
            error_log('[PhpVideoAutomator] OpenAI media selection error: ' . $e->getMessage());
        }

        return 0;
    }

    public function smartFormatScript(string $script, int $targetCount = 3): string
    {
        if (empty($this->apiKey) || $this->provider !== 'openai') {
            return $script;
        }

        try {
            $systemPrompt = "You are a professional video director. The user will provide a long, unpunctuated or comma-heavy video prompt. Your task is to rewrite it into EXACTLY {$targetCount} distinct, highly visual sentences separated by periods. Focus on breaking the visual elements logically into scenes. Do not add conversational filler. Output ONLY the rewritten sentences.";

            $response = $this->client->post('https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $script],
                    ],
                    'max_completion_tokens' => 150,
                    'temperature' => 0.3,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $content = $data['choices'][0]['message']['content'] ?? null;

            if (!empty($content)) {
                return trim($content);
            }
        } catch (Exception $e) {
            error_log('[PhpVideoAutomator] OpenAI script formatting error: ' . $e->getMessage());
        }

        return $script;
    }

    public function generateVoiceoverScript(string $prompt, int $duration = 30): string
    {
        if (empty($this->apiKey) || $this->provider !== 'openai') {
            return $prompt;
        }

        try {
            $wordCount = max(5, (int) ($duration * 2.0));
            $maxTokens = (int) ($wordCount * 1.35);
            $sentenceCount = max(1, (int) ($duration / 6));

            $systemPrompt = "You are a professional video scriptwriter. Write a concise, engaging voiceover script for the following video concept. The script MUST fit within exactly {$duration} seconds when read aloud at a natural pace. Write EXACTLY {$wordCount} words or fewer — never more. Split into approximately {$sentenceCount} sentences. Output only the spoken text, no stage directions or scene descriptions.";

            $response = $this->client->post('https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'max_completion_tokens' => $maxTokens,
                    'temperature' => 0.6,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $content = $data['choices'][0]['message']['content'] ?? null;

            if (!empty($content)) {
                return trim($content);
            }
        } catch (Exception $e) {
            error_log('[PhpVideoAutomator] OpenAI voiceover script error: ' . $e->getMessage());
        }

        return $prompt;
    }
}