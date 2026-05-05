<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

class HttpException extends RuntimeException
{
    public int $status;
    public string $errorCode;
    public array $details;

    public function __construct(int $status, string $errorCode, string $message = '', array $details = [])
    {
        parent::__construct($message ?: $errorCode, $status);
        $this->status    = $status;
        $this->errorCode = $errorCode;
        $this->details   = $details;
    }
}
