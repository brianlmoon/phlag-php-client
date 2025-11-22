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
 *     environment: 'production'
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
class PhlagClient
{
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
     * Creates a new Phlag client for a specific environment
     *
     * The environment is set at construction time and all flag requests will
     * use this environment. To query a different environment, create a new
     * instance or use the withEnvironment() method.
     *
     * @param string $base_url    The base URL of the Phlag server (e.g., http://localhost:8000)
     * @param string $api_key     The 64-character API key for authentication
     * @param string $environment The environment name (e.g., production, staging, development)
     */
    public function __construct(string $base_url, string $api_key, string $environment)
    {
        $this->base_url    = $base_url;
        $this->api_key     = $api_key;
        $this->environment = $environment;
        $this->client      = new Client($base_url, $api_key);
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
     * @param string $name The flag name
     *
     * @return mixed The flag value (bool, int, float, string, or null)
     *
     * @throws Exception\AuthenticationException      When the API key is invalid
     * @throws Exception\InvalidFlagException         When the flag doesn't exist
     * @throws Exception\InvalidEnvironmentException  When the environment doesn't exist
     * @throws Exception\NetworkException             When network communication fails
     * @throws Exception\PhlagException               For other errors
     */
    public function getFlag(string $name): mixed
    {
        $return = null;

        $endpoint = sprintf('flag/%s/%s', $this->environment, $name);
        $return   = $this->client->get($endpoint);

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
    public function isEnabled(string $name): bool
    {
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
    public function getEnvironment(): string
    {
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
     * The original client instance is not modified (immutable pattern).
     *
     * @param string $environment The new environment name
     *
     * @return self A new PhlagClient instance for the specified environment
     */
    public function withEnvironment(string $environment): self
    {
        $return = new self(
            $this->base_url,
            $this->api_key,
            $environment
        );

        return $return;
    }
}
