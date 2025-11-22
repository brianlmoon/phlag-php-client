<?php

namespace Moonspot\PhlagClient;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Moonspot\PhlagClient\Exception\AuthenticationException;
use Moonspot\PhlagClient\Exception\InvalidEnvironmentException;
use Moonspot\PhlagClient\Exception\InvalidFlagException;
use Moonspot\PhlagClient\Exception\NetworkException;
use Moonspot\PhlagClient\Exception\PhlagException;

/**
 * HTTP client wrapper for communicating with the Phlag API
 *
 * This class wraps the Guzzle HTTP client and handles authentication, error
 * handling, and response parsing. It's used internally by PhlagClient and
 * shouldn't be used directly by application code.
 *
 * @package Moonspot\PhlagClient
 */
class Client
{
    /**
     * @var GuzzleClient The underlying Guzzle HTTP client
     */
    protected GuzzleClient $http_client;

    /**
     * @var string The API key for authentication
     */
    protected string $api_key;

    /**
     * @var string The base URL of the Phlag server
     */
    protected string $base_url;

    /**
     * Creates a new HTTP client for the Phlag API
     *
     * @param string $base_url The base URL of the Phlag server (e.g., http://localhost:8000)
     * @param string $api_key  The 64-character API key for authentication
     */
    public function __construct(string $base_url, string $api_key)
    {
        $this->base_url = rtrim($base_url, '/');
        $this->api_key  = $api_key;

        $this->http_client = new GuzzleClient([
            'base_uri' => $this->base_url,
            'timeout'  => 10,
            'headers'  => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Accept'        => 'application/json',
            ],
        ]);
    }

    /**
     * Sends a GET request to the Phlag API
     *
     * This method handles authentication, error handling, and JSON parsing.
     * It throws specific exceptions for different error conditions to make
     * error handling easier for calling code.
     *
     * @param string $endpoint The API endpoint path (e.g., /flag/production/feature_name)
     *
     * @return mixed The decoded JSON response
     *
     * @throws AuthenticationException      When the API key is invalid (401)
     * @throws InvalidFlagException         When a flag doesn't exist (404 on /flag endpoint)
     * @throws InvalidEnvironmentException  When an environment doesn't exist (404 on environment endpoints)
     * @throws NetworkException             When network communication fails
     * @throws PhlagException               For other HTTP errors
     */
    public function get(string $endpoint): mixed
    {
        $return = null;

        try {
            $response = $this->http_client->get($endpoint);
            $body     = $response->getBody()->getContents();
            $return   = json_decode($body, true);

            // Handle scalar responses (from /flag endpoint)
            if (json_last_error() !== JSON_ERROR_NONE) {
                // Try decoding without associative flag for scalar values
                $return = json_decode($body);
            }
        } catch (ClientException $e) {
            $status_code = $e->getResponse()->getStatusCode();

            if ($status_code === 401) {
                throw new AuthenticationException(
                    'Invalid API key',
                    1,
                    $e
                );
            }

            if ($status_code === 404) {
                // Determine if this is a flag or environment error based on the endpoint
                if (str_contains($endpoint, '/flag/')) {
                    throw new InvalidFlagException(
                        'Flag not found: ' . $endpoint,
                        2,
                        $e
                    );
                }

                throw new InvalidEnvironmentException(
                    'Environment not found: ' . $endpoint,
                    3,
                    $e
                );
            }

            throw new PhlagException(
                'HTTP error: ' . $e->getMessage(),
                4,
                $e
            );
        } catch (ConnectException $e) {
            throw new NetworkException(
                'Network error: ' . $e->getMessage(),
                5,
                $e
            );
        } catch (RequestException $e) {
            throw new NetworkException(
                'Request error: ' . $e->getMessage(),
                6,
                $e
            );
        }

        return $return;
    }
}
