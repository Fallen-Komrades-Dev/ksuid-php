<?php

declare(strict_types=1);

namespace FallenKomradesDev\KSUID\Exception;

/**
 * Thrown by MacPartitioner when no suitable (non-loopback, hardware-
 * addressed) network interface can be found on the current system.
 *
 * Mirrors Go's ErrNoNetworkInterface sentinel error.
 */
final class NoNetworkInterfaceException extends \RuntimeException
{
    public function __construct(string $message = 'No network interface with hardware address found')
    {
        parent::__construct($message);
    }
}
