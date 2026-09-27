<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Exceptions;

use RuntimeException;

final class CompanySignerWalletException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
