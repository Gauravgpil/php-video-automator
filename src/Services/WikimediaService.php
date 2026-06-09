<?php

namespace PhpVideoAutomator\Services;

use Exception;
use GuzzleHttp\Client;
use PhpVideoAutomator\Exceptions\VideoAutomatorException;

class WikimediaService
{
    protected Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => 'https://commons.wikimedia.org/w/api.php',
            'headers' => [
                'User-Agent' => 'PhpVideoAutomator/1.0'
            ],
            'timeout' => 30,
        ]);
    }

    public function searchVideos(string $query, int $limit = 10): array
    {
        try {
            $response = $this->client->get('', [
                'query' => [
                    'action' => 'query',
                    'list' => 'search',
                    'srsearch' => $query . ' filetype:video',
                    'srlimit' => $limit,
                    'utf8' => '1',
                    'format' => 'json'
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $search = $data['query']['search'] ?? [];

            if (empty($search)) {
                return [];
            }

            $titles = array_column($search, 'title');
            $titlesStr = implode('|', $titles);

            $infoResponse = $this->client->get('', [
                'query' => [
                    'action' => 'query',
                    'titles' => $titlesStr,
                    'prop' => 'videoinfo',
                    'viprop' => 'url',
                    'format' => 'json'
                ]
            ]);

            $infoData = json_decode($infoResponse->getBody()->getContents(), true);
            $pages = $infoData['query']['pages'] ?? [];

            $results = [];
            foreach ($pages as $page) {
                if (isset($page['videoinfo'][0]['url'])) {
                    $results[] = ['url' => $page['videoinfo'][0]['url']];
                }
            }

            return $results;
        } catch (\Exception $e) {
            throw new VideoAutomatorException("Failed to fetch videos from Wikimedia Commons: " . $e->getMessage());
        }
    }

    public function searchImages(string $query, int $limit = 10): array
    {
        try {
            $response = $this->client->get('', [
                'query' => [
                    'action' => 'query',
                    'list' => 'search',
                    'srsearch' => $query . ' filetype:bitmap',
                    'srlimit' => $limit,
                    'utf8' => '1',
                    'format' => 'json'
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $search = $data['query']['search'] ?? [];

            if (empty($search)) {
                return [];
            }

            $titles = array_column($search, 'title');
            $titlesStr = implode('|', $titles);

            $infoResponse = $this->client->get('', [
                'query' => [
                    'action' => 'query',
                    'titles' => $titlesStr,
                    'prop' => 'imageinfo',
                    'iiprop' => 'url',
                    'format' => 'json'
                ]
            ]);

            $infoData = json_decode($infoResponse->getBody()->getContents(), true);
            $pages = $infoData['query']['pages'] ?? [];

            $results = [];
            foreach ($pages as $page) {
                if (isset($page['imageinfo'][0]['url'])) {
                    $results[] = ['url' => $page['imageinfo'][0]['url']];
                }
            }

            return $results;
        } catch (Exception $e) {
            throw new VideoAutomatorException("Failed to fetch images from Wikimedia Commons: " . $e->getMessage());
        }
    }
}
