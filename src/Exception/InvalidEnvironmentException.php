<?php

namespace Moonspot\PhlagClient\Exception;

/**
 * Exception thrown when a requested environment doesn't exist
 *
 * This means the environment name provided doesn't match any environment in
 * the Phlag server. Verify the environment name spelling and ensure the
 * environment has been created in the admin interface.
 *
 * @package Moonspot\PhlagClient\Exception
 */
class InvalidEnvironmentException extends PhlagException
{
}
