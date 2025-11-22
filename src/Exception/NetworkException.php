<?php

namespace Moonspot\PhlagClient\Exception;

/**
 * Exception thrown when network communication fails
 *
 * This covers connection failures, timeouts, DNS resolution errors, and other
 * network-level problems. It wraps Guzzle's request exceptions to provide a
 * consistent interface for handling network errors.
 *
 * @package Moonspot\PhlagClient\Exception
 */
class NetworkException extends PhlagException
{
}
