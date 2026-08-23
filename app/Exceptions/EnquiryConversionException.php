<?php

namespace App\Exceptions;

use RuntimeException;

class EnquiryConversionException extends RuntimeException
{
    public static function alreadyConverted(int $enquiryId): self
    {
        return new self("Enquiry [{$enquiryId}] has already been converted.");
    }

    public static function emailUnderDifferentTenant(string $email): self
    {
        return new self("Email [{$email}] is already registered under a different institute.");
    }
}
