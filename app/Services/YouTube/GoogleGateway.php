<?php

namespace App\Services\YouTube;

use Google\Client;
use Google\Service\Exception;
use Google\Service\YouTube;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;

class GoogleGateway implements Gateway
{
    public function __construct(private ?ClientInterface $httpClient = null) {}

    public function fetch(string $method, array $parameters): array
    {
        if (! config('youtube.key')) {
            throw new ApiFailure('key_missing');
        }
        $client = new Client(['retry' => ['retries' => 0]]);
        $client->setDeveloperKey(config('youtube.key'));
        $client->setHttpClient($this->httpClient ?? new HttpClient(['timeout' => config('youtube.timeout'), 'connect_timeout' => 3]));
        $api = new YouTube($client);
        try {
            $response = match ($method) {
                'search' => $api->search->listSearch('snippet', $parameters),
                'channels' => $api->channels->listChannels('snippet,statistics,contentDetails', $parameters),
                'playlistItems' => $api->playlistItems->listPlaylistItems('contentDetails', $parameters),
                'videos' => $api->videos->listVideos('snippet,statistics,contentDetails,liveStreamingDetails', $parameters),
                default => throw new ApiFailure('unsupported_method'),
            };

            return json_decode(json_encode($response, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        } catch (Exception $e) {
            $reason = $e->getErrors()[0]['reason'] ?? '';
            if (in_array($reason, ['quotaExceeded', 'dailyLimitExceeded'], true)) {
                throw new ApiFailure('quota_exceeded');
            }
            if ($e->getCode() === 429 || $e->getCode() >= 500 || in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded'], true)) {
                throw new ApiFailure('temporary_error', true);
            }
            throw new ApiFailure(match ($e->getCode()) {
                400 => 'invalid_request', 401, 403 => 'access_denied', 404 => 'not_found', default => 'api_error',
            });
        } catch (TransferException) {
            throw new ApiFailure('network_error', true);
        }
    }
}
