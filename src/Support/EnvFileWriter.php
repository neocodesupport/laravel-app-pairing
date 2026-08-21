<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Support;

/**
 * Reads and rewrites a single key in the application's .env file, mirroring
 * the approach Laravel's own `key:generate` command uses for APP_KEY.
 */
class EnvFileWriter
{
    public function __construct(private readonly string $envPath) {}

    public function get(string $key): ?string
    {
        if (! is_file($this->envPath)) {
            return null;
        }

        $contents = file_get_contents($this->envPath);

        if ($contents === false) {
            return null;
        }

        if (! preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $contents, $matches)) {
            return null;
        }

        $value = trim($matches[1]);

        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
            $value = substr($value, 1, -1);
        }

        return $value;
    }

    public function set(string $key, string $value): void
    {
        $escaped = str_contains($value, ' ') || $value === '' ? '"'.$value.'"' : $value;

        $contents = is_file($this->envPath) ? (file_get_contents($this->envPath) ?: '') : '';

        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

        if (preg_match($pattern, $contents)) {
            $contents = preg_replace($pattern, "{$key}={$escaped}", $contents, 1) ?? $contents;
        } else {
            $contents = rtrim($contents, "\n")."\n{$key}={$escaped}\n";
        }

        file_put_contents($this->envPath, $contents);
    }
}
