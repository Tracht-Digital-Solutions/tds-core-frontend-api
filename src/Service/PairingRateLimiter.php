<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Service;

/** Small file-backed limiter; no database lookup is needed for rejected tokens. */
final class PairingRateLimiter
{
    public function __construct(private readonly string $directory)
    {
    }

    public function allow(string $key, int $limit, int $windowSeconds, ?int $now = null): bool
    {
        $now ??= time();
        if ($limit < 1 || $windowSeconds < 1) {
            return false;
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            // Availability wins if a read-only host cannot create the limiter.
            return true;
        }
        $path = rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
        $file = @fopen($path, 'c+');
        if ($file === false) {
            return true;
        }
        try {
            if (!flock($file, LOCK_EX)) {
                return true;
            }
            rewind($file);
            $raw = stream_get_contents($file);
            $timestamps = json_decode(is_string($raw) ? $raw : '', true);
            $timestamps = is_array($timestamps) ? array_values(array_filter(
                array_map('intval', $timestamps),
                static fn (int $timestamp): bool => $timestamp > $now - $windowSeconds,
            )) : [];
            if (count($timestamps) >= $limit) {
                return false;
            }
            $timestamps[] = $now;
            ftruncate($file, 0);
            rewind($file);
            fwrite($file, json_encode($timestamps, JSON_THROW_ON_ERROR));
            fflush($file);
            @chmod($path, 0600);
            return true;
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }
}
