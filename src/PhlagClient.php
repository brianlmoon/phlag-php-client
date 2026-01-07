<?php

namespace Moonspot\PhlagClient;

/**
 * Primary client for interacting with the Phlag feature flag API
 *
 * This is the main entry point for the Phlag client library. It provides
 * methods for retrieving feature flag values from a specific environment.
 * The environment is set at construction time and all requests use that
 * environment.
 *
 * Usage:
 * ```php
 * $client = new PhlagClient(
 *     base_url: 'http://localhost:8000',
 *     api_key: 'your-64-char-api-key',
 *     environment: 'production',
 *     timeout: 15
 * );
 *
 * // Check a boolean flag
 * if ($client->isEnabled('feature_checkout')) {
 *     // Feature is enabled
 * }
 *
 * // Get a typed value
 * $max_items = $client->getFlag('max_items'); // returns int or null
 * ```
 *
 * @package Moonspot\PhlagClient
 */
class PhlagClient {
    /**
     * @var Client The HTTP client for API communication
     */
    protected Client $client;

    /**
     * @var string The environment name for flag lookups
     */
    protected string $environment;

    /**
     * @var string The base URL of the Phlag server
     */
    protected string $base_url;

    /**
     * @var string The API key for authentication
     */
    protected string $api_key;

    /**
     * @var bool Whether caching is enabled
     */
    protected bool $cache_enabled;

    /**
     * @var string The cache file path
     */
    protected string $cache_file;

    /**
     * @var int The cache time-to-live in seconds
     */
    protected int $cache_ttl;

    /**
     * @var int The HTTP request timeout in seconds
     */
    protected int $timeout;

    /**
     * @var array|null The in-memory flag cache
     */
    protected ?array $flag_cache = null;

    /**
     * Creates a new Phlag client for a specific environment
     *
     * The environment is set at construction time and all flag requests will
     * use this environment. To query a different environment, create a new
     * instance or use the withEnvironment() method.
     *
     * When caching is enabled, the client fetches all flags for the environment
     * once using the /all-flags endpoint and serves subsequent requests from
     * the cached data. This dramatically reduces API calls but means flag
     * changes won't be reflected until the cache expires (default 5 minutes).
     *
     * @param string      $base_url    The base URL of the Phlag server (e.g., http://localhost:8000)
     * @param string      $api_key     The 64-character API key for authentication
     * @param string      $environment The environment name (e.g., production, staging, development)
     * @param bool        $cache       Enable file-based caching (default: false)
     * @param string|null $cache_file  Custom cache file path (default: auto-generated in sys temp dir)
     * @param int         $cache_ttl   Cache time-to-live in seconds (default: 300)
     * @param int         $timeout     HTTP request timeout in seconds (default: 10)
     */
    public function __construct(
        string $base_url,
        string $api_key,
        string $environment,
        bool $cache = false,
        ?string $cache_file = null,
        int $cache_ttl = 300,
        int $timeout = 10
    ) {
        $this->base_url      = $base_url;
        $this->api_key       = $api_key;
        $this->environment   = $environment;
        $this->cache_enabled = $cache;
        $this->cache_ttl     = $cache_ttl;
        $this->timeout       = $timeout;
        $this->client        = new Client($base_url, $api_key, $timeout);

        // Generate cache filename if not provided
        if ($cache_file === null) {
            $this->cache_file = $this->generateCacheFilename();
        } else {
            $this->cache_file = $cache_file;
        }
    }

