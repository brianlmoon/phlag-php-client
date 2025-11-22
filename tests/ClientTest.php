<?php

namespace Moonspot\PhlagClient\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Moonspot\PhlagClient\Client;
use Moonspot\PhlagClient\Exception\AuthenticationException;
use Moonspot\PhlagClient\Exception\InvalidEnvironmentException;
use Moonspot\PhlagClient\Exception\InvalidFlagException;
use Moonspot\PhlagClient\Exception\NetworkException;
use Moonspot\PhlagClient\Exception\PhlagException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for the Client HTTP wrapper class
 *
 * These tests verify that the Client class correctly handles HTTP requests,
 * authentication, error conditions, and response parsing. We use Guzzle's
 * MockHandler to simulate various server responses.
 *
 * @package Moonspot\PhlagClient\Tests
 */
class ClientTest extends TestCase {
    /**
     * Creates a Client instance with a mocked HTTP client
     *
     * This helper lets us inject a mock Guzzle handler so we can control
     * the HTTP responses without making real network requests.
     *
     * @param MockHandler $mock_handler The mock handler with queued responses
     *
     * @return Client The configured client instance
     */
    protected function createClientWithMock(MockHandler $mock_handler): Client {
        $return = null;

        $handlerStack = HandlerStack::create($mock_handler);
        $guzzle       = new GuzzleClient(['handler' => $handlerStack]);

        $client = new Client('http://localhost:8000', 'test-api-key');

        // Use reflection to inject our mocked Guzzle client
        $reflection = new ReflectionClass($client);
        $property   = $reflection->getProperty('http_client');
        $property->setAccessible(true);
        $property->setValue($client, $guzzle);

        $return = $client;

        return $return;
    }

    /**
     * Tests successful scalar response from the API
     *
     * The /flag endpoint returns scalar values (true, false, numbers, strings)
     * which need to be decoded differently than array responses.
     */
    public function testGetScalarResponse(): void {
        $mock = new MockHandler([
            new Response(200, [], 'true'),
        ]);

        $client = $this->createClientWithMock($mock);
        $result = $client->get('/flag/production/feature_test');

        $this->assertTrue($result);
    }

    /**
     * Tests successful array response from the API
     *
     * The /all-flags and /get-flags endpoints return JSON arrays/objects.
     */
    public function testGetArrayResponse(): void {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['flag1' => true, 'flag2' => false])),
        ]);

        $client = $this->createClientWithMock($mock);
        $result = $client->get('/all-flags/production');

        $this->assertIsArray($result);
        $this->assertTrue($result['flag1']);
        $this->assertFalse($result['flag2']);
    }

    /**
     * Tests null response from the API
     *
     * Flags that don't exist or are inactive return null.
     */
    public function testGetNullResponse(): void {
        $mock = new MockHandler([
            new Response(200, [], 'null'),
        ]);

        $client = $this->createClientWithMock($mock);
        $result = $client->get('/flag/production/nonexistent');

        $this->assertNull($result);
    }

    /**
     * Tests integer response from the API
     */
    public function testGetIntegerResponse(): void {
        $mock = new MockHandler([
            new Response(200, [], '100'),
        ]);

        $client = $this->createClientWithMock($mock);
        $result = $client->get('/flag/production/max_items');

        $this->assertSame(100, $result);
    }

    /**
     * Tests float response from the API
     */
    public function testGetFloatResponse(): void {
        $mock = new MockHandler([
            new Response(200, [], '3.14'),
        ]);

        $client = $this->createClientWithMock($mock);
        $result = $client->get('/flag/production/price_multiplier');

        $this->assertSame(3.14, $result);
    }

    /**
     * Tests string response from the API
     */
    public function testGetStringResponse(): void {
        $mock = new MockHandler([
            new Response(200, [], '"hello world"'),
        ]);

        $client = $this->createClientWithMock($mock);
        $result = $client->get('/flag/production/welcome_message');

        $this->assertSame('hello world', $result);
    }

    /**
     * Tests that 401 responses throw AuthenticationException
     */
    public function testAuthenticationError(): void {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid API key');

        $mock = new MockHandler([
            new ClientException(
                'Unauthorized',
                new Request('GET', '/flag/production/test'),
                new Response(401, [], 'Unauthorized')
            ),
        ]);

        $client = $this->createClientWithMock($mock);
        $client->get('/flag/production/test');
    }

    /**
     * Tests that 404 on flag endpoint throws InvalidFlagException
     */
    public function testInvalidFlagError(): void {
        $this->expectException(InvalidFlagException::class);
        $this->expectExceptionMessage('Flag not found');

        $mock = new MockHandler([
            new ClientException(
                'Not Found',
                new Request('GET', '/flag/production/nonexistent'),
                new Response(404, [], 'Not Found')
            ),
        ]);

        $client = $this->createClientWithMock($mock);
        $client->get('/flag/production/nonexistent');
    }

    /**
     * Tests that 404 on other endpoints throws InvalidEnvironmentException
     */
    public function testInvalidEnvironmentError(): void {
        $this->expectException(InvalidEnvironmentException::class);
        $this->expectExceptionMessage('Environment not found');

        $mock = new MockHandler([
            new ClientException(
                'Not Found',
                new Request('GET', '/all-flags/nonexistent'),
                new Response(404, [], 'Not Found')
            ),
        ]);

        $client = $this->createClientWithMock($mock);
        $client->get('/all-flags/nonexistent');
    }

    /**
     * Tests that connection errors throw NetworkException
     */
    public function testNetworkError(): void {
        $this->expectException(NetworkException::class);
        $this->expectExceptionMessage('Network error');

        $mock = new MockHandler([
            new ConnectException(
                'Connection refused',
                new Request('GET', '/flag/production/test')
            ),
        ]);

        $client = $this->createClientWithMock($mock);
        $client->get('/flag/production/test');
    }

    /**
     * Tests that other HTTP errors throw PhlagException
     */
    public function testGenericHttpError(): void {
        $this->expectException(PhlagException::class);
        $this->expectExceptionMessage('HTTP error');

        $mock = new MockHandler([
            new ClientException(
                'Internal Server Error',
                new Request('GET', '/flag/production/test'),
                new Response(500, [], 'Internal Server Error')
            ),
        ]);

        $client = $this->createClientWithMock($mock);
        $client->get('/flag/production/test');
    }

    /**
     * Tests that the client sends correct authorization header
     */
    public function testAuthorizationHeader(): void {
        $container = [];
        $history   = Middleware::history($container);

        $mock = new MockHandler([
            new Response(200, [], 'true'),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push($history);

        $guzzle = new GuzzleClient([
            'handler' => $handlerStack,
            'headers' => [
                'Authorization' => 'Bearer my-test-key',
                'Accept'        => 'application/json',
            ],
        ]);

        $client = new Client('http://localhost:8000', 'my-test-key');

        $reflection = new ReflectionClass($client);
        $property   = $reflection->getProperty('http_client');
        $property->setAccessible(true);
        $property->setValue($client, $guzzle);

        $client->get('/flag/production/test');

        $this->assertCount(1, $container);
        $request = $container[0]['request'];
        $this->assertTrue($request->hasHeader('Authorization'));
        $this->assertSame(['Bearer my-test-key'], $request->getHeader('Authorization'));
    }
}
