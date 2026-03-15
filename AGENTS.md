# AGENTS.md

**Context guide for AI coding assistants working with the Phlag PHP Client**

This document provides project-specific context for AI assistants (Claude, ChatGPT, Cursor, Windsurf, etc.) working on this codebase. It highlights conventions, patterns, and gotchas that aren't obvious from the code alone.

---

## Project Overview

**What:** PHP client library for fetching feature flags from a [Phlag](https://github.com/brianlmoon/phlag) server

**Purpose:** Provides a type-safe, environment-aware API for querying feature flags with optional caching to reduce API calls

**Key capabilities:**
- Retrieve boolean, integer, float, or string flag values
- Environment-scoped flag queries (production, staging, etc.)
- **Multi-environment fallback** (for dev/QA only - NOT production)
- Immutable environment switching
- Optional file-based caching with 5-minute TTL
- Robust error handling with specific exception types

**Who uses this:** PHP 8.2+ applications that need runtime feature toggles without code deploys

---

## Architecture & Design

### Component Structure

```
PhlagClient (public API)
    ├─> Client (HTTP wrapper, internal)
    │   └─> GuzzleClient (HTTP library)
    └─> Exception hierarchy
        └─> PhlagException (base)
            ├─> AuthenticationException (401)
            ├─> InvalidFlagException (404 flag)
            ├─> InvalidEnvironmentException (404 env)
            └─> NetworkException (connection/timeout)
```

### Data Flow

**Without caching (single environment):**
1. `PhlagClient::getFlag('name')` → `Client::get('flag/{env}/{name}')`
2. HTTP GET with Bearer token → Phlag API
3. Response body parsed → type-cast to bool/int/float/string
4. Return to caller

**Without caching (multi-environment fallback):**
1. `PhlagClient::getFlag('name')` → loop through environments
2. Try first environment → if null, try next → if null, try next...
3. First non-null value (including false/0/"") stops the chain
4. Return value or null if all environments return null

**With caching (single environment):**
1. First request: `Client::get('all-flags/{env}')` → cache all flags
2. Store in memory + write to disk (JSON file)
3. Subsequent requests: in-memory lookup (zero API calls)
4. Cache expires after TTL → repeat from step 1

**With caching (multi-environment):**
1. First request: Fetch `/all-flags/{env}` for EACH environment
2. Merge results: primary (first) environment values override later ones
3. Store merged result in memory + write to single cache file
4. Subsequent requests: in-memory lookup (zero API calls)
5. Cache expires after TTL → repeat from step 1

### Key Design Patterns

**Multi-Environment Fallback (Dev/QA Only):**
```php
// ✓ Good: Development with fallback
$dev = new PhlagClient($url, $key, ['my-branch', 'staging']);

// ✗ Bad: Production with fallback (defeats explicit config)
$prod = new PhlagClient($url, $key, ['production', 'staging']); // DON'T
```
Only `null` triggers fallback; `false`, `0`, `""` stop the chain.

**Immutability:** `withEnvironment()` returns a new instance instead of mutating state
```php
$prod = new PhlagClient($url, $key, 'production');
$staging = $prod->withEnvironment('staging'); // new instance

// Can also switch to multi-environment
$multi = $prod->withEnvironment(['staging', 'dev']); // new instance
```

**API Change:** `getEnvironment()` now **always returns array** (breaking change)
```php
$client = new PhlagClient($url, $key, 'production');
$envs = $client->getEnvironment(); // ["production"] not "production"
```

**Graceful Degradation:** Cache failures log warnings but don't throw exceptions—client continues without caching

**Type Safety:** All flag values are strongly typed (bool/int/float/string/null); no mixed returns except `getFlag()` which returns different types per flag type

**Single Responsibility:** `PhlagClient` handles business logic + caching; `Client` handles HTTP + error mapping

---

## Coding Standards

This project follows **DealNews PHP conventions** (NOT standard PSR-12 in all areas). Key differences:

### Bracing (1TBS - One True Brace Style)

Opening brace on **same line** as declaration:

```php
// ✓ Correct
public function doesSomething() {
    if ($condition) {
        return $value;
    }
}

// ✗ Wrong (PSR-12 style)
public function doesSomething()
{
    if ($condition)
    {
        return $value;
    }
}
```

**Exception:** Multi-line conditionals put opening brace on new line:
```php
if (
    $really_long_condition &&
    $another_condition &&
    $third_condition
) {
    doSomething();
}
```

### Variable Naming

`snake_case` for variables/properties (NOT camelCase):

```php
// ✓ Correct
protected string $api_key;
protected int $cache_ttl;
$flag_value = $client->getFlag('name');

// ✗ Wrong
protected string $apiKey;
protected int $cacheTtl;
$flagValue = $client->getFlag('name');
```

Methods still use `camelCase`: `getFlag()`, `withEnvironment()`, `clearCache()`

### Visibility

**Default to `protected`** unless truly encapsulated:

```php
// ✓ Preferred
protected Client $client;
protected string $environment;

// ✗ Avoid (unless you have a reason)
private Client $client;
```

Rationale: Makes testing and extension easier without breaking encapsulation

### Single Return Point

Prefer single return at end; early return OK for validation:

```php
// ✓ Preferred
public function computeValue(int $input): ?int {
    $result = null;
    
    // 50 lines of logic
    if ($condition) {
        $result = 42;
    }
    // 50 more lines
    
    return $result;
}

// ✓ Acceptable for early validation
public function getFlag(string $name): mixed {
    if (empty($name)) {
        throw new \InvalidArgumentException('Name required');
    }
    
    $result = null;
    // ... rest of logic
    return $result;
}

// ✗ Avoid (multiple returns in business logic)
public function computeValue(int $input): ?int {
    // ... 50 lines
    if ($condition) {
        return 42;  // ✗ early return in middle of logic
    }
    // ... 50 more lines
    return null;
}
```

### Type Declarations

**Always declare** parameter types and return types:

```php
// ✓ Correct
public function getFlag(string $name): mixed { }
protected function parseValue(string $value): bool|int|float|string { }

// ✗ Wrong (missing types)
public function getFlag($name) { }
```

Union types (PHP 8.0+) are encouraged: `bool|int|float|string`

### PHPDoc Blocks

**All classes** need class-level docblock. **All public methods** should have docblocks:

```php
/**
 * Retrieves a flag value from the configured environment
 *
 * When caching is enabled, this serves from the in-memory cache after
 * the first request. Returns null if the flag doesn't exist or isn't
 * active for the environment.
 *
 * @param string $name The flag name
 *
 * @return bool|int|float|string|null The flag value or null
 *
 * @throws AuthenticationException If API key is invalid
 * @throws NetworkException If network communication fails
 */
public function getFlag(string $name): mixed {
```

**Style:** Conversational, explain *why* not *what*, include "Heads-up:" for gotchas

### Arrays

Short syntax `[]`, align associative arrows, trailing commas on multi-line:

```php
// ✓ Correct
$config = [
    'cache'      => true,
    'cache_ttl'  => 300,
    'timeout'    => 10,
];

// ✗ Wrong
$config = array('cache' => true, 'cache_ttl' => 300);
```

### No Pass-by-Reference

Avoid `&` parameters—return values instead:

```php
// ✗ Wrong
public function modifyFlag(array &$flags): void { }

// ✓ Correct
public function modifyFlag(array $flags): array {
    // ... modify
    return $flags;
}
```

---

## Build & Test

### Commands

```bash
# Install dependencies
composer install

# Run all checks (lint + tests)
composer test

# Just unit tests
composer unit

# Just linting
composer lint

# Auto-fix code style
composer fix
```

### Test Execution

Tests use PHPUnit 11 with no external dependencies. All HTTP calls are mocked via Guzzle's `MockHandler`.

**Fast:** Full suite runs in <1 second (no network I/O)

---

## Common Patterns

### Testing with Mocked HTTP

All tests inject a `MockHandler` via reflection to control responses:

```php
// From PhlagClientTest.php
protected function createClientWithMock(
    MockHandler $mock_handler,
    string $environment = 'production'
): PhlagClient {
    $handlerStack = HandlerStack::create($mock_handler);
    $guzzle = new GuzzleClient(['handler' => $handlerStack]);
    
    $phlag_client = new PhlagClient('http://localhost:8000', 'test-api-key', $environment);
    
    // Inject mocked Guzzle into internal Client via reflection
    $reflection = new ReflectionClass($phlag_client);
    $client_prop = $reflection->getProperty('client');
    $client_prop->setAccessible(true);
    $internal_client = $client_prop->getValue($phlag_client);
    
    // ... inject mock into internal_client->http_client
    
    return $phlag_client;
}
```

**Why reflection?** The `Client` is created internally by `PhlagClient` and isn't exposed. Reflection lets us swap it for tests without polluting the public API.

### Type Casting from API Responses

The Phlag API returns JSON-encoded primitives as strings. We parse them manually:

```php
// From Client.php
protected function parseValue(string $value): bool|int|float|string {
    $result = null;
    
    // Try boolean
    if ($value === 'true' || $value === 'false') {
        $result = ($value === 'true');
    }
    // Try integer
    elseif (ctype_digit($value) || (str_starts_with($value, '-') && ctype_digit(substr($value, 1)))) {
        $result = (int) $value;
    }
    // Try float
    elseif (is_numeric($value)) {
        $result = (float) $value;
    }
    // Default to string
    else {
        $result = $value;
    }
    
    return $result;
}
```

**Heads-up:** Order matters! Check boolean before numeric to avoid treating `'true'` as NaN.

### Cache File Naming

Cache files use MD5 hash of base URL + environment:

```php
// Auto-generated filename
$hash = md5($this->base_url . $this->environment);
$cache_file = sys_get_temp_dir() . '/phlag_cache_' . $hash . '.json';
```

**Why MD5?** Ensures unique cache file per server+environment combo without path/URL encoding issues.

---

## Testing Strategy

### What Tests Verify

**Unit tests** (no integration tests with real Phlag server):

1. **Flag retrieval** - All types (bool/int/float/string) parse correctly
2. **Error mapping** - HTTP status codes → correct exception types
3. **Caching** - File write/read, TTL expiration, cache key generation
4. **Environment switching** - `withEnvironment()` creates independent instances
5. **Convenience methods** - `isEnabled()` returns boolean for non-boolean flags

### Test Organization

```
tests/
├── ClientTest.php              # HTTP client + error handling
├── PhlagClientTest.php         # Main API without caching
└── PhlagClientCacheTest.php    # Caching behavior
```

**Mocking strategy:** All HTTP is mocked; cache tests use real filesystem (in temp dir)

### Code Coverage

Target: 100% line coverage for `src/`. Exclude `vendor/`.

Run with: `phpunit --coverage-html coverage/`

---

## Gotchas & Edge Cases

### 1. Subdirectory Installations

**Problem:** If Phlag is installed at `https://example.com/phlag/`, you must include `/phlag` in `base_url`:

```php
// ✗ Wrong - will 404
new PhlagClient('https://example.com', $key, 'prod');

// ✓ Correct
new PhlagClient('https://example.com/phlag', $key, 'prod');
```

**Why?** Guzzle's `base_uri` uses RFC 3986 rules. A relative path `'flag/prod/feature'` resolves correctly only if `base_uri` includes the subdirectory.

**Implementation:** We store `base_url` without trailing slash, but set Guzzle's `base_uri` WITH trailing slash (`$base_url . '/'`). This makes relative paths work correctly.

### 2. `isEnabled()` Returns False for Everything Except `true`

The convenience method only returns `true` for boolean `true`:

```php
$client->getFlag('feature');  // returns (int) 1
$client->isEnabled('feature'); // returns false (!!)
```

**Rationale:** Avoids accidental truthy conversions. If you want truthy logic, use `getFlag()` with explicit casting.

### 3. Cache Doesn't Store `null` Values

When caching is enabled, fetching a non-existent flag:

1. Checks in-memory cache → miss
2. Checks file cache → miss
3. Returns `null` (doesn't call API)

**Why?** The `/all-flags` endpoint only returns configured flags. If a flag isn't in the cache, it doesn't exist (or isn't active), so we return `null` immediately.

**Exception:** If you `clearCache()`, the next request re-fetches from API.

### 4. Cache Write Failures Are Silent

If the cache file can't be written (permissions, disk full):

- **Behavior:** Client logs a warning and continues without caching
- **No exception thrown**
- **Graceful degradation:** Every request hits the API

**Check cache status:**
```php
$client->warmCache(); // Preload cache
if ($client->isCacheEnabled()) {
    echo "Cache at: " . $client->getCacheFile();
} else {
    echo "Caching disabled";
}
```

### 5. Type Ambiguity for Numeric Strings

The API returns `"123"` for both integer and string flags. We use heuristics:

```php
"123"    → (int) 123
"123.45" → (float) 123.45
"abc123" → (string) "abc123"
"-456"   → (int) -456
```

**Heads-up:** If you have a STRING flag with value `"123"`, it will be cast to `int`. Store as `"v123"` or similar if you need the string type.

### 6. Single Environment per Client Instance ~~(Now Supports Multiple)~~

**Updated:** You can now configure multiple environments with fallback:
```php
$client = new PhlagClient($url, $key, ['staging', 'development']);
```

**Use case:** Development and QA only. NOT recommended for production.

**Heads-up:** Cache settings are preserved, but cache **files** are separate per environment configuration. `['prod', 'staging']` ≠ `['staging', 'prod']` (different order = different hash).

### 7. `getEnvironment()` Always Returns Array (Breaking Change)

Previously returned string for single environment. Now always returns array:
```php
// Old behavior (pre-multi-environment)
$client = new PhlagClient($url, $key, 'production');
$env = $client->getEnvironment(); // "production" (string)

// New behavior (current)
$client = new PhlagClient($url, $key, 'production');
$envs = $client->getEnvironment(); // ["production"] (array)

// Multiple environments
$client = new PhlagClient($url, $key, ['prod', 'staging']);
$envs = $client->getEnvironment(); // ["prod", "staging"] (array)
```

**Migration:** Code expecting `getEnvironment()` to return a string will break. Update to expect array:
```php
// ✗ Old code (will fail)
if ($client->getEnvironment() === 'production') { }

// ✓ New code
if (in_array('production', $client->getEnvironment())) { }
// or
if ($client->getEnvironment()[0] === 'production') { }
```

### 8. Multi-Environment Fallback Logic

When multiple environments configured, only `null` triggers fallback to next environment:
```php
$client = new PhlagClient($url, $key, ['staging', 'development']);

// staging: 'feature' => false (set to false)
// development: 'feature' => true
$result = $client->getFlag('feature'); // returns false (NO fallback, false is valid)

// staging: 'feature' => null (not configured)
// development: 'feature' => true
$result = $client->getFlag('feature'); // returns true (fallback triggered)

// staging: 'feature' => 0 (set to zero)
// development: 'feature' => 100
$result = $client->getFlag('feature'); // returns 0 (NO fallback, 0 is valid)
```

**Performance impact without caching:**
- Single environment: 1 API call per flag
- Multiple environments: Up to N API calls if each returns null

**Performance with caching:**
- Single environment: 1 API call on first request
- Multiple environments: N API calls on first request (one per environment), then merged

---

## Making Changes

### Workflow for Adding Features

1. **Write tests first** (TDD approach)
   - Add test case(s) to appropriate test file
   - Mock HTTP responses for expected behavior
   - Run `composer unit` → watch it fail

2. **Implement feature**
   - Update `PhlagClient.php` or `Client.php`
   - Follow 1TBS bracing, `snake_case` vars, `protected` visibility
   - Add PHPDoc blocks with conversational descriptions

3. **Fix code style**
   - Run `composer fix` to auto-format
   - Manually review for single-return-point pattern

4. **Verify tests pass**
   - Run `composer test` (lint + unit)
   - Check coverage if available

5. **Update README**
   - Add usage example if public API changed
   - Update API Reference section
   - Add troubleshooting section for gotchas

### Workflow for Bug Fixes

1. **Reproduce in test**
   - Add failing test that demonstrates the bug
   - Use `MockHandler` to simulate problematic API response

2. **Fix implementation**
   - Minimum necessary change
   - Don't refactor unrelated code

3. **Verify fix**
   - Run `composer test`
   - Manually test with `test.php` if needed

4. **Document edge case**
   - Add "Heads-up:" note to PHPDoc
   - Update this AGENTS.md if it's a common gotcha

### Adding New Exception Types

If adding a new exception (e.g., `RateLimitException`):

1. Create in `src/Exception/` extending `PhlagException`
2. Throw from `Client::get()` based on HTTP status
3. Document in README's Error Handling section
4. Add test case to `ClientTest.php`

**Convention:** Exception names match HTTP semantics when possible (`AuthenticationException` for 401, `InvalidFlagException` for 404).

### Modifying Caching Behavior

Cache logic lives in `PhlagClient`:
- `warmCache()` - preloads via `/all-flags`
- `clearCache()` - deletes file + in-memory
- `isCacheExpired()` - checks TTL

**Heads-up:** Cache file format is JSON object with flat flag name → value mapping. Don't change format without migration strategy.

---

## Project Dependencies

**Runtime:**
- `guzzlehttp/guzzle` ^7.10 - HTTP client
- PHP 8.2+ (uses typed properties, constructor property promotion)

**Dev:**
- `phpunit/phpunit` ^11 - testing framework
- `friendsofphp/php-cs-fixer` ^3.89 - code style enforcement
- `php-parallel-lint/php-parallel-lint` ^1.4 - syntax checking

**No external services** required for testing (all mocked).

---

## Documentation Style Notes

When writing/updating docs in this project:

- **Voice:** Conversational, crisp, action-oriented
- **Audience:** PHP developers (don't explain `composer install`)
- **Heads-up:** Use for gotchas/warnings (not "Note:" or "Warning:")
- **Code examples:** Show both wrong (✗) and correct (✓) when clarifying
- **Scannable:** Keep bullet lists ≤5 items; use subheadings liberally

**Example:**
```markdown
### Getting Flag Values

Use `getFlag()` to retrieve any flag type:

✓ **Do this:**
$value = $client->getFlag('feature_name');

✗ **Not this:**
$value = $client->get_flag('feature_name'); // wrong method name
```

---

## Quick Reference

### File Layout
```
src/
├── PhlagClient.php           # Main public API
├── Client.php                # HTTP wrapper (internal)
└── Exception/                # Exception hierarchy
    ├── PhlagException.php    # Base exception
    ├── AuthenticationException.php
    ├── InvalidFlagException.php
    ├── InvalidEnvironmentException.php
    └── NetworkException.php

tests/
├── PhlagClientTest.php       # Core functionality
├── PhlagClientCacheTest.php  # Caching tests
└── ClientTest.php            # HTTP client tests
```

### Common Tasks

| Task | Command |
|------|---------|
| Run all tests | `composer test` |
| Fix code style | `composer fix` |
| Run just lint | `composer lint` |
| Run just tests | `composer unit` |

### Coding Checklist

When submitting code, ensure:
- [ ] 1TBS bracing (`{` on same line)
- [ ] `snake_case` variables, `camelCase` methods
- [ ] `protected` visibility (not `private`)
- [ ] Type declarations on all methods
- [ ] PHPDoc blocks on public methods
- [ ] Single return point (except early validation)
- [ ] Tests pass (`composer test`)
- [ ] Code style fixed (`composer fix`)

---

## Getting Help

**For bugs/features:** Open GitHub issue at https://github.com/brianlmoon/phlag-php-client

**For questions:** Contact brian@moonspot.net

**For Phlag server questions:** See https://github.com/brianlmoon/phlag

---

*Last updated: 2026-03-15*
