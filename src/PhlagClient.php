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
     * @var array The environment names for flag lookups (with fallback)
     */
    protected array $environments;

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
     * @var int the last time the cache was loaded
     */
    protected int $flag_cache_last_loaded = 0;

    /**
     * Creates a new Phlag client for one or more environments
     *
     * The environment(s) are set at construction time and all flag requests
     * will use these environments. When multiple environments are provided,
     * they act as a fallback chain: if a flag returns null in the first
     * environment, the second is checked, and so on.
     *
     * Heads-up: Only null triggers fallback. Values like false, 0, and ""
     * are considered "set" and stop the fallback chain.
     *
     * When caching is enabled, the client fetches all flags for ALL
     * environments once using the /all-flags endpoint and serves subsequent
     * requests from the cached data. This dramatically reduces API calls but
     * means flag changes won't be reflected until the cache expires (default
     * 5 minutes).
     *
     * @param string       $base_url    The base URL of the Phlag server (e.g., http://localhost:8000)
     * @param string       $api_key     The 64-character API key for authentication
     * @param string|array $environment Single environment name or array of environments for fallback
     * @param bool         $cache       Enable file-based caching (default: false)
     * @param string|null  $cache_file  Custom cache file path (default: auto-generated in sys temp dir)
     * @param int          $cache_ttl   Cache time-to-live in seconds (default: 300)
     * @param int          $timeout     HTTP request timeout in seconds (default: 10)
     */
    public function __construct(
        string $base_url,
        string $api_key,
        string|array $environment,
        bool $cache = false,
        ?string $cache_file = null,
        int $cache_ttl = 300,
        int $timeout = 10
    ) {
        $this->base_url      = $base_url;
        $this->api_key       = $api_key;
        $this->cache_enabled = $cache;
        $this->cache_ttl     = $cache_ttl;
        $this->timeout       = $timeout;
        $this->client        = new Client($base_url, $api_key, $timeout);

        // Normalize environment to array
        if (is_string($environment)) {
            $this->environments = [$environment];
        } else {
            $this->environments = $environment;
        }

        // Generate cache filename if not provided
        if ($cache_file === null) {
            $this->cache_file = $this->generateCacheFilename();
        } else {
            $this->cache_file = $cache_file;
        }
    }

    /**
     * Gets the value of a single feature flag with environment fallback
     *
     * This method retrieves the current value of a flag from the configured
     * environment(s). When multiple environments are configured, it implements
     * fallback logic: if the flag returns null in the first environment, it
     * queries the second, and so on.
     *
     * Heads-up: Only null triggers fallback. Values like false, 0, and ""
     * are considered "set" and stop the fallback chain.
     *
     * The return type depends on the flag type:
     *
     * - SWITCH flags return boolean (true/false)
     * - INTEGER flags return int or null
     * - FLOAT flags return float or null
     * - STRING flags return string or null
     *
     * Flags return null when they don't exist, aren't configured for any
     * environment, or are outside their temporal constraints (for non-SWITCH
     * types). SWITCH flags return false when inactive.
     *
     * When caching is enabled, this method serves values from the in-memory
     * cache (populated on first request). When caching is disabled, each call
     * may make multiple API requests if earlier environments return null.
     *
     * @param string $name The flag name
     *
     * @return mixed The flag value (bool, int, float, string, or null)
     *
     * @throws Exception\AuthenticationException      When the API key is invalid
     * @throws Exception\InvalidEnvironmentException  When the environment doesn't exist
     * @throws Exception\NetworkException             When network communication fails
     * @throws Exception\PhlagException               For other errors
     */
    public function getFlag(string $name): mixed {
        $return = null;

        if ($this->cache_enabled) {
            // Lazy load cache on first request or anytime when running in the CLI and the TTL has passed
            if ($this->flag_cache === null || time() - $this->flag_cache_last_loaded > $this->cache_ttl) {
                $this->loadCache();
                $this->flag_cache_last_loaded = time();
            }

            $return = $this->flag_cache[$name] ?? null;
        } else {
            // Use direct API call with fallback through environments
            foreach ($this->environments as $environment) {
                $endpoint = sprintf('flag/%s/%s', $environment, $name);

                try {
                    $value = $this->client->get($endpoint);

                    // Only null triggers fallback - false, 0, "" are valid values
                    if ($value !== null) {
                        $return = $value;
                        break;
                    }
                } catch (Exception\InvalidFlagException $e) {
                    // Flag doesn't exist in this environment - continue to next
                    continue;
                }
            }
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
     * Gets the configured environment names
     *
     * Returns an array of environment names, even if only one environment
     * was configured. When multiple environments are configured, they
     * represent the fallback chain order.
     *
     * @return array The environment names
     */
    public function getEnvironment(): array {
        return $this->environments;
    }

    /**
     * Creates a new client instance with different environment(s)
     *
     * This method returns a new PhlagClient instance configured for different
     * environment(s) while reusing the same base URL and API key. This is
     * useful when you need to query multiple environments without maintaining
     * multiple client instances.
     *
     * The original client instance is not modified (immutable pattern). Cache
     * settings are preserved, but a new cache file is generated for the new
     * environment(s) to prevent cache collisions.
     *
     * @param string|array $environment Single environment name or array of environments
     *
     * @return self A new PhlagClient instance for the specified environment(s)
     */
    public function withEnvironment(string|array $environment): self {
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
     * Generates a cache filename based on base URL and environment(s)
     *
     * The filename is generated using an MD5 hash of the base URL and
     * all environment names to ensure uniqueness across different Phlag
     * servers and environment configurations. The file is placed in the
     * system temp directory.
     *
     * @return string The absolute path to the cache file
     */
    protected function generateCacheFilename(): string {
        $return = null;

        $env_string = implode('|', $this->environments);
        $hash       = md5($this->base_url . '|' . $env_string);
        $return     = sys_get_temp_dir() . '/phlag_cache_' . $hash . '.json';

        return $return;
    }

    /**
     * Loads flag cache from file or API
     *
     * This method first checks if a valid cache file exists. If the file
     * exists and hasn't expired (based on file mtime and TTL), it loads
     * the cached data. Otherwise, it fetches all flags from the API.
     *
     * When multiple environments are configured, this method fetches flags
     * from ALL environments and merges them. Primary (first) environment
     * values take precedence over secondary environments.
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

        $this->flag_cache = null;

        // Check if cache file exists and is valid
        clearstatcache();
        if (file_exists($this->cache_file)) {
            $mtime = filemtime($this->cache_file);

            if ($mtime !== false && (time() - $mtime) < $this->cache_ttl) {

                // file_exists is called twice because on high write latency network filesystems (e.g., NFS, Amazon EFS, etc.)
                // one process could be warming the cache at the same time that another process is trying to load the
                // cache causing the file_get_contents function to fail even though the earlier file_exists passed.
                if (file_exists($this->cache_file)) {
                    $this->loadCacheFile();
                }
            }
        }

        if (empty($this->flag_cache)) {
            try {
                // Cache miss or expired - fetch from API and merge
                $this->flag_cache = $this->fetchAndMergeFlags();

                // Write to cache file using atomic write
                $this->writeCacheFile();
            } catch (\Throwable $e) {
                // If the cache file exists, attempt to load the stale cache
                if (file_exists($this->cache_file)) {
                    $this->loadCacheFile();

                    // If loading the stale cache failed to populate a usable cache,
                    // rethrow the original exception so the error is not silently swallowed.
                    if (!is_array($this->flag_cache) || empty($this->flag_cache)) {
                        throw $e;
                    }
                } else {
                    throw $e;
                }
            }
        }
    }

    /**
     * Loads flag data from the cache file into memory
     *
     * This method reads the JSON-encoded cache file and populates the
     * in-memory flag_cache. It is used both when a valid (unexpired)
     * cache file exists and when loading a potentially stale cache as a
     * fallback after API failures. The method performs JSON decoding with
     * error checking to ensure data integrity.
     *
     * Heads-up: This method silently returns without populating cache if
     * the file read fails, JSON is invalid, or data isn't an array. In the
     * normal path, the calling code (loadCache) will then fetch fresh data
     * from the API. In the API-failure fallback path, there may be no
     * subsequent API fetch and the cache may remain empty or unchanged.
     *
     * @return void
     */
    protected function loadCacheFile(): void {
        $contents = file_get_contents($this->cache_file);

        if ($contents !== false) {
            $data = json_decode($contents, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                $this->flag_cache = $data;
            }
        }
    }

    /**
     * Fetches flags from all configured environments and merges them
     *
     * This method queries the /all-flags endpoint for each environment
     * and merges the results. Only null values from earlier (primary)
     * environments are overridden by later (fallback) environments.
     * Non-null values including false, 0, and empty string take precedence
     * and are never overridden.
     *
     * @return array The merged flag data
     *
     * @throws Exception\AuthenticationException      When the API key is invalid
     * @throws Exception\InvalidEnvironmentException  When an environment doesn't exist
     * @throws Exception\NetworkException             When network communication fails
     * @throws Exception\PhlagException               For other API errors
     */
    protected function fetchAndMergeFlags(): array {
        $return = [];

        // Iterate through environments in order (primary first)
        // Only add/update values that are missing or null
        foreach ($this->environments as $environment) {
            $endpoint = sprintf('all-flags/%s', $environment);
            $flags    = $this->client->get($endpoint);

            // Merge flags, but only set values if key doesn't exist
            // or existing value is null
            foreach ($flags as $key => $value) {
                if (!isset($return[$key]) || $return[$key] === null) {
                    $return[$key] = $value;
                }
            }
        }

        return $return;
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
        // Use random bytes to create unique temp filename
        $temp_file = $this->cache_file . '.' . bin2hex(random_bytes(16)) . '.tmp';

        // Write to temp file first
        if (@file_put_contents($temp_file, json_encode($this->flag_cache)) === false) {
            trigger_error("Phlag: Unable to write cache file: {$this->cache_file}", E_USER_WARNING);

            return;
        }

        // if the new file and the current file are the same, simply touch the existing file to avoid
        // non-atomic renames on high write latency filesystems (NFS, AWS, etc.).
        clearstatcache();
        if (file_exists($this->cache_file) && md5_file($temp_file) === md5_file($this->cache_file)) {
            touch($this->cache_file);
            @unlink($temp_file); // Clean up temp file when contents are identical

            return;
        }

        // Atomic rename (POSIX systems)
        if (@rename($temp_file, $this->cache_file) === false) {
            trigger_error("Phlag: Unable to rename cache file: {$temp_file} to {$this->cache_file}", E_USER_WARNING);
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
