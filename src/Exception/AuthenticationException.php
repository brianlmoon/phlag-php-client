<?php

namespace Moonspot\PhlagClient\Exception;

/**
 * Exception thrown when API authentication fails
 *
 * This typically means the API key is invalid, expired, or missing from the
 * request. Check that you're using the correct API key and that it hasn't
 * been deleted from the Phlag server.
 *
 * @package Moonspot\PhlagClient\Exception
 */
class AuthenticationException extends PhlagException {
}
