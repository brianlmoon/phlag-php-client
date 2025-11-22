# Phlag Client

**PHP client library for the Phlag feature flag management system**

This library provides a simple, type-safe interface for querying feature flags from a [Phlag](https://github.com/moonspot/phlag) server. It handles authentication, environment management, and error handling so you can focus on feature rollouts.

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

### Working with Multiple Environments

You can switch environments without creating new client instances:

```php
$prod_client = new PhlagClient(
    base_url: 'http://phlag.example.com',
    api_key: 'your-api-key',
    environment: 'production'
);

// Create a new client for staging (immutable pattern)
$staging_client = $prod_client->withEnvironment('staging');

// Original client unchanged
echo $prod_client->getEnvironment(); // "production"
echo $staging_client->getEnvironment(); // "staging"

// Query both environments
$prod_enabled = $prod_client->isEnabled('feature_beta');
$staging_enabled = $staging_client->isEnabled('feature_beta');
```

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

#### `__construct(string $base_url, string $api_key, string $environment)`

Creates a new client instance.

**Parameters:**
- `$base_url` - Base URL of your Phlag server (e.g., `http://localhost:8000`)
- `$api_key` - 64-character API key from the Phlag admin panel
- `$environment` - Environment name (e.g., `production`, `staging`, `development`)

#### `getFlag(string $name): mixed`

Retrieves a flag value.

**Parameters:**
- `$name` - Flag name

**Returns:** The flag value (bool, int, float, string, or null)

**Throws:**
- `AuthenticationException` - Invalid API key
- `InvalidFlagException` - Flag doesn't exist
- `InvalidEnvironmentException` - Environment doesn't exist
- `NetworkException` - Network communication failed
- `PhlagException` - Other errors

#### `isEnabled(string $name): bool`

Convenience method for checking SWITCH flags.

**Parameters:**
- `$name` - Flag name

**Returns:** `true` if the flag value is boolean `true`, `false` otherwise

**Throws:** Same as `getFlag()`

#### `getEnvironment(): string`

Gets the current environment name.

**Returns:** The environment name

#### `withEnvironment(string $environment): self`

Creates a new client for a different environment.

**Parameters:**
- `$environment` - New environment name

**Returns:** New PhlagClient instance (immutable pattern)

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
- [Moonspot Value Objects](https://github.com/moonspot/value-objects) - Base classes

## Support

For bugs and feature requests, please use the GitHub issue tracker.

For questions, contact brian@moonspot.net.
