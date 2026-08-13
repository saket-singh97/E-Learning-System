<?php

namespace App\Integrations;

interface DnsResolver
{
    /**
     * @return array<int, string>
     */
    public function txtRecords(string $domain): array;
}
