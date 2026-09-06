<?php

declare(strict_types=1);

namespace App\Foundation;

use RuntimeException;

final class AssetVerifier
{
    public function __construct(private readonly string $root)
    {
    }

    public function verify(): void
    {
        $manifest = $this->root . '/assets/provenance.json';
        $data = json_decode((string) @file_get_contents($manifest), true);
        if (!is_array($data) || !isset($data['assets']) || !is_array($data['assets'])) {
            throw new RuntimeException('Asset provenance is invalid.');
        }

        foreach ($data['assets'] as $asset) {
            if (!is_array($asset)) {
                throw new RuntimeException('Asset provenance contains an invalid entry.');
            }
            $path = $asset['path'] ?? null;
            $checksum = $asset['sha256'] ?? null;
            if (!is_string($path) || !is_string($checksum)
                || str_contains(str_replace('\\', '/', $path), '../')
                || str_starts_with($path, '/') || str_starts_with($path, '\\')
                || preg_match('/^[A-Za-z]:/', $path) === 1) {
                throw new RuntimeException('Asset provenance contains an unsafe path.');
            }

            $file = $this->root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $path);
            $actual = is_file($file) ? hash_file('sha256', $file) : false;
            if (!is_string($actual) || !hash_equals(strtolower($checksum), $actual)) {
                throw new RuntimeException("Asset verification failed for {$path}.");
            }
        }
    }
}