    /**
     * Gets the value of a single feature flag
     *
     * This method retrieves the current value of a flag from the configured
     * environment. The return type depends on the flag type:
     *
     * - SWITCH flags return boolean (true/false)
     * - INTEGER flags return int or null
     * - FLOAT flags return float or null
     * - STRING flags return string or null
     *
     * Flags return null when they don't exist, aren't configured for the
     * environment, or are outside their temporal constraints (for non-SWITCH
     * types). SWITCH flags return false when inactive.
     *
     * When caching is enabled, this method serves values from the in-memory
     * cache (populated on first request). When caching is disabled, each call
     * makes a direct API request to /flag/{environment}/{name}.
     *
     * @param string $name The flag name
     *
     * @return mixed The flag value (bool, int, float, string, or null)
     *
     * @throws Exception\AuthenticationException      When the API key is invalid
     * @throws Exception\InvalidFlagException         When the flag doesn't exist (cache disabled only)
     * @throws Exception\InvalidEnvironmentException  When the environment doesn't exist
     * @throws Exception\NetworkException             When network communication fails
     * @throws Exception\PhlagException               For other errors
     */
    public function getFlag(string $name): mixed {
        $return = null;

        if ($this->cache_enabled) {
            // Lazy load cache on first request
            if ($this->flag_cache === null) {
                $this->loadCache();
            }

            $return = $this->flag_cache[$name] ?? null;
        } else {
            // Use direct API call
            $endpoint = sprintf('flag/%s/%s', $this->environment, $name);
            $return   = $this->client->get($endpoint);
        }

        return $return;
    }

    /**
     * Checks if a SWITCH flag is enabled
     *
     * This is a convenience method for checking boolean flags. It's equivalent
     * to calling getFlag() and checking for true, but provides a more readable
     * API for the common case of feature toggles.
     *
     * Heads-up: This only makes sense for SWITCH type flags. Using it with
     * other flag types will return false for any non-true value.
     *
     * @param string $name The flag name
     *
     * @return bool True if the flag is enabled, false otherwise
     *
     * @throws Exception\AuthenticationException      When the API key is invalid
     * @throws Exception\InvalidEnvironmentException  When the environment doesn't exist
     * @throws Exception\NetworkException             When network communication fails
     * @throws Exception\PhlagException               For other errors
     */
    public function isEnabled(string $name): bool {
        $return = false;

        $value = $this->getFlag($name);

        if ($value === true) {
            $return = true;
        }

        return $return;
    }

    /**
     * Gets the current environment name
     *
     * @return string The environment name
     */
    public function getEnvironment(): string {
        return $this->environment;
    }

    /**
     * Creates a new client instance with a different environment
     *
     * This method returns a new PhlagClient instance configured for a different
     * environment while reusing the same base URL and API key. This is useful
     * when you need to query multiple environments without maintaining multiple
     * client instances.
     *
     * The original client instance is not modified (immutable pattern). Cache
     * settings are preserved, but a new cache file is generated for the new
     * environment to prevent cache collisions.
     *
     * @param string $environment The new environment name
     *
     * @return self A new PhlagClient instance for the specified environment
     */
    public function withEnvironment(string $environment): self {
        $return = new self(
            $this->base_url,
            $this->api_key,
            $environment,
            $this->cache_enabled,
            null, // Let new instance generate its own cache file
            $this->cache_ttl,
            $this->timeout
        );

        return $return;
    }

    /**
     * Generates a cache filename based on base URL and environment
     *
     * The filename is generated using an MD5 hash of the base URL and
     * environment name to ensure uniqueness across different Phlag servers
     * and environments. The file is placed in the system temp directory.
     *
     * @return string The absolute path to the cache file
     */
    protected function generateCacheFilename(): string {
        $return = null;

        $hash   = md5($this->base_url . '|' . $this->environment);
        $return = sys_get_temp_dir() . '/phlag_cache_' . $hash . '.json';

        return $return;
    }

    /**
     * Loads flag cache from file or API
     *
     * This method first checks if a valid cache file exists. If the file
     * exists and hasn't expired (based on file mtime and TTL), it loads
     * the cached data. Otherwise, it fetches all flags from the API using
     * the /all-flags endpoint and writes the cache file.
     *
     * Cache file write failures are logged but don't throw exceptions,
     * allowing graceful degradation to cache-less operation.
     *
     * @return void
     *
     * @throws Exception\AuthenticationException      When the API key is invalid
     * @throws Exception\InvalidEnvironmentException  When the environment doesn't exist
     * @throws Exception\NetworkException             When network communication fails
     * @throws Exception\PhlagException               For other API errors
     */
    protected function loadCache(): void {
        // Check if cache file exists and is valid
        clearstatcache();
        if (file_exists($this->cache_file)) {
            $mtime = filemtime($this->cache_file);

            if ($mtime !== false && (time() - $mtime) < $this->cache_ttl) {

                // file_exists is called twice because on high write latency network filesystems (e.g., NFS, Amazon EFS, etc.)
                // one process could be warming the cache at the same time that another process is trying to load the
                // cache causing the file_get_contents function to fail even though the earlier file_exists passed.
                if (file_exists($this->cache_file)) {
                    $contents = file_get_contents($this->cache_file);

                    if ($contents !== false) {
                        $data = json_decode($contents, true);

                        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                            $this->flag_cache = $data;

                            return;
                        }
                    }
                }
            }
        }

