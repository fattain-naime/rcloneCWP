<?php
/**
 * rcloneCWP CLI Exception
 *
 * Thrown by the CLI engine to signal a fatal, user-facing error. The
 * CLI::main() entry point catches it, renders the message to STDERR (plus
 * a JSON envelope when --json is set), and returns a clean non-zero exit
 * code instead of terminating the PHP process outright.
 *
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP
 */

namespace CWP\RcloneCWP;

use RuntimeException;

class CLIException extends RuntimeException
{
}
