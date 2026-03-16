# Phlag Client

**PHP client library for the Phlag feature flag management system**

This library provides a simple, type-safe interface for querying feature flags from a [Phlag](https://github.com/brianlmoon/phlag) server. It handles authentication, environment management, and error handling so you can focus on feature rollouts.

## Features

- 🎯 **Type-safe flag retrieval** - Get boolean, integer, float, or string values
- 🌐 **Environment-aware** - Configure once, query a specific environment
- 🔄 **Immutable environment switching** - Easy multi-environment queries
- ⚡ **Simple API** - Clean, fluent interface with convenience methods
- 🛡️ **Robust error handling** - Specific exceptions for different error conditions
- ✅ **Fully tested** - Comprehensive test coverage with PHPUnit

## Requirements

- PHP 8.2 or higher
- Composer
- A running Phlag server instance

## Installation

Install via Composer:

```bash
composer require moonspot/phlag-client
```

## Quick Start

```php
use Moonspot\PhlagClient\PhlagClient;

// Create a client for a specific environment
$client = new PhlagClient(
    base_url: 'http://localhost:8000',
    api_key: 'your-64-character-api-key',
    environment: 'production'
);

// Check if a feature is enabled
if ($client->isEnabled('feature_checkout')) {
    // Show the new checkout flow
}

// Get typed configuration values
$max_items = $client->getFlag('max_items'); // returns int or null
$price_multiplier = $client->getFlag('price_multiplier'); // returns float or null
$welcome_message = $client->getFlag('welcome_message'); // returns string or null

// For high-traffic apps, enable caching
$cached_client = new PhlagClient(
    base_url: 'http://localhost:8000',
    api_key: 'your-api-key',
    environment: 'production',
    cache: true  // Fetches all flags once, caches for 5 minutes
);
```

## Usage

### Creating a Client

The `PhlagClient` constructor requires three parameters:

```php
$client = new PhlagClient(
    base_url: 'http://phlag.example.com',    // Your Phlag server URL
    api_key: 'your-api-key-here',            // 64-char API key from Phlag admin
    environment: 'production'                 // Environment to query
);
```

#### Subdirectory Installation

If your Phlag server is installed in a subdirectory, include the full path in the base URL:

```php
$client = new PhlagClient(
    base_url: 'https://www.example.com/phlag',  // Note: includes /phlag subdirectory
    api_key: 'your-api-key',
    environment: 'production'
);

// Client will correctly request:
// https://www.example.com/phlag/flag/production/feature_name
```

The client automatically handles trailing slashes, so both `https://www.example.com/phlag` and `https://www.example.com/phlag/` work correctly.

### Multi-Environment Fallback

Configure multiple environments for automatic fallback. If a flag returns `null` in the first environment, it checks the next environment, and so on:

```php
// Configure with fallback chain: staging → development
$client = new PhlagClient(
    base_url: 'http://localhost:8000',
    api_key: 'your-api-key',
    environment: ['staging', 'development']
);

// Query a flag - automatically falls back through environments
$value = $client->getFlag('feature_beta');
// Returns value from staging, or development if not set in staging
```

**⚠️ Use Case: Development & QA Only**

Multi-environment fallback is designed for **development and QA environments**, not production. It's useful when:

- **Local development:** Fall back from your feature branch environment to staging or development
- **QA testing:** Test with production-like config while allowing QA-specific overrides
- **Staging environments:** Inherit production flags but override specific features for testing

**Do NOT use in production.** Production should query a single, explicit environment to avoid:
- Unpredictable behavior when flags are missing
- Hidden configuration issues masked by fallback
- Performance overhead from multiple API calls (without caching)
- Audit/compliance concerns about which environment provided the value

```php
// ✓ Good: Development with fallback
$dev_client = new PhlagClient($url, $key, ['my-feature-branch', 'staging']);

// ✓ Good: QA with fallback
$qa_client = new PhlagClient($url, $key, ['qa', 'production']);

// ✗ Bad: Production with fallback (defeats the purpose of explicit config)
$prod_client = new PhlagClient($url, $key, ['production', 'staging']); // Don't do this!

// ✓ Good: Production with single environment
$prod_client = new PhlagClient($url, $key, 'production');
```

**Fallback Rules:**

- **Only `null` triggers fallback** - Values like `false`, `0`, and `""` are considered "set" and stop the fallback chain
- **Order matters** - Earlier environments take precedence
- **Cache-aware** - With caching enabled, all environments are fetched and merged once

**Example scenarios:**

```php
$client = new PhlagClient($url, $key, ['staging', 'development']);

// Scenario 1: Staging has value
// staging: 'feature' => true
// development: 'feature' => false
$result = $client->getFlag('feature'); // returns true (staging wins)

// Scenario 2: Staging returns null, development has value
// staging: 'feature' => null (not configured)
// development: 'feature' => false
$result = $client->getFlag('feature'); // returns false (from development)

// Scenario 3: Staging returns false (valid value)
// staging: 'feature' => false
// development: 'feature' => true
$result = $client->getFlag('feature'); // returns false (no fallback, false is valid)

// Scenario 4: Neither environment has the flag
// staging: 'feature' => null
// development: 'feature' => null
$result = $client->getFlag('feature'); // returns null
```

**Performance considerations:**

- **Without caching:** Worst case = N API calls (one per environment if each returns null)
- **With caching:** 1 API call per environment on first request, then zero API calls until cache expires

### Checking Feature Flags

The `isEnabled()` method is perfect for boolean feature toggles:

```php
if ($client->isEnabled('feature_new_dashboard')) {
    // Feature is active
}
```

Heads-up: `isEnabled()` returns `true` only for actual boolean `true` values. Non-existent flags, inactive flags, and non-boolean values all return `false`.

### Getting Flag Values

Use `getFlag()` to retrieve any flag type:

```php
// SWITCH flags return bool
$enabled = $client->getFlag('feature_checkout'); // true or false

// INTEGER flags return int or null
$max_items = $client->getFlag('max_items'); // 100 or null

// FLOAT flags return float or null
$multiplier = $client->getFlag('price_multiplier'); // 1.5 or null

// STRING flags return string or null
$message = $client->getFlag('welcome_message'); // "Hello!" or null
```

**When flags return `null`:**
- The flag doesn't exist
- The flag isn't configured for the environment
- The flag is outside its temporal constraints (start/end dates)

Note: SWITCH flags return `false` when inactive, not `null`.

### Switching Environments

You can create new client instances for different environments without losing configuration:

```php
$prod_client = new PhlagClient(
    base_url: 'http://phlag.example.com',
    api_key: 'your-api-key',
    environment: 'production'
);

// Create a new client for staging (immutable pattern)
$staging_client = $prod_client->withEnvironment('staging');

// Or switch to multiple environments with fallback
$multi_client = $prod_client->withEnvironment(['staging', 'development']);

// Original client unchanged
$prod_envs = $prod_client->getEnvironment(); // ["production"]
$staging_envs = $staging_client->getEnvironment(); // ["staging"]
$multi_envs = $multi_client->getEnvironment(); // ["staging", "development"]

// Query each client
$prod_enabled = $prod_client->isEnabled('feature_beta');
$staging_enabled = $staging_client->isEnabled('feature_beta');
```

**Heads-up:** `getEnvironment()` always returns an array, even for single environments. This is a **breaking change** from earlier versions that returned a string.

## Performance & Caching

For high-traffic applications, enable file-based caching to dramatically reduce API calls:

```php
$client = new PhlagClient(
    base_url: 'http://localhost:8000',
    api_key: 'your-api-key',
    environment: 'production',
    cache: true,           // Enable caching
    cache_file: null,      // Auto-generate filename (optional)
    cache_ttl: 300         // Cache for 5 minutes (optional)
);

// First call fetches all flags from API (1 request)
$enabled = $client->isEnabled('feature_checkout');

// Subsequent calls use cached data (0 requests)
$max = $client->getFlag('max_items');
$price = $client->getFlag('price_multiplier');
```

### How Caching Works

When caching is enabled:
1. **First request**: Client fetches ALL flags for the environment via `/all-flags` endpoint
2. **Cache storage**: Flags stored in memory AND persisted to disk
3. **Subsequent requests**: Served from in-memory cache (no API calls)
4. **Cache expiration**: After TTL expires, next request refreshes from API
5. **Cross-request persistence**: Cache file survives between PHP requests

### Cache File Location

By default, cache files are stored in the system temp directory with auto-generated names:

```php
// Auto-generated filename format
sys_get_temp_dir() . '/phlag_cache_{hash}.json'

// Hash is MD5 of base_url + environment
// Example: /tmp/phlag_cache_a1b2c3d4e5f6.json
```

**Custom cache file:**

```php
$client = new PhlagClient(
    base_url: 'http://localhost:8000',
    api_key: 'your-api-key',
    environment: 'production',
    cache: true,
    cache_file: '/var/cache/app/phlag_prod.json'
);
```

### Cache Management

**Warming the cache** (preload before first request):

```php
$client->warmCache();  // Immediately fetches and caches all flags
```

**Clearing the cache** (force fresh fetch):

```php
$client->clearCache();  // Removes cache file and in-memory data

// Next request will fetch fresh from API
$value = $client->getFlag('feature');
```

**Checking cache status:**

```php
if ($client->isCacheEnabled()) {
    echo "Cache file: " . $client->getCacheFile() . "\n";
    echo "TTL: " . $client->getCacheTtl() . " seconds\n";
}
```

### When to Use Caching

**✅ Good use cases:**
- High-traffic applications with frequent flag checks
- Flags that change infrequently (hourly, daily)
- Reducing API load and network latency
- Improving response times (sub-millisecond flag checks)

**❌ When to avoid caching:**
- You need real-time flag updates (seconds matter)
- Flags change very frequently
- Low-traffic applications (caching overhead not worth it)
- Single flag check per request

### Performance Impact

**Without caching:**
- API calls: N (one per `getFlag()` call)
- Network overhead: ~10-50ms per call
- Total overhead: N × 10-50ms

**With caching:**
- API calls: 1 per TTL period (default 5 minutes)
- First request: ~10-50ms (fetch all flags)
- Subsequent requests: <1ms (memory lookup)
- Cache file I/O: ~1-2ms on first load per PHP request

**Example savings** (100 flag checks per request, 1000 requests/minute):
- Without cache: 100,000 API calls/minute
- With cache (300s TTL): ~20 API calls/minute (99.98% reduction)

## Error Handling

The client throws specific exceptions for different error conditions:

```php
use Moonspot\PhlagClient\Exception\AuthenticationException;
use Moonspot\PhlagClient\Exception\InvalidEnvironmentException;
use Moonspot\PhlagClient\Exception\InvalidFlagException;
use Moonspot\PhlagClient\Exception\NetworkException;
use Moonspot\PhlagClient\Exception\PhlagException;

try {
    $value = $client->getFlag('my_flag');
} catch (AuthenticationException $e) {
    // Invalid API key (401)
    error_log('Bad API key: ' . $e->getMessage());
} catch (InvalidFlagException $e) {
    // Flag doesn't exist (404)
    error_log('Flag not found: ' . $e->getMessage());
} catch (InvalidEnvironmentException $e) {
    // Environment doesn't exist (404)
    error_log('Environment not found: ' . $e->getMessage());
} catch (NetworkException $e) {
    // Connection failed, timeout, etc.
    error_log('Network error: ' . $e->getMessage());
} catch (PhlagException $e) {
    // Other errors (500, etc.)
    error_log('Phlag error: ' . $e->getMessage());
}
```

All exceptions extend `PhlagException`, so you can catch them all with a single block:

```php
try {
    $value = $client->getFlag('my_flag');
} catch (PhlagException $e) {
    // Handle any Phlag error
    error_log('Error fetching flag: ' . $e->getMessage());
    $value = null; // Use a safe default
}
```

## API Reference

### PhlagClient

#### `__construct(string $base_url, string $api_key, string|array $environment, bool $cache = false, ?string $cache_file = null, int $cache_ttl = 300)`

Creates a new client instance.

**Parameters:**
- `$base_url` - Base URL of your Phlag server (e.g., `http://localhost:8000`)
- `$api_key` - 64-character API key from the Phlag admin panel
- `$environment` - Single environment name (string) or multiple environments for fallback (array)
- `$cache` - Enable file-based caching (default: `false`)
- `$cache_file` - Custom cache file path (default: auto-generated in system temp directory)
- `$cache_ttl` - Cache time-to-live in seconds (default: `300`)

#### `getFlag(string $name): mixed`

Retrieves a flag value. When caching is enabled, serves from cache after first request. When multiple environments are configured, implements fallback logic.

**Parameters:**
- `$name` - Flag name

**Returns:** The flag value (bool, int, float, string, or null)

**Throws:**
- `AuthenticationException` - Invalid API key
- `InvalidEnvironmentException` - Environment doesn't exist
- `NetworkException` - Network communication failed
- `PhlagException` - Other errors

**Heads-up:** With multi-environment fallback, InvalidFlagException is caught internally to continue the fallback chain.

#### `isEnabled(string $name): bool`

Convenience method for checking SWITCH flags.

**Parameters:**
- `$name` - Flag name

**Returns:** `true` if the flag value is boolean `true`, `false` otherwise

**Throws:** Same as `getFlag()`

#### `getEnvironment(): array`

Gets the configured environment names.

**Returns:** Array of environment names (even for single environment)

**Heads-up:** This is a breaking change from previous versions that returned a string for single environments.

#### `withEnvironment(string|array $environment): self`

Creates a new client for different environment(s). Cache settings are preserved but a new cache file is generated.

**Parameters:**
- `$environment` - Single environment name or array of environments

**Returns:** New PhlagClient instance (immutable pattern)

#### `warmCache(): void`

Preloads the flag cache immediately. Useful for warming cache during application startup.

**Heads-up:** No-op if caching is disabled.

**Throws:** Same as `getFlag()` for API errors

#### `clearCache(): void`

Clears in-memory and file cache, forcing fresh fetch on next request.

**Heads-up:** No-op if caching is disabled.

#### `isCacheEnabled(): bool`

Checks if caching is enabled.

**Returns:** `true` if caching is enabled

#### `getCacheFile(): string`

Gets the cache file path (even if file doesn't exist yet).

**Returns:** Absolute path to the cache file

#### `getCacheTtl(): int`

Gets the cache time-to-live in seconds.

**Returns:** Cache TTL in seconds

## Development

### Running Tests

```bash
# Install dependencies
composer install

# Run all tests
composer test

# Run just unit tests
composer unit

# Run just linting
composer lint

# Fix code style
composer fix
```

### Project Structure

```
phlag-client/
├── src/
│   ├── Client.php              # HTTP client wrapper
│   ├── PhlagClient.php         # Main public API
│   └── Exception/              # Exception hierarchy
├── tests/
│   ├── ClientTest.php          # Client tests
│   └── PhlagClientTest.php     # PhlagClient tests
└── composer.json
```

## Troubleshooting

### 404 Errors with Subdirectory Installation

If you're getting 404 errors and your Phlag server is installed in a subdirectory:

**Problem:** Base URL doesn't include the subdirectory path  
**Solution:** Make sure your base URL includes the full path to Phlag

```php
// Wrong - missing subdirectory
$client = new PhlagClient('https://www.example.com', 'key', 'prod');

// Correct - includes /phlag subdirectory
$client = new PhlagClient('https://www.example.com/phlag', 'key', 'prod');
```

### Stale Cache Data

If you're seeing outdated flag values when caching is enabled:

**Problem:** Cache hasn't expired yet  
**Solutions:**
1. Use shorter TTL: `new PhlagClient($url, $key, $env, cache: true, cache_ttl: 60)`
2. Manually clear: `$client->clearCache()`
3. Disable caching if you need real-time updates

### Cache File Permission Errors

If cache files aren't being created:

**Problem:** No write permission to temp directory or custom cache path  
**Solution:** Ensure PHP has write access to `sys_get_temp_dir()` or your custom cache directory

```php
// Check permissions
$cache_file = $client->getCacheFile();
$cache_dir = dirname($cache_file);
if (!is_writable($cache_dir)) {
    echo "No write permission to: $cache_dir\n";
}
```

Heads-up: Cache write failures are logged but don't throw exceptions. The client gracefully degrades to non-cached operation.

### Connection Timeouts

The default timeout is 10 seconds. If you're experiencing timeouts with a slow network or heavily loaded server, you may need to adjust Guzzle's timeout configuration by extending the Client class.

### Invalid API Key Errors

Make sure you're using the full 64-character API key exactly as shown in the Phlag admin panel. Keys are case-sensitive and must be copied completely.

## Contributing

Contributions welcome! This project follows strict coding standards:

- PSR-1 and PSR-12 compliance
- 1TBS brace style
- snake_case for variables/properties
- camelCase for methods
- Type declarations on all methods
- Protected visibility (not private) unless truly encapsulated
- PHPDoc blocks in conversational style

Run `composer fix` before committing to ensure code style compliance.

## License

BSD 3-Clause License - see [LICENSE](LICENSE) file for details.

## Credits

Built by Brian Moon (brian@moonspot.net)

**Dependencies:**
- [Guzzle HTTP Client](https://github.com/guzzle/guzzle) - HTTP communication

## Support

For bugs and feature requests, please use the GitHub issue tracker.

For questions, contact brian@moonspot.net.
