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
}
