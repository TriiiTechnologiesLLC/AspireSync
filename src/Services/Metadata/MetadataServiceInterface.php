<?php

declare(strict_types=1);

namespace App\Services\Metadata;

use Generator;

interface MetadataServiceInterface
{
    /**
     * @param string[] $versions
     * @return string[]
     */
    public function getUnprocessedVersions(string $slug, array $versions): array;

    public function getDownloadUrl(string $slug, string $version): ?string;

    public function exportAllMetadata(): Generator;

    /** @param array<string, mixed> $metadata */
    public function save(array $metadata): void;

    /** @return array<string|int, string[]> */
    public function getOpenVersions(string $revDate = '1900-01-01'): array;

    public function markProcessed(string $slug, string $version): void;

    /** @return array<string,int> */
    public function getPulledAfter(int $timestamp): array;

    /** @return array<string|int, array{}> */
    public function getAllSlugs(): array;
}
