<?php

namespace App\Domains\Comms\Jobs\Concerns;

/**
 * CSV formula injection (CWE-1236): a user-supplied cell value, unescaped,
 * becomes a live spreadsheet formula the moment it starts with =, +, -, or
 * @ and an admin opens the file in Excel/Sheets. Extracted from
 * ExportAttendanceRegister (S8 B6, the first CSV export in this app) once
 * S9 B11 added two more exports that needed the exact same guard — a
 * second and third hand-rolled copy would have been the same bug class
 * waiting for someone to forget it on export #4.
 */
trait EscapesCsvFormulas
{
    protected function csvSafe(string $value): string
    {
        return in_array(mb_substr($value, 0, 1), ['=', '+', '-', '@'], true)
            ? "'".$value
            : $value;
    }
}
