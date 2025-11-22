<?php

namespace Moonspot\PhlagClient\Exception;

/**
 * Exception thrown when a requested flag doesn't exist
 *
 * This means the flag name provided doesn't match any flag in the Phlag
 * server. Double-check the flag name spelling and ensure the flag has been
 * created in the admin interface.
 *
 * @package Moonspot\PhlagClient\Exception
 */
class InvalidFlagException extends PhlagException
{
}
