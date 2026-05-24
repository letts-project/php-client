<?php
declare(strict_types=1);

namespace Letts\Exceptions;

/**
 * Client-side wait deadline (run(waitTimeout:)) elapsed before the mission
 * reached a terminal event. The mission itself keeps running on the daemon —
 * this is "stopped waiting", not "mission failed".
 */
final class WaitTimeoutException extends LettsException
{
}
