<?php

namespace App\Glimpse;

final class Config
{
    private const DEFAULT_API_URL = 'https://glimpseimg.com/api';

    public function token(): ?string
    {
        $env = getenv('GLIMPSE_TOKEN');

        if ($env !== false && $env !== '') {
            return $env;
        }

        $token = $this->read()['token'] ?? null;

        return is_string($token) ? $token : null;
    }

    public function apiUrl(): string
    {
        $env = getenv('GLIMPSE_API_URL');

        if ($env !== false && $env !== '') {
            return rtrim($env, '/');
        }

        $url = $this->read()['api_url'] ?? null;

        return rtrim(is_string($url) ? $url : self::DEFAULT_API_URL, '/');
    }

    public function setToken(?string $token): void
    {
        $this->write(['token' => $token] + $this->read());
    }

    public function path(): string
    {
        $base = getenv('XDG_CONFIG_HOME');

        if ($base === false || $base === '') {
            $base = (getenv('HOME') ?: '').'/.config';
        }

        return $base.'/glimpse/config.json';
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        if (! is_file($this->path())) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($this->path()), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function write(array $data): void
    {
        $dir = dirname($this->path());

        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $data = array_filter($data, fn ($value) => $value !== null);

        file_put_contents($this->path(), json_encode($data, JSON_PRETTY_PRINT).PHP_EOL);
        chmod($this->path(), 0600);
    }
}
