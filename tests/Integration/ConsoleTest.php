<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Foundation\Console;
use App\Foundation\AssetVerifier;
use PHPUnit\Framework\TestCase;

final class ConsoleTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/Sistema Ferreto Console ' . bin2hex(random_bytes(4));
        mkdir($this->root . '/public/assets', 0777, true);
        mkdir($this->root . '/assets');
        file_put_contents($this->root . '/composer.json', '{}');
        file_put_contents($this->root . '/public/assets/probe.txt', 'verified');
        file_put_contents($this->root . '/assets/provenance.json', json_encode([
            'assets' => [[
                'path' => 'public/assets/probe.txt',
                'sha256' => hash('sha256', 'verified'),
            ]],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function testUnknownCommandHasNoEffects(): void
    {
        $marker = $this->root . '/effect.txt';

        self::assertSame(64, (new Console($this->root))->run(['unknown', $marker]));
        self::assertFileDoesNotExist($marker);
    }

    public function testMetacharactersAreNotExecuted(): void
    {
        $marker = $this->root . '/effect.txt';
        $argument = 'unknown;Set-Content ' . $marker;

        self::assertSame(64, (new Console($this->root))->run([$argument]));
        self::assertFileDoesNotExist($marker);
    }

    public function testAssetVerificationRunsFromAWindowsPathContainingSpaces(): void
    {
        self::assertSame(0, (new Console($this->root))->run(['verify-assets']));
    }

    public function testRootWithoutProjectMarkerIsRejected(): void
    {
        self::expectException(\InvalidArgumentException::class);

        new Console(dirname($this->root));
    }

    public function testChangedAssetIsRejectedWithoutSubstitution(): void
    {
        file_put_contents($this->root . '/public/assets/probe.txt', 'changed');
        self::expectException(\RuntimeException::class);

        (new AssetVerifier($this->root))->verify();
    }
}
