<?php

namespace Reservepay\Payment\Model\Api;

/**
 * A failed Reservepay call. errorCode is the error triple code (NOT_FOUND, CONFLICT, ...) or one of the local codes below.
 * notProcessed is true only when Reservepay provably did not act on the request (Client::notProcessed()).
 */
class ApiException extends \RuntimeException
{
    public const TRANSPORT = 'TRANSPORT';
    public const BAD_RESPONSE = 'BAD_RESPONSE';
    public const CONFIG = 'CONFIG';

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly bool $notProcessed = false
    ) {
        parent::__construct($message);
    }
}
