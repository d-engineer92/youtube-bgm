<?php

namespace Tests\Unit;

use App\Services\YouTube\ApiFailure;
use App\Services\YouTube\GoogleGateway;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoogleGatewayTest extends TestCase
{
    public static function endpoints(): array
    {
        return [['search', ['q' => 'rain', 'type' => 'video'], 'search'], ['channels', ['id' => 'UCDEMO000000000000000001'], 'channels'], ['playlistItems', ['playlistId' => 'UUDEMO000000000000000001'], 'playlistItems'], ['videos', ['id' => 'demoVideo01'], 'videos']];
    }

    #[DataProvider('endpoints')]
    public function test_official_client_read_methods_with_fake_http(string $method, array $params, string $path): void
    {
        config(['youtube.key' => 'test-placeholder-not-a-real-key']);
        $mock = new MockHandler([new Response(200, ['Content-Type' => 'application/json'], json_encode(['items' => [], 'nextPageToken' => 'next']))]);
        $gateway = new GoogleGateway(new Client(['handler' => HandlerStack::create($mock)]));
        $result = $gateway->fetch($method, $params);
        $this->assertSame([], $result['items']);
        $this->assertSame('/youtube/v3/'.$path, $mock->getLastRequest()->getUri()->getPath());
        $this->assertSame('GET', $mock->getLastRequest()->getMethod());
    }

    public function test_google_error_is_sanitized_and_internal_retry_is_disabled(): void
    {
        config(['youtube.key' => 'secret-must-not-appear']);
        $body = json_encode(['error' => ['code' => 503, 'message' => 'secret-must-not-appear', 'errors' => [['reason' => 'backendError']]]]);
        $mock = new MockHandler([new Response(503, [], $body)]);
        $gateway = new GoogleGateway(new Client(['handler' => HandlerStack::create($mock)]));
        try {
            $gateway->fetch('videos', ['id' => 'demoVideo01']);
            $this->fail('Expected failure');
        } catch (ApiFailure $e) {
            $this->assertTrue($e->retryable);
            $this->assertSame('temporary_error', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
        $this->assertSame(0, $mock->count());
    }

    public function test_quota_is_not_retryable(): void
    {
        config(['youtube.key' => 'test-placeholder']);
        $mock = new MockHandler([new Response(403, [], json_encode(['error' => ['code' => 403, 'message' => 'quota', 'errors' => [['reason' => 'quotaExceeded']]]]))]);
        try {
            (new GoogleGateway(new Client(['handler' => HandlerStack::create($mock)])))->fetch('videos', ['id' => 'demoVideo01']);
            $this->fail('Expected failure');
        } catch (ApiFailure $e) {
            $this->assertSame('quota_exceeded', $e->reason);
            $this->assertFalse($e->retryable);
        }
    }
}