        // Cache miss or expired - fetch from API
        $endpoint         = sprintf('all-flags/%s', $this->environment);
        $this->flag_cache = $this->client->get($endpoint);

        // Write to cache file using atomic write
        $this->writeCacheFile();
    }

    /**
     * Writes the flag cache to disk using atomic write operation
     *
     * This method uses a temporary file and rename to ensure atomic writes
     * on POSIX systems, preventing partial writes during concurrent access.
     * Write failures are logged but don't throw exceptions.
     *
     * @return void
     */
    protected function writeCacheFile(): void {
        // Use process ID to create unique temp filename
        $temp_file = $this->cache_file . '.' . getmypid() . '.tmp';

        // Write to temp file first
        if (@file_put_contents($temp_file, json_encode($this->flag_cache)) === false) {
            error_log("Phlag: Unable to write cache file: {$this->cache_file}");

            return;
        }

        // if the new file and the current file are the same, simply touch the existing file to avoid
        // non-atomic renames on high write latency filesystems (NFS, AWS, etc.).
        clearstatcache();
        if(file_exists($this->cache_file) && md5_file($temp_file) === md5_file($this->cache_file)) {
            touch($this->cache_file);
            return;
        }

        // Atomic rename (POSIX systems)
        if (@rename($temp_file, $this->cache_file) === false) {
            error_log("Phlag: Unable to rename cache file: {$temp_file} to {$this->cache_file}");
            @unlink($temp_file); // Clean up temp file
        }
    }

    /**
     * Preloads the flag cache without waiting for first request
     *
     * This method immediately fetches all flags from the API and populates
     * the cache, rather than waiting for the first getFlag() call. Useful
     * for warming the cache during application startup or deployment.
     *
     * Heads-up: This method is a no-op if caching is disabled.
     *
     * @return void
     *
     * @throws Exception\AuthenticationException      When the API key is invalid
     * @throws Exception\InvalidEnvironmentException  When the environment doesn't exist
     * @throws Exception\NetworkException             When network communication fails
     * @throws Exception\PhlagException               For other API errors
     */
    public function warmCache(): void {
        if ($this->cache_enabled) {
            $this->loadCache();
        }
    }

    /**
     * Clears the in-memory and file cache
     *
     * This forces a fresh fetch on the next flag request. Useful when you
     * know flags have been updated on the server and you want an immediate
     * refresh without waiting for TTL expiration.
     *
     * Heads-up: This method is a no-op if caching is disabled.
     *
     * @return void
     */
    public function clearCache(): void {
        if ($this->cache_enabled) {
            $this->flag_cache = null;

            if (file_exists($this->cache_file)) {
                @unlink($this->cache_file);
            }
        }
    }

    /**
     * Checks if cache is enabled
     *
     * @return bool True if caching is enabled
     */
    public function isCacheEnabled(): bool {
        return $this->cache_enabled;
    }

    /**
     * Gets the cache file path
     *
     * This returns the path even if the file doesn't exist yet. The file
     * will be created on the first cache load when caching is enabled.
     *
     * @return string The absolute path to the cache file
     */
    public function getCacheFile(): string {
        return $this->cache_file;
    }

    /**
     * Gets the cache TTL in seconds
     *
     * @return int The cache time-to-live in seconds
     */
    public function getCacheTtl(): int {
        return $this->cache_ttl;
    }

    /**
     * Gets the HTTP request timeout in seconds
     *
     * @return int The HTTP request timeout in seconds
     */
    public function getTimeout(): int {
        return $this->timeout;
    }
}
