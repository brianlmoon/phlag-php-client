<?php

namespace Moonspot\PhlagClient\Exception;

use Exception;

/**
 * Base exception for all Phlag client errors
 *
 * All exceptions thrown by the Phlag client library extend from this base
 * exception, making it easy to catch any Phlag-related errors with a single
 * catch block.
 *
 * @package Moonspot\PhlagClient\Exception
 */
class PhlagException extends Exception
{
}
