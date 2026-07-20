<?php

namespace PhpVideoAutomator\Services;

use Exception;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class AiTextService
{
    protected string $apiKey;
    protected string $provider;
    protected Client $client;

    public function __construct(string $apiKey, string $provider = 'openai')
    {
        $this->apiKey = $apiKey;
        $this->provider = $provider;
        $this->client = new Client(['timeout' => 60]);
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
                    'model' => 'gpt-3.5-turbo',
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => "Extract 1 to 2 highly visual, concrete, noun-based search terms representing the scene for a stock video search. Only output the keywords. Do not include abstract concepts, punctuation, or conversational text. Output example: 'sunny beach', 'businessman typing'."
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt
                        ]
                    ],
                    'max_tokens' => 20,
                    'temperature' => 0.3
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $content = data_get($data, 'choices.0.message.content');
            
            if (!empty($content)) {
                $keywords = trim($content);
                $keywords = str_replace(["'", '"', '.', ',', "\n"], '', $keywords);
                
                if (empty($keywords)) {
                    return $prompt;
                }
                return $keywords;
            }

            return $prompt;
        } catch (Exception $e) {
            Log::error('OpenAI Text Extraction Error: ' . $e->getMessage());
            return $prompt;
        }
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

        $optionsText = "";
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
                    'model' => 'gpt-4o-mini',
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $systemPrompt
                        ],
                        [
                            'role' => 'user',
                            'content' => $userPrompt
                        ]
                    ],
                    'max_tokens' => 10,
                    'temperature' => 0.1
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $content = data_get($data, 'choices.0.message.content');
            
            if (!empty($content)) {
                $content = trim($content);
                // Extract the first number from the output in case AI adds extra text
                if (preg_match('/\d+/', $content, $matches)) {
                    $idx = (int)$matches[0];
                    if (isset($options[$idx])) {
                        return $idx;
                    }
                }
            }
        } catch (Exception $e) {
            Log::warning('OpenAI AI Selection Error: ' . $e->getMessage());
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
                    'model' => 'gpt-4o-mini',
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $systemPrompt
                        ],
                        [
                            'role' => 'user',
                            'content' => $script
                        ]
                    ],
                    'max_tokens' => 150,
                    'temperature' => 0.3
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $content = data_get($data, 'choices.0.message.content');
            
            if (!empty($content)) {
                return trim($content);
            }
        } catch (Exception $e) {
            Log::warning('OpenAI AI Formatting Error: ' . $e->getMessage());
        }

        return $script;
    }

    public function generateVoiceoverScript(string $prompt, int $duration = 30): string
    {
        if (empty($this->apiKey) || $this->provider !== 'openai') {
            return $prompt;
        }

        try {
            $wordCount = (int)($duration * 2.5);
            $sentenceCount = max(2, (int)($duration / 6)); // roughly a sentence every 6 seconds.
            
            $systemPrompt = "You are a professional video scriptwriter. The user will provide a motion brief or description of a video. Your task is to write an engaging, highly emotional, and genuinely human voiceover script. Use conversational phrasing, a natural flow, and include dramatic pauses (represented by ellipses '...' or em-dashes '—'). Avoid sounding like an AI, a generic corporate announcer, or a news reader.\nIMPORTANT: The video is exactly {$duration} seconds long. You MUST write EXACTLY {$wordCount} words. If you write less than {$wordCount} words, there will be dead silence at the end of the video. Count your words and ensure the script is extremely close to {$wordCount} words. Split into roughly {$sentenceCount} distinct sentences. Do not include any visual directions, just the spoken text.";
            
            $response = $this->client->post('https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => 'gpt-4o-mini',
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $systemPrompt
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt
                        ]
                    ],
                    'max_tokens' => max(150, (int)($wordCount * 1.5)),
                    'temperature' => 0.7
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $content = data_get($data, 'choices.0.message.content');
            
            if (!empty($content)) {
                return trim($content);
            }
        } catch (Exception $e) {
            Log::warning('OpenAI AI Voiceover Script Error: ' . $e->getMessage());
        }

        return $prompt;
    }
}
