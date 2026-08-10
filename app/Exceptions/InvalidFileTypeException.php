<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidFileTypeException extends RuntimeException
{
    /**
     * @param  array<int, string>  $allowed
     */
    public function __construct(
        public readonly string $field,
        public readonly array $allowed,
        string $extension,
    ) {
        parent::__construct(
            "The {$field} must be one of [".implode(', ', $allowed)."], got [{$extension}]."
        );
    }

    /**
     * @param  array<int, string>  $allowed
     */
    public static function forField(string $field, string $extension, array $allowed): self
    {
        return new self($field, $allowed, $extension);
    }
}
