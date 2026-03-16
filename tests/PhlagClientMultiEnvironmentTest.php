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
 * Tests for multi-environment fallback functionality
 *
 * These tests verify that the PhlagClient correctly handles multiple
 * environments with proper fallback logic when flags return null.
 *
 * @package Moonspot\PhlagClient\Tests
 */
class PhlagClientMultiEnvironmentTest extends TestCase {
    /**
     * Creates a PhlagClient with a mocked HTTP client
     *
     * This helper injects a Guzzle mock handler into the client so we can
     * control responses without making real network calls.
     *
     * @param MockHandler     $mock_handler The mock handler with queued responses
     * @param string|array    $environment  Single environment or array of environments
     *
     * @return PhlagClient The configured client instance
     */
    protected function createClientWithMock(
        MockHandler $mock_handler,
        string|array $environment = 'production'
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
     * Tests constructor accepts array of environments
     */
    public function testConstructorAcceptsArrayOfEnvironments(): void {
        $client = new PhlagClient(
            'http://localhost:8000',
            'test-api-key',
            ['production', 'staging']
        );

        $this->assertSame(['production', 'staging'], $client->getEnvironment());
    }

    /**
     * Tests getEnvironment always returns array for single environment
     */
    public function testGetEnvironmentReturnsArrayForSingleEnvironment(): void {
        $client = new PhlagClient(
            'http://localhost:8000',
            'test-api-key',
            'production'
        );

        $result = $client->getEnvironment();

        $this->assertIsArray($result);
        $this->assertSame(['production'], $result);
    }

    /**
     * Tests getEnvironment returns array for multiple environments
     */
    public function testGetEnvironmentReturnsArrayForMultipleEnvironments(): void {
        $client = new PhlagClient(
            'http://localhost:8000',
            'test-api-key',
            ['production', 'staging', 'development']
        );

        $result = $client->getEnvironment();

        $this->assertIsArray($result);
        $this->assertSame(['production', 'staging', 'development'], $result);
    }

    /**
     * Tests fallback to second environment when first returns null
     */
    public function testFallbackToSecondEnvironmentWhenFirstReturnsNull(): void {
        $mock = new MockHandler([
            new Response(404), // First environment returns null (404)
            new Response(200, [], '"staging-value"'), // Second environment returns value
        ]);

        $client = $this->createClientWithMock($mock, ['production', 'staging']);
        $result = $client->getFlag('feature_test');

        $this->assertSame('staging-value', $result);
    }

    /**
     * Tests no fallback when first environment returns false
     */
    public function testNoFallbackWhenFirstReturnsFalse(): void {
        $mock = new MockHandler([
            new Response(200, [], 'false'), // First environment returns false
            // Second environment should not be called
        ]);

        $client = $this->createClientWithMock($mock, ['production', 'staging']);
        $result = $client->getFlag('feature_test');

        $this->assertFalse($result);
    }

    /**
     * Tests no fallback when first environment returns zero
     */
    public function testNoFallbackWhenFirstReturnsZero(): void {
        $mock = new MockHandler([
            new Response(200, [], '0'), // First environment returns 0
            // Second environment should not be called
        ]);

        $client = $this->createClientWithMock($mock, ['production', 'staging']);
        $result = $client->getFlag('max_items');

        $this->assertSame(0, $result);
    }

    /**
     * Tests no fallback when first environment returns empty string
     */
    public function testNoFallbackWhenFirstReturnsEmptyString(): void {
        $mock = new MockHandler([
            new Response(200, [], '""'), // First environment returns ""
            // Second environment should not be called
        ]);

        $client = $this->createClientWithMock($mock, ['production', 'staging']);
        $result = $client->getFlag('message');

        $this->assertSame('', $result);
    }

    /**
     * Tests fallback through three environments
     */
    public function testFallbackThroughThreeEnvironments(): void {
        $mock = new MockHandler([
            new Response(404), // production returns null
            new Response(404), // staging returns null
            new Response(200, [], 'true'), // development returns true
        ]);

        $client = $this->createClientWithMock(
            $mock,
            ['production', 'staging', 'development']
        );
        $result = $client->getFlag('feature_test');

        $this->assertTrue($result);
    }

    /**
     * Tests all environments return null results in null
     */
    public function testAllEnvironmentsReturnNullResultsInNull(): void {
        $mock = new MockHandler([
            new Response(404), // production returns null
            new Response(404), // staging returns null
            new Response(404), // development returns null
        ]);

        $client = $this->createClientWithMock(
            $mock,
            ['production', 'staging', 'development']
        );
        $result = $client->getFlag('nonexistent_flag');

        $this->assertNull($result);
    }

    /**
     * Tests withEnvironment accepts array
     */
    public function testWithEnvironmentAcceptsArray(): void {
        $mock = new MockHandler([]);

        $client1 = $this->createClientWithMock($mock, 'production');
        $client2 = $client1->withEnvironment(['staging', 'development']);

        $this->assertSame(['production'], $client1->getEnvironment());
        $this->assertSame(['staging', 'development'], $client2->getEnvironment());
    }

    /**
     * Tests withEnvironment accepts string
     */
    public function testWithEnvironmentAcceptsString(): void {
        $mock = new MockHandler([]);

        $client1 = $this->createClientWithMock($mock, ['production', 'staging']);
        $client2 = $client1->withEnvironment('development');

        $this->assertSame(['production', 'staging'], $client1->getEnvironment());
        $this->assertSame(['development'], $client2->getEnvironment());
    }

    /**
     * Tests cache filename differs for different environment arrays
     */
    public function testCacheFilenameDiffersForDifferentEnvironmentArrays(): void {
        $client1 = new PhlagClient(
            'http://localhost:8000',
            'test-api-key',
            ['production', 'staging'],
            true
        );

        $client2 = new PhlagClient(
            'http://localhost:8000',
            'test-api-key',
            ['staging', 'production'],
            true
        );

        $this->assertNotSame($client1->getCacheFile(), $client2->getCacheFile());
    }

    /**
     * Tests cache filename same for same environments in same order
     */
    public function testCacheFilenameSameForSameEnvironments(): void {
        $client1 = new PhlagClient(
            'http://localhost:8000',
            'test-api-key',
            ['production', 'staging'],
            true
        );

        $client2 = new PhlagClient(
            'http://localhost:8000',
            'test-api-key',
            ['production', 'staging'],
            true
        );

        $this->assertSame($client1->getCacheFile(), $client2->getCacheFile());
    }
}
