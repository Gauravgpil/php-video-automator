<?php

namespace PhpVideoAutomator\Services;

use GuzzleHttp\Client;
use PhpVideoAutomator\Exceptions\VideoAutomatorException;

class InternetArchiveService
{
    protected Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => 'https://archive.org/'
        ]);
    }

    public function searchVideos(string $query, int $limit = 10): array
    {
        try {
            $response = $this->client->get('advancedsearch.php', [
                'query' => [
                    'q' => $query . ' AND mediatype:movies AND format:"h.264"',
                    'fl[]' => 'identifier',
                    'rows' => $limit,
                    'page' => 1,
                    'output' => 'json'
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $docs = $data['response']['docs'] ?? [];

            $results = [];
            foreach ($docs as $doc) {
                $identifier = $doc['identifier'];
                
                $metaResponse = $this->client->get("metadata/{$identifier}");
                $metaData = json_decode($metaResponse->getBody()->getContents(), true);

                $files = $metaData['files'] ?? [];
                foreach ($files as $file) {
                    if (str_ends_with(strtolower($file['name'] ?? ''), '.mp4')) {
                        $results[] = [
                            'url' => "https://archive.org/download/{$identifier}/" . $file['name']
                        ];
                        break;
                    }
                }
            }

            return $results;
        } catch (\Exception $e) {
            throw new VideoAutomatorException("Failed to fetch videos from Internet Archive: " . $e->getMessage());
        }
    }
}
