<?php

namespace Moonspot\PhlagClient\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Moonspot\PhlagClient\Client;
use Moonspot\PhlagClient\PhlagClient;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for PhlagClient caching functionality
 *
 * These tests verify that the file-based caching system works correctly,
 * including cache loading, expiration, management methods, and edge cases.
 *
 * @package Moonspot\PhlagClient\Tests
 */
class PhlagClientCacheTest extends TestCase {
    /**
     * Creates a PhlagClient with a mocked HTTP client
     *
     * @param MockHandler $mock_handler The mock handler with queued responses
     * @param bool        $cache        Enable caching
     * @param string|null $cache_file   Custom cache file path
     * @param int         $cache_ttl    Cache TTL in seconds
     *
     * @return PhlagClient The configured client instance
     */
    protected function createClientWithMock(
        MockHandler $mock_handler,
        bool $cache = false,
        ?string $cache_file = null,
        int $cache_ttl = 300
    ): PhlagClient {
        $return = null;

        $handlerStack = HandlerStack::create($mock_handler);
        $guzzle       = new GuzzleClient([
            'base_uri' => 'http://localhost:8000/',
            'handler'  => $handlerStack,
            'headers'  => [
                'Authorization' => 'Bearer test-key',
                'Accept'        => 'application/json',
            ],
        ]);

        $phlag_client = new PhlagClient(
            'http://localhost:8000',
            'test-key',
            'production',
            $cache,
            $cache_file,
            $cache_ttl
        );

        // Inject the mocked Guzzle client
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
     * Tests that cache is disabled by default
     */
    public function testCacheDisabledByDefault(): void {
        $mock = new MockHandler([]);

        $client = $this->createClientWithMock($mock);

        $this->assertFalse($client->isCacheEnabled());
    }

    /**
     * Tests that cache disabled makes direct API calls
     */
    public function testCacheDisabledMakesDirectApiCalls(): void {
        $container = [];
        $history   = Middleware::history($container);

        $mock = new MockHandler([
            new Response(200, [], 'true'),
            new Response(200, [], 'false'),
            new Response(200, [], '100'),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push($history);

        $guzzle = new GuzzleClient([
            'base_uri' => 'http://localhost:8000/',
            'handler'  => $handlerStack,
            'headers'  => [
                'Authorization' => 'Bearer test-key',
                'Accept'        => 'application/json',
            ],
        ]);

        $client = new PhlagClient('http://localhost:8000', 'test-key', 'production', false);

        $reflection     = new ReflectionClass($client);
        $client_prop    = $reflection->getProperty('client');
        $client_prop->setAccessible(true);
        $internal_client = $client_prop->getValue($client);

        $client_reflection = new ReflectionClass($internal_client);
        $http_prop         = $client_reflection->getProperty('http_client');
        $http_prop->setAccessible(true);
        $http_prop->setValue($internal_client, $guzzle);

        // Make three flag requests
        $client->getFlag('flag1');
        $client->getFlag('flag2');
        $client->getFlag('flag3');

        // Should have made 3 API calls
        $this->assertCount(3, $container);
        $this->assertStringContainsString('flag/production/flag1', (string) $container[0]['request']->getUri());
        $this->assertStringContainsString('flag/production/flag2', (string) $container[1]['request']->getUri());
        $this->assertStringContainsString('flag/production/flag3', (string) $container[2]['request']->getUri());
    }

    /**
     * Tests that cache enabled loads flags once
     */
    public function testCacheEnabledLoadsFlagsOnce(): void {
        $container = [];
        $history   = Middleware::history($container);

        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'flag1' => true,
                'flag2' => false,
                'flag3' => 100,
            ])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push($history);

        $guzzle = new GuzzleClient([
            'base_uri' => 'http://localhost:8000/',
            'handler'  => $handlerStack,
            'headers'  => [
                'Authorization' => 'Bearer test-key',
                'Accept'        => 'application/json',
            ],
        ]);

        $temp_file = sys_get_temp_dir() . '/phlag_test_' . uniqid() . '.json';

        $client = new PhlagClient(
            'http://localhost:8000',
            'test-key',
            'production',
            true,
            $temp_file
        );

        $reflection     = new ReflectionClass($client);
        $client_prop    = $reflection->getProperty('client');
        $client_prop->setAccessible(true);
        $internal_client = $client_prop->getValue($client);

        $client_reflection = new ReflectionClass($internal_client);
        $http_prop         = $client_reflection->getProperty('http_client');
        $http_prop->setAccessible(true);
        $http_prop->setValue($internal_client, $guzzle);

        // Make three flag requests
        $result1 = $client->getFlag('flag1');
        $result2 = $client->getFlag('flag2');
        $result3 = $client->getFlag('flag3');

        // Should have made only 1 API call to all-flags endpoint
        $this->assertCount(1, $container);
        $this->assertStringContainsString('all-flags/production', (string) $container[0]['request']->getUri());

        // Verify results
        $this->assertTrue($result1);
        $this->assertFalse($result2);
        $this->assertSame(100, $result3);

        // Clean up
        @unlink($temp_file);
    }

    /**
     * Tests that cache file is created
     */
    public function testCacheFileCreated(): void {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['flag1' => true])),
        ]);

        $temp_file = sys_get_temp_dir() . '/phlag_test_' . uniqid() . '.json';

        $client = $this->createClientWithMock($mock, true, $temp_file);

        // Trigger cache load
        $client->getFlag('flag1');

        // Cache file should exist
        $this->assertFileExists($temp_file);

        // Clean up
        @unlink($temp_file);
    }

    /**
     * Tests that cache file is auto-named correctly
     */
    public function testCacheFileAutoNamed(): void {
        $mock = new MockHandler([]);

        $client = $this->createClientWithMock($mock, true);

        $cache_file = $client->getCacheFile();

        // Should contain phlag_cache_ prefix
        $this->assertStringContainsString('phlag_cache_', $cache_file);

        // Should be in temp directory
        $this->assertStringStartsWith(sys_get_temp_dir(), $cache_file);

        // Should end with .json
        $this->assertStringEndsWith('.json', $cache_file);
    }

    /**
     * Tests that custom cache file path works
     */
    public function testCacheFileCustomPath(): void {
        $mock = new MockHandler([]);

        $custom_path = sys_get_temp_dir() . '/my_custom_cache.json';
        $client      = $this->createClientWithMock($mock, true, $custom_path);

        $this->assertSame($custom_path, $client->getCacheFile());
    }

    /**
     * Tests that cache file is used on subsequent loads
     */
    public function testCacheFileUsedOnReload(): void {
        $container = [];
        $history   = Middleware::history($container);

        $mock = new MockHandler([
            new Response(200, [], json_encode(['flag1' => true])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push($history);

        $guzzle = new GuzzleClient([
            'base_uri' => 'http://localhost:8000/',
            'handler'  => $handlerStack,
            'headers'  => [
                'Authorization' => 'Bearer test-key',
                'Accept'        => 'application/json',
            ],
        ]);

        $temp_file = sys_get_temp_dir() . '/phlag_test_' . uniqid() . '.json';

        // First client - loads from API
        $client1 = new PhlagClient(
            'http://localhost:8000',
            'test-key',
            'production',
            true,
            $temp_file
        );

        $reflection     = new ReflectionClass($client1);
        $client_prop    = $reflection->getProperty('client');
        $client_prop->setAccessible(true);
        $internal_client = $client_prop->getValue($client1);

        $client_reflection = new ReflectionClass($internal_client);
        $http_prop         = $client_reflection->getProperty('http_client');
        $http_prop->setAccessible(true);
        $http_prop->setValue($internal_client, $guzzle);

        $client1->getFlag('flag1');

        // Should have made 1 API call
        $this->assertCount(1, $container);

        // Second client - should use cache file (no new mock responses needed)
        $client2 = new PhlagClient(
            'http://localhost:8000',
            'test-key',
            'production',
            true,
            $temp_file
        );

        $result = $client2->getFlag('flag1');

        // Should still be only 1 API call (from first client)
        $this->assertCount(1, $container);
        $this->assertTrue($result);

        // Clean up
        @unlink($temp_file);
    }

    /**
     * Tests that missing flag in cache returns null
     */
    public function testMissingFlagInCacheReturnsNull(): void {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['flag1' => true])),
        ]);

        $client = $this->createClientWithMock($mock, true);

        // Request a flag that's not in the cache
        $result = $client->getFlag('nonexistent');

        $this->assertNull($result);
    }

    /**
     * Tests clearCache removes file
     */
    public function testClearCacheRemovesFile(): void {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['flag1' => true])),
        ]);

        $temp_file = sys_get_temp_dir() . '/phlag_test_' . uniqid() . '.json';

        $client = $this->createClientWithMock($mock, true, $temp_file);

        // Load cache
        $client->getFlag('flag1');

        $this->assertFileExists($temp_file);

        // Clear cache
        $client->clearCache();

        $this->assertFileDoesNotExist($temp_file);
    }

    /**
     * Tests clearCache forces reload on next request
     */
    public function testClearCacheForcesReload(): void {
        $container = [];
        $history   = Middleware::history($container);

        $mock = new MockHandler([
            new Response(200, [], json_encode(['flag1' => true])),
            new Response(200, [], json_encode(['flag1' => false])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push($history);

        $guzzle = new GuzzleClient([
            'base_uri' => 'http://localhost:8000/',
            'handler'  => $handlerStack,
            'headers'  => [
                'Authorization' => 'Bearer test-key',
                'Accept'        => 'application/json',
            ],
        ]);

        $temp_file = sys_get_temp_dir() . '/phlag_test_' . uniqid() . '.json';

        $client = new PhlagClient(
            'http://localhost:8000',
            'test-key',
            'production',
            true,
            $temp_file
        );

        $reflection     = new ReflectionClass($client);
        $client_prop    = $reflection->getProperty('client');
        $client_prop->setAccessible(true);
        $internal_client = $client_prop->getValue($client);

        $client_reflection = new ReflectionClass($internal_client);
        $http_prop         = $client_reflection->getProperty('http_client');
        $http_prop->setAccessible(true);
        $http_prop->setValue($internal_client, $guzzle);

        // First request
        $result1 = $client->getFlag('flag1');
        $this->assertTrue($result1);
        $this->assertCount(1, $container);

        // Clear cache
        $client->clearCache();

        // Second request should reload from API
        $result2 = $client->getFlag('flag1');
        $this->assertFalse($result2);
        $this->assertCount(2, $container);

        // Clean up
        @unlink($temp_file);
    }

    /**
     * Tests withEnvironment generates new cache file
     */
    public function testWithEnvironmentGeneratesNewCache(): void {
        $mock = new MockHandler([]);

        $client1 = $this->createClientWithMock($mock, true);
        $client2 = $client1->withEnvironment('staging');

        // Cache files should be different
        $this->assertNotSame($client1->getCacheFile(), $client2->getCacheFile());

        // Both should contain phlag_cache_ prefix
        $this->assertStringContainsString('phlag_cache_', $client1->getCacheFile());
        $this->assertStringContainsString('phlag_cache_', $client2->getCacheFile());
    }

    /**
     * Tests withEnvironment preserves cache settings
     */
    public function testWithEnvironmentPreservesCacheSettings(): void {
        $mock = new MockHandler([]);

        $client1 = $this->createClientWithMock($mock, true, null, 600);
        $client2 = $client1->withEnvironment('staging');

        $this->assertTrue($client2->isCacheEnabled());
        $this->assertSame(600, $client2->getCacheTtl());
    }

    /**
     * Tests warmCache preloads cache
     */
    public function testWarmCachePreloadsCache(): void {
        $container = [];
        $history   = Middleware::history($container);

        $mock = new MockHandler([
            new Response(200, [], json_encode(['flag1' => true])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push($history);

        $guzzle = new GuzzleClient([
            'base_uri' => 'http://localhost:8000/',
            'handler'  => $handlerStack,
            'headers'  => [
                'Authorization' => 'Bearer test-key',
                'Accept'        => 'application/json',
            ],
        ]);

        $temp_file = sys_get_temp_dir() . '/phlag_test_' . uniqid() . '.json';

        $client = new PhlagClient(
            'http://localhost:8000',
            'test-key',
            'production',
            true,
            $temp_file
        );

        $reflection     = new ReflectionClass($client);
        $client_prop    = $reflection->getProperty('client');
        $client_prop->setAccessible(true);
        $internal_client = $client_prop->getValue($client);

        $client_reflection = new ReflectionClass($internal_client);
        $http_prop         = $client_reflection->getProperty('http_client');
        $http_prop->setAccessible(true);
        $http_prop->setValue($internal_client, $guzzle);

        // Warm cache
        $client->warmCache();

        // Should have made 1 API call
        $this->assertCount(1, $container);

        // Subsequent request should use cache (no new API call)
        $client->getFlag('flag1');

        // Still only 1 API call
        $this->assertCount(1, $container);

        // Clean up
        @unlink($temp_file);
    }

    /**
     * Tests warmCache is no-op when caching disabled
     */
    public function testWarmCacheNoOpWhenDisabled(): void {
        $container = [];
        $history   = Middleware::history($container);

        $mock = new MockHandler([]);

        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push($history);

        $guzzle = new GuzzleClient([
            'base_uri' => 'http://localhost:8000/',
            'handler'  => $handlerStack,
            'headers'  => [
                'Authorization' => 'Bearer test-key',
                'Accept'        => 'application/json',
            ],
        ]);

        $client = new PhlagClient(
            'http://localhost:8000',
            'test-key',
            'production',
            false
        );

        $reflection     = new ReflectionClass($client);
        $client_prop    = $reflection->getProperty('client');
        $client_prop->setAccessible(true);
        $internal_client = $client_prop->getValue($client);

        $client_reflection = new ReflectionClass($internal_client);
        $http_prop         = $client_reflection->getProperty('http_client');
        $http_prop->setAccessible(true);
        $http_prop->setValue($internal_client, $guzzle);

        // Warm cache (should do nothing)
        $client->warmCache();

        // Should have made 0 API calls
        $this->assertCount(0, $container);
    }

    /**
     * Tests clearCache is no-op when caching disabled
     */
    public function testClearCacheNoOpWhenDisabled(): void {
        $mock = new MockHandler([]);

        $client = $this->createClientWithMock($mock, false);

        // Should not throw exception
        $client->clearCache();

        $this->assertFalse($client->isCacheEnabled());
    }

    /**
     * Tests corrupted cache file is ignored
     */
    public function testInvalidCacheFileIgnored(): void {
        $container = [];
        $history   = Middleware::history($container);

        $mock = new MockHandler([
            new Response(200, [], json_encode(['flag1' => true])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push($history);

        $guzzle = new GuzzleClient([
            'base_uri' => 'http://localhost:8000/',
            'handler'  => $handlerStack,
            'headers'  => [
                'Authorization' => 'Bearer test-key',
                'Accept'        => 'application/json',
            ],
        ]);

        $temp_file = sys_get_temp_dir() . '/phlag_test_' . uniqid() . '.json';

        // Write corrupted JSON to cache file
        file_put_contents($temp_file, '{invalid json}');

        $client = new PhlagClient(
            'http://localhost:8000',
            'test-key',
            'production',
            true,
            $temp_file
        );

        $reflection     = new ReflectionClass($client);
        $client_prop    = $reflection->getProperty('client');
        $client_prop->setAccessible(true);
        $internal_client = $client_prop->getValue($client);

        $client_reflection = new ReflectionClass($internal_client);
        $http_prop         = $client_reflection->getProperty('http_client');
        $http_prop->setAccessible(true);
        $http_prop->setValue($internal_client, $guzzle);

        // Should ignore corrupted cache and fetch from API
        $result = $client->getFlag('flag1');

        $this->assertTrue($result);
        $this->assertCount(1, $container);

        // Clean up
        @unlink($temp_file);
    }
}
