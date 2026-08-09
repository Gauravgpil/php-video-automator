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
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => 'gpt-4o-mini',
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => "Extract 1 to 2 highly visual, concrete, noun-based search terms representing the scene for a stock video search. Only output the keywords. Do not include abstract concepts, punctuation, or conversational text. Output example: 'sunny beach', 'businessman typing'.",
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt,
                        ],
                    ],
                    'max_tokens' => 20,
                    'temperature' => 0.3,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $content = $data['choices'][0]['message']['content'] ?? null;

            if (! empty($content)) {
                $keywords = trim($content);
                $keywords = str_replace(["'", '"', '.', ',', "\n"], '', $keywords);

                if (empty($keywords)) {
                    return $prompt;
                }

                return $keywords;
            }

            if (! empty($content)) {
                $keywords = trim(str_replace(["'", '"', '.', ',', "\n"], '', $content));

                return ! empty($keywords) ? $keywords : $prompt;
            }
        } catch (Exception $e) {
            error_log('OpenAI Text Extraction Error: '.$e->getMessage());

            return $prompt;
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
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $systemPrompt,
                        ],
                        [
                            'role' => 'user',
                            'content' => $userPrompt,
                        ],
                    ],
                    'max_tokens' => 10,
                    'temperature' => 0.1,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $content = $data['choices'][0]['message']['content'] ?? null;

            if (! empty($content)) {
                $content = trim($content);
                if (preg_match('/\d+/', $content, $matches)) {
                    $idx = (int) $matches[0];
                    if (isset($options[$idx])) {
                        return $idx;
                    }
                }
            }
        } catch (Exception $e) {
            error_log('OpenAI AI Selection Error: '.$e->getMessage());
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
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $systemPrompt,
                        ],
                        [
                            'role' => 'user',
                            'content' => $script,
                        ],
                    ],
                    'max_tokens' => 150,
                    'temperature' => 0.3,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $content = $data['choices'][0]['message']['content'] ?? null;

            if (! empty($content)) {
                return trim($content);
            }
        } catch (Exception $e) {
            error_log('OpenAI AI Formatting Error: '.$e->getMessage());
        }

        return $script;
    }

    public function generateVoiceoverScript(string $prompt, int $duration = 30): string
    {
        if (empty($this->apiKey) || $this->provider !== 'openai') {
            return $prompt;
        }

        try {
            $wordCount = max(10, (int) round($duration * 2.3));
            $maxTokens = max(200, (int) ($wordCount * 1.8));
            $sentenceCount = max(2, (int) ceil($duration / 5));

            $systemPrompt = <<<PROMPT
You are an award-winning video scriptwriter specializing in cinematic, emotionally resonant voiceovers.

Your task is to write a professional voiceover script for a {$duration}-second video based on the concept provided by the user.

RULES (follow all of them strictly):
1. WORD COUNT: Write EXACTLY {$wordCount} words. Count carefully. Not fewer. Not more.
2. DURATION FIT: The script must fill the full {$duration} seconds when spoken aloud at a natural, unhurried pace (~2.3 words/second).
3. SENTENCE STRUCTURE: Break the script into approximately {$sentenceCount} distinct, punchy sentences. Use short, powerful clauses for impact.
4. TONE: Write like a human. Use conversational rhythm, vivid imagery, and emotional beats. Never sound robotic or corporate.
5. NATURAL PAUSES: Use ellipses "..." or em-dashes "—" to indicate dramatic pauses. These help the TTS engine breathe naturally.
6. NO STAGE DIRECTIONS: Output ONLY the spoken words. No scene descriptions, timestamps, or speaker labels.
7. COMPLETENESS: The script must end at a complete sentence or clause. Never end mid-sentence or mid-thought.
8. SPECIFICITY: Reference the visual concept clearly. The audience should be able to picture the scene as they listen.
PROMPT;

            $response = $this->client->post('https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $systemPrompt,
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt,
                        ],
                    ],
                    'max_tokens' => $maxTokens,
                    'temperature' => 0.65,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $content = $data['choices'][0]['message']['content'] ?? null;

            if (! empty($content)) {
                return trim($content);
            }
        } catch (Exception $e) {
            error_log('OpenAI AI Voiceover Script Error: '.$e->getMessage());
        }

        return $prompt;
    }
}
