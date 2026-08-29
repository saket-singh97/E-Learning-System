<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidPaymentHistoryImportFileException extends RuntimeException
{
    public static function badHeaders(): self
    {
        return new self('The CSV must have a header row with exactly these columns: email, batch, invoice_number, amount, gst_amount, total_amount, paid_at.');
    }

    public static function empty(): self
    {
        return new self('The CSV has no data rows.');
    }
}
