<?php

namespace Reservepay\Payment\Model\Api;

/**
 * A failed Reservepay call. errorCode is the error triple code (NOT_FOUND, CONFLICT, ...) or one of the local codes below.
 */
class ApiException extends \RuntimeException
{
    public const TRANSPORT = 'TRANSPORT';
    public const BAD_RESPONSE = 'BAD_RESPONSE';
    public const CONFIG = 'CONFIG';

    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
