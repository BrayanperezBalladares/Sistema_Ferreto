<?php

declare(strict_types=1);

namespace App\Foundation;

final readonly class Renderer
{
    private const TEMPLATES = [
        'page.health' => 'pages/health.php',
        'fragment.health' => 'fragments/health.php',
        'fragment.notification' => 'fragments/notification.php',
        'page.login' => 'pages/login.php',
        'page.products' => 'pages/products.php',
        'fragment.product_table' => 'fragments/product_table.php',
        'page.locations' => 'pages/locations.php',
        'page.inventory' => 'pages/inventory.php',
        'page.counts' => 'pages/counts.php',
        'fragment.count_history' => 'fragments/count_history.php',
        'error' => 'error.php',
    ];

    public function __construct(private string $root)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $name, array $data = []): string
    {
        $file = self::TEMPLATES[$name] ?? throw new \InvalidArgumentException('Template is not allowed.');
        $level = ob_get_level();
        ob_start();
        try {
            require $this->root . '/templates/' . $file;
            return (string) ob_get_clean();
        } catch (\Throwable $exception) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $exception;
        }
    }

    public static function escape(mixed $value): string
    {
        if (is_string($value)) {
            return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        if ($value instanceof \Stringable) {
            return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return '';
    }
}
