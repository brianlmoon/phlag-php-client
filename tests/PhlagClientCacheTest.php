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

    /**
     * Tests that initial cache load sets the last loaded timestamp
     *
     * When cache is enabled and the first getFlag() call is made, the
     * flag_cache_last_loaded timestamp should be set to the current time.
     */
    public function testInitialCacheLoadSetsTimestamp(): void {
        $return = null;

        $mock = new MockHandler([
            new Response(200, [], json_encode(['flag1' => true])),
        ]);

        $temp_file = sys_get_temp_dir() . '/phlag_test_' . uniqid() . '.json';
        $client    = $this->createClientWithMock($mock, true, $temp_file);

        $time_before = time();

        // First getFlag() call should load cache and set timestamp
        $client->getFlag('flag1');

        $time_after = time();

        // Access protected flag_cache_last_loaded property
        $reflection = new ReflectionClass($client);
        $prop       = $reflection->getProperty('flag_cache_last_loaded');
        $prop->setAccessible(true);
        $last_loaded = $prop->getValue($client);

        // Timestamp should be set to current time (within test window)
        $this->assertGreaterThanOrEqual($time_before, $last_loaded);
        $this->assertLessThanOrEqual($time_after, $last_loaded);

        $return = true;

        // Clean up
        @unlink($temp_file);

        $this->assertTrue($return);
    }

    /**
     * Tests that multiple requests within TTL don't reload cache
     *
     * When cache is enabled and multiple getFlag() calls are made within
     * the TTL window, only one API call should be made and the timestamp
     * should not change.
     */
    public function testMultipleRequestsWithinTtlDontReload(): void {
        $return = null;

        $container = [];
        $history   = Middleware::history($container);

        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'flag1' => true,
                'flag2' => 100,
                'flag3' => 'test',
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
            $temp_file,
            300  // 5 minute TTL
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
        $client->getFlag('flag1');

        // Get timestamp after first load
        $timestamp_prop = $reflection->getProperty('flag_cache_last_loaded');
        $timestamp_prop->setAccessible(true);
        $first_timestamp = $timestamp_prop->getValue($client);

        // Make more requests
        $client->getFlag('flag2');
        $client->getFlag('flag3');

        // Get timestamp after subsequent requests
        $second_timestamp = $timestamp_prop->getValue($client);

        // Should have made only 1 API call
        $this->assertCount(1, $container);
        $this->assertStringContainsString(
            'all-flags/production',
            (string) $container[0]['request']->getUri()
        );

        // Timestamp should not have changed
        $this->assertSame($first_timestamp, $second_timestamp);

        $return = true;

        // Clean up
        @unlink($temp_file);

        $this->assertTrue($return);
    }

    /**
     * Tests that request after TTL expires triggers cache reload
     *
     * When cache is enabled and a request is made after the TTL has
     * expired, a second API call should be made and the timestamp
     * should be updated.
     */
    public function testRequestAfterTtlExpiresTriggersReload(): void {
        $return = null;

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
            $temp_file,
            60  // 60 second TTL
        );

        $reflection     = new ReflectionClass($client);
        $client_prop    = $reflection->getProperty('client');
        $client_prop->setAccessible(true);
        $internal_client = $client_prop->getValue($client);

        $client_reflection = new ReflectionClass($internal_client);
        $http_prop         = $client_reflection->getProperty('http_client');
        $http_prop->setAccessible(true);
        $http_prop->setValue($internal_client, $guzzle);

        // First request - loads cache
        $client->getFlag('flag1');

        // Should have made 1 API call
        $this->assertCount(1, $container);

        // Get timestamp after first load
        $timestamp_prop = $reflection->getProperty('flag_cache_last_loaded');
        $timestamp_prop->setAccessible(true);
        $first_timestamp = $timestamp_prop->getValue($client);

        // Simulate TTL expiration by setting timestamp to 61 seconds ago
        $timestamp_prop->setValue($client, time() - 61);

        // Delete cache file to force API reload
        @unlink($temp_file);

        // Second request - should reload cache
        $client->getFlag('flag1');

        // Should have made 2 API calls
        $this->assertCount(2, $container);

        // Get timestamp after reload
        $second_timestamp = $timestamp_prop->getValue($client);

        // Timestamp should have been updated (might be same second)
        $this->assertGreaterThanOrEqual($first_timestamp, $second_timestamp);

        $return = true;

        // Clean up
        @unlink($temp_file);

        $this->assertTrue($return);
    }

    /**
     * Tests that reloaded cache contains updated flag values
     *
     * When cache is reloaded after TTL expiration, the new values from
     * the API should be returned, not the old cached values.
     */
    public function testReloadedCacheContainsUpdatedValues(): void {
        $return = null;

        $container = [];
        $history   = Middleware::history($container);

        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'flag1' => true,
                'flag2' => 100,
            ])),
            new Response(200, [], json_encode([
                'flag1' => false,
                'flag2' => 200,
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
            $temp_file,
            60  // 60 second TTL
        );

        $reflection     = new ReflectionClass($client);
        $client_prop    = $reflection->getProperty('client');
        $client_prop->setAccessible(true);
        $internal_client = $client_prop->getValue($client);

        $client_reflection = new ReflectionClass($internal_client);
        $http_prop         = $client_reflection->getProperty('http_client');
        $http_prop->setAccessible(true);
        $http_prop->setValue($internal_client, $guzzle);

        // First request - get initial values
        $result1 = $client->getFlag('flag1');
        $result2 = $client->getFlag('flag2');

        $this->assertTrue($result1);
        $this->assertSame(100, $result2);

        // Simulate TTL expiration
        $timestamp_prop = $reflection->getProperty('flag_cache_last_loaded');
        $timestamp_prop->setAccessible(true);
        $timestamp_prop->setValue($client, time() - 61);

        // Delete cache file to force API reload
        @unlink($temp_file);

        // Second request - should get updated values
        $result3 = $client->getFlag('flag1');
        $result4 = $client->getFlag('flag2');

        $this->assertFalse($result3);
        $this->assertSame(200, $result4);

        // Should have made 2 API calls
        $this->assertCount(2, $container);

        $return = true;

        // Clean up
        @unlink($temp_file);

        $this->assertTrue($return);
    }

    /**
     * Tests TTL boundary edge case
     *
     * The reload condition uses > not >=, so at exactly TTL no reload
     * occurs. One second past TTL, reload is triggered.
     */
    public function testTtlBoundaryEdgeCase(): void {
        $return = null;

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
            $temp_file,
            60  // 60 second TTL
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
        $client->getFlag('flag1');

        // Set timestamp to exactly TTL+1 seconds ago (just past boundary)
        $timestamp_prop = $reflection->getProperty('flag_cache_last_loaded');
        $timestamp_prop->setAccessible(true);
        $current_time = time();
        $timestamp_prop->setValue($client, $current_time - 61);

        // Delete cache file to force API reload on TTL expiration
        @unlink($temp_file);

        // Second request just past TTL boundary
        $client->getFlag('flag1');

        // Should have made 2 API calls (reload triggered)
        $this->assertCount(2, $container);

        $return = true;

        // Clean up
        @unlink($temp_file);

        $this->assertTrue($return);
    }

    /**
     * Tests that short TTL values work correctly
     *
     * Verifies that very short TTL values (1 second) work as expected
     * and trigger reloads after expiration.
     */
    public function testShortTtlValuesWorkCorrectly(): void {
        $return = null;

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
            $temp_file,
            1  // 1 second TTL
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
        $client->getFlag('flag1');

        // Simulate 2 seconds passing (beyond 1 second TTL)
        $timestamp_prop = $reflection->getProperty('flag_cache_last_loaded');
        $timestamp_prop->setAccessible(true);
        $timestamp_prop->setValue($client, time() - 2);

        // Delete cache file to force API reload
        @unlink($temp_file);

        // Second request should trigger reload
        $client->getFlag('flag1');

        // Should have made 2 API calls
        $this->assertCount(2, $container);

        $return = true;

        // Clean up
        @unlink($temp_file);

        $this->assertTrue($return);
    }

    /**
     * Tests multi-environment cache fetches all environments
     */
    public function testMultiEnvironmentCacheFetchesAll(): void {
        $container = [];
        $history   = Middleware::history($container);

        $mock = new MockHandler([
            // First environment (production) - fetched first
            new Response(200, [], json_encode(['flag1' => 'production-value', 'flag2' => 'prod-only'])),
            // Second environment (staging) - fetched second
            new Response(200, [], json_encode(['flag1' => 'staging-value'])),
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
            ['production', 'staging'],
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

        // First request loads cache
        $result1 = $client->getFlag('flag1');
        $result2 = $client->getFlag('flag2');

        // Should have made 2 API calls (one per environment)
        $this->assertCount(2, $container);

        // Production value should win for flag1
        $this->assertSame('production-value', $result1);

        // flag2 only exists in production
        $this->assertSame('prod-only', $result2);

        // Clean up
        @unlink($temp_file);
    }

    /**
     * Tests multi-environment cache merge priority
     */
    public function testMultiEnvironmentCacheMergePriority(): void {
        $container = [];
        $history   = Middleware::history($container);

        $mock = new MockHandler([
            // First environment (production) - fetched first
            new Response(200, [], json_encode([
                'flag1' => 'production-value',
                'flag4' => 'production-only',
            ])),
            // Second environment (staging) - fetched second
            new Response(200, [], json_encode([
                'flag1' => 'staging-value',
                'flag3' => 'staging-only',
            ])),
            // Third environment (development) - fetched third
            new Response(200, [], json_encode([
                'flag1' => 'dev-value',
                'flag2' => 'dev-only',
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
            ['production', 'staging', 'development'],
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

        // Load cache
        $result1 = $client->getFlag('flag1');
        $result2 = $client->getFlag('flag2');
        $result3 = $client->getFlag('flag3');
        $result4 = $client->getFlag('flag4');

        // Should have made 3 API calls (one per environment)
        $this->assertCount(3, $container);

        // Production value wins (first environment)
        $this->assertSame('production-value', $result1);

        // Each flag exists only in its environment
        $this->assertSame('dev-only', $result2);
        $this->assertSame('staging-only', $result3);
        $this->assertSame('production-only', $result4);

        // Clean up
        @unlink($temp_file);
    }

    /**
     * Tests that cache merge only overrides null values
     *
     * When multiple environments are cached, only null values from the
     * primary environment should be overridden by fallback values. Non-null
     * values like false, 0, and "" should NOT be overridden.
     */
    public function testCacheMergeOnlyOverridesNullValues(): void {
        $container = [];
        $history   = Middleware::history($container);

        $mock = new MockHandler([
            // Primary environment (first) - has false, 0, "", null
            new Response(200, [], json_encode([
                'flag_false' => false,
                'flag_zero'  => 0,
                'flag_empty' => '',
                'flag_null'  => null,
            ])),
            // Fallback environment (second) - has different values for all
            new Response(200, [], json_encode([
                'flag_false'         => true,
                'flag_zero'          => 100,
                'flag_empty'         => 'text',
                'flag_null'          => 'from-fallback',
                'flag_only_fallback' => 'value',
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
            ['primary', 'fallback'],
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

        // Load cache - will fetch both environments
        $result_false = $client->getFlag('flag_false');
        $result_zero  = $client->getFlag('flag_zero');
        $result_empty = $client->getFlag('flag_empty');
        $result_null  = $client->getFlag('flag_null');
        $result_only  = $client->getFlag('flag_only_fallback');

        // Should have made 2 API calls (one per environment)
        $this->assertCount(2, $container);

        // false should NOT be overridden (primary wins)
        $this->assertFalse($result_false);

        // 0 should NOT be overridden (primary wins)
        $this->assertSame(0, $result_zero);

        // Empty string should NOT be overridden (primary wins)
        $this->assertSame('', $result_empty);

        // null SHOULD be overridden by fallback value
        $this->assertSame('from-fallback', $result_null);

        // Flag only in fallback should be included
        $this->assertSame('value', $result_only);

        // Clean up
        @unlink($temp_file);
    }

    /**
     * Tests that stale cache is used when API fetch fails
     *
     * When the cache is expired and the API request fails (e.g., network error),
     * the client should fall back to serving from the stale cache file rather
     * than throwing an exception. This allows graceful degradation.
     */
    public function testStaleCacheFallbackOnNetworkError(): void {
        $temp_file = sys_get_temp_dir() . '/phlag_test_' . uniqid() . '.json';

        // Create a stale cache file (expired)
        $stale_cache = [
            'feature_a' => true,
            'feature_b' => 42,
            'feature_c' => 'from-stale-cache',
        ];
        file_put_contents($temp_file, json_encode($stale_cache));

        // Set the file's mtime to be older than TTL (make it stale)
        // Using TTL of 300 seconds, so touch file to be 400 seconds old
        touch($temp_file, time() - 400);

        // Mock handler that throws NetworkException when trying to fetch
        $mock = new MockHandler([
            new ConnectException(
                'Connection timeout',
                new Request('GET', '/all-flags/production')
            ),
        ]);

        $client = $this->createClientWithMock($mock, true, $temp_file, 300);

        // Should load stale cache without throwing exception
        $result_a = $client->getFlag('feature_a');
        $result_b = $client->getFlag('feature_b');
        $result_c = $client->getFlag('feature_c');

        // Values should come from stale cache
        $this->assertTrue($result_a);
        $this->assertSame(42, $result_b);
        $this->assertSame('from-stale-cache', $result_c);

        // Clean up
        @unlink($temp_file);
    }

    /**
     * Tests that stale cache fallback works for other exception types
     *
     * The stale cache fallback should work for any Throwable, not just
     * NetworkException. This tests with an AuthenticationException.
     */
    public function testStaleCacheFallbackOnAuthenticationError(): void {
        $temp_file = sys_get_temp_dir() . '/phlag_test_' . uniqid() . '.json';

        // Create a stale cache file
        $stale_cache = [
            'cached_feature' => 'stale-value',
        ];
        file_put_contents($temp_file, json_encode($stale_cache));
        touch($temp_file, time() - 400); // Make it stale

        // Mock handler that throws 401 (authentication error)
        $mock = new MockHandler([
            new ClientException(
                'Unauthorized',
                new Request('GET', '/all-flags/production'),
                new Response(401, [], 'Unauthorized')
            ),
        ]);

        $client = $this->createClientWithMock($mock, true, $temp_file, 300);

        // Should load stale cache instead of throwing AuthenticationException
        $result = $client->getFlag('cached_feature');

        $this->assertSame('stale-value', $result);

        // Clean up
        @unlink($temp_file);
    }

    /**
     * Tests that exception is thrown when stale cache doesn't exist
     *
     * If the cache file is expired/missing and the API fetch fails, and there's
     * no stale cache to fall back to, the original exception should be thrown.
     */
    public function testExceptionThrownWhenNoStaleCacheExists(): void {
        $temp_file = sys_get_temp_dir() . '/phlag_test_' . uniqid() . '.json';

        // Don't create a cache file at all

        // Mock handler that throws NetworkException
        $mock = new MockHandler([
            new ConnectException(
                'Connection timeout',
                new Request('GET', '/all-flags/production')
            ),
        ]);

        $client = $this->createClientWithMock($mock, true, $temp_file, 300);

        // Should throw NetworkException since no stale cache exists
        $this->expectException(\Moonspot\PhlagClient\Exception\NetworkException::class);

        $client->getFlag('feature');

        // Clean up (shouldn't be needed but just in case)
        @unlink($temp_file);
    }

    /**
     * Tests that exception is thrown when stale cache is empty
     *
     * If the stale cache file exists but is empty (or invalid JSON that results
     * in empty array), the original exception should be thrown rather than
     * silently returning null for all flags.
     */
    public function testExceptionThrownWhenStaleCacheIsEmpty(): void {
        $temp_file = sys_get_temp_dir() . '/phlag_test_' . uniqid() . '.json';

        // Create an empty cache file
        file_put_contents($temp_file, json_encode([]));
        touch($temp_file, time() - 400); // Make it stale

        // Mock handler that throws NetworkException
        $mock = new MockHandler([
            new ConnectException(
                'Connection timeout',
                new Request('GET', '/all-flags/production')
            ),
        ]);

        $client = $this->createClientWithMock($mock, true, $temp_file, 300);

        // Should throw NetworkException since stale cache is empty
        $this->expectException(\Moonspot\PhlagClient\Exception\NetworkException::class);

        $client->getFlag('feature');

        // Clean up
        @unlink($temp_file);
    }

    /**
     * Tests that exception is thrown when stale cache has invalid JSON
     *
     * If the stale cache file exists but contains invalid JSON that can't
     * be decoded, the original exception should be thrown.
     */
    public function testExceptionThrownWhenStaleCacheIsInvalidJson(): void {
        $temp_file = sys_get_temp_dir() . '/phlag_test_' . uniqid() . '.json';

        // Create a cache file with invalid JSON
        file_put_contents($temp_file, 'not valid json {{{');
        touch($temp_file, time() - 400); // Make it stale

        // Mock handler that throws NetworkException
        $mock = new MockHandler([
            new ConnectException(
                'Connection timeout',
                new Request('GET', '/all-flags/production')
            ),
        ]);

        $client = $this->createClientWithMock($mock, true, $temp_file, 300);

        // Should throw NetworkException since stale cache can't be loaded
        $this->expectException(\Moonspot\PhlagClient\Exception\NetworkException::class);

        $client->getFlag('feature');

        // Clean up
        @unlink($temp_file);
    }

    /**
     * Tests stale cache fallback with multi-environment configuration
     *
     * When multiple environments are configured and the API fetch fails,
     * the stale cache should still be loaded and used.
     */
    public function testStaleCacheFallbackWithMultiEnvironment(): void {
        $temp_file = sys_get_temp_dir() . '/phlag_test_' . uniqid() . '.json';

        // Create a stale cache file with merged environment data
        $stale_cache = [
            'feature_primary'  => 'from-primary',
            'feature_fallback' => 'from-fallback',
        ];
        file_put_contents($temp_file, json_encode($stale_cache));
        touch($temp_file, time() - 400); // Make it stale

        // Mock handler that throws on all-flags requests
        $mock = new MockHandler([
            new ConnectException(
                'Connection timeout',
                new Request('GET', '/all-flags/primary')
            ),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $guzzle       = new GuzzleClient([
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
            ['primary', 'fallback'],
            true,
            $temp_file,
            300
        );

        // Inject the mocked Guzzle client
        $reflection      = new ReflectionClass($client);
        $client_prop     = $reflection->getProperty('client');
        $client_prop->setAccessible(true);
        $internal_client = $client_prop->getValue($client);

        $client_reflection = new ReflectionClass($internal_client);
        $http_prop         = $client_reflection->getProperty('http_client');
        $http_prop->setAccessible(true);
        $http_prop->setValue($internal_client, $guzzle);

        // Should load stale cache without throwing
        $result_primary  = $client->getFlag('feature_primary');
        $result_fallback = $client->getFlag('feature_fallback');

        $this->assertSame('from-primary', $result_primary);
        $this->assertSame('from-fallback', $result_fallback);

        // Clean up
        @unlink($temp_file);
    }
}
