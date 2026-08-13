<?php

namespace App\Integrations\Drivers;

use App\Integrations\DnsResolver;

class FakeDnsResolver implements DnsResolver
{
    /**
     * @var array<string, array<int, string>>
     */
    protected array $records = [];

    /**
     * @param  array<int, string>  $txtRecords
     */
    public function seed(string $domain, array $txtRecords): void
    {
        $this->records[$domain] = $txtRecords;
    }

    public function txtRecords(string $domain): array
    {
        return $this->records[$domain] ?? [];
    }
}
