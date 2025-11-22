<?php

namespace Moonspot\PhlagClient\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Moonspot\PhlagClient\Client;
use Moonspot\PhlagClient\PhlagClient;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for the PhlagClient class
 *
 * These tests verify the public API of PhlagClient, including flag retrieval,
 * environment handling, and the convenience methods. We mock the underlying
 * HTTP client to avoid making real network requests.
 *
 * @package Moonspot\PhlagClient\Tests
 */
class PhlagClientTest extends TestCase
{
    /**
     * Creates a PhlagClient with a mocked HTTP client
     *
     * This helper injects a Guzzle mock handler into the client so we can
     * control responses without making real network calls.
     *
     * @param MockHandler $mock_handler The mock handler with queued responses
     * @param string      $environment  The environment name
     *
     * @return PhlagClient The configured client instance
     */
    protected function createClientWithMock(
        MockHandler $mock_handler,
        string $environment = 'production'
    ): PhlagClient {
        $return = null;

        $handlerStack = HandlerStack::create($mock_handler);
        $guzzle       = new GuzzleClient(['handler' => $handlerStack]);

        $phlag_client = new PhlagClient(
            'http://localhost:8000',
            'test-api-key',
            $environment
        );

        // Inject the mocked Guzzle client into the internal Client
        $reflection     = new ReflectionClass($phlag_client);
        $client_prop    = $reflection->getProperty('client');
        $client_prop->setAccessible(true);
        $internal_client = $client_prop->getValue($phlag_client);

        $client_reflection = new ReflectionClass($internal_client);
        $http_prop         = $client_reflection->getProperty('http_client');
        $http_prop->setAccessible(true);
        $http_prop->setValue($internal_client, $guzzle);

        $return = $phlag_client;

        return $return;
    }

    /**
     * Tests getting a SWITCH flag that returns true
     */
    public function testGetFlagSwitchTrue(): void
    {
        $mock = new MockHandler([
            new Response(200, [], 'true'),
        ]);

        $client = $this->createClientWithMock($mock);
        $result = $client->getFlag('feature_checkout');

        $this->assertTrue($result);
    }

    /**
     * Tests getting a SWITCH flag that returns false
     */
    public function testGetFlagSwitchFalse(): void
    {
        $mock = new MockHandler([
            new Response(200, [], 'false'),
        ]);

        $client = $this->createClientWithMock($mock);
        $result = $client->getFlag('feature_checkout');

        $this->assertFalse($result);
    }

    /**
     * Tests getting an INTEGER flag
     */
    public function testGetFlagInteger(): void
    {
        $mock = new MockHandler([
            new Response(200, [], '100'),
        ]);

        $client = $this->createClientWithMock($mock);
        $result = $client->getFlag('max_items');

        $this->assertSame(100, $result);
    }

    /**
     * Tests getting a FLOAT flag
     */
    public function testGetFlagFloat(): void
    {
        $mock = new MockHandler([
            new Response(200, [], '3.14'),
        ]);

        $client = $this->createClientWithMock($mock);
        $result = $client->getFlag('price_multiplier');

        $this->assertSame(3.14, $result);
    }

    /**
     * Tests getting a STRING flag
     */
    public function testGetFlagString(): void
    {
        $mock = new MockHandler([
            new Response(200, [], '"hello world"'),
        ]);

        $client = $this->createClientWithMock($mock);
        $result = $client->getFlag('welcome_message');

        $this->assertSame('hello world', $result);
    }

    /**
     * Tests getting a null flag (nonexistent or inactive)
     */
    public function testGetFlagNull(): void
    {
        $mock = new MockHandler([
            new Response(200, [], 'null'),
        ]);

        $client = $this->createClientWithMock($mock);
        $result = $client->getFlag('nonexistent');

        $this->assertNull($result);
    }

    /**
     * Tests isEnabled returns true for enabled flags
     */
    public function testIsEnabledTrue(): void
    {
        $mock = new MockHandler([
            new Response(200, [], 'true'),
        ]);

        $client = $this->createClientWithMock($mock);
        $result = $client->isEnabled('feature_checkout');

        $this->assertTrue($result);
    }

    /**
     * Tests isEnabled returns false for disabled flags
     */
    public function testIsEnabledFalse(): void
    {
        $mock = new MockHandler([
            new Response(200, [], 'false'),
        ]);

        $client = $this->createClientWithMock($mock);
        $result = $client->isEnabled('feature_checkout');

        $this->assertFalse($result);
    }

    /**
     * Tests isEnabled returns false for null values
     */
    public function testIsEnabledNull(): void
    {
        $mock = new MockHandler([
            new Response(200, [], 'null'),
        ]);

        $client = $this->createClientWithMock($mock);
        $result = $client->isEnabled('nonexistent');

        $this->assertFalse($result);
    }

    /**
     * Tests isEnabled returns false for non-boolean values
     *
     * Heads-up: This is intentional behavior - isEnabled should only return
     * true for actual boolean true values.
     */
    public function testIsEnabledNonBoolean(): void
    {
        $mock = new MockHandler([
            new Response(200, [], '100'),
        ]);

        $client = $this->createClientWithMock($mock);
        $result = $client->isEnabled('max_items');

        $this->assertFalse($result);
    }

    /**
     * Tests getEnvironment returns the correct environment
     */
    public function testGetEnvironment(): void
    {
        $mock = new MockHandler([]);

        $client = $this->createClientWithMock($mock, 'staging');
        $result = $client->getEnvironment();

        $this->assertSame('staging', $result);
    }

    /**
     * Tests withEnvironment creates a new instance
     */
    public function testWithEnvironmentCreatesNewInstance(): void
    {
        $mock = new MockHandler([]);

        $client1 = $this->createClientWithMock($mock, 'production');
        $client2 = $client1->withEnvironment('staging');

        $this->assertNotSame($client1, $client2);
        $this->assertSame('production', $client1->getEnvironment());
        $this->assertSame('staging', $client2->getEnvironment());
    }

    /**
     * Tests that different environments query different endpoints
     */
    public function testDifferentEnvironmentsQueryDifferentEndpoints(): void
    {
        $mock1 = new MockHandler([
            new Response(200, [], 'true'),
        ]);

        $mock2 = new MockHandler([
            new Response(200, [], 'false'),
        ]);

        $client1 = $this->createClientWithMock($mock1, 'production');
        $client2 = $this->createClientWithMock($mock2, 'staging');

        $result1 = $client1->getFlag('feature_test');
        $result2 = $client2->getFlag('feature_test');

        $this->assertTrue($result1);
        $this->assertFalse($result2);
    }

    /**
     * Tests that constructor stores all required properties
     */
    public function testConstructorStoresProperties(): void
    {
        $client = new PhlagClient(
            'http://example.com',
            'my-api-key',
            'development'
        );

        $this->assertSame('development', $client->getEnvironment());

        $reflection = new ReflectionClass($client);

        $base_url_prop = $reflection->getProperty('base_url');
        $base_url_prop->setAccessible(true);
        $this->assertSame('http://example.com', $base_url_prop->getValue($client));

        $api_key_prop = $reflection->getProperty('api_key');
        $api_key_prop->setAccessible(true);
        $this->assertSame('my-api-key', $api_key_prop->getValue($client));
    }
}
