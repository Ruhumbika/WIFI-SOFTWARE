<?php

namespace App\Services;

use RuntimeException;

class RouterEnvironment
{
    public function write(array $values): void
    {
        $path = app()->environmentPath().DIRECTORY_SEPARATOR.app()->environmentFile();
        $handle = is_file($path) ? fopen($path, 'r+') : false;
        if ($handle === false) {
            throw new RuntimeException('The environment file could not be opened.');
        }

        $temporary = null;
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('The environment file is busy.');
            }
            rewind($handle);
            $content = stream_get_contents($handle);
            if ($content === false) {
                throw new RuntimeException('The environment file could not be read.');
            }

            foreach ($values as $key => $value) {
                if (!in_array($key, [
                    'MIKROTIK_BASE_URL', 'MIKROTIK_USERNAME', 'MIKROTIK_PASSWORD',
                    'MIKROTIK_VERIFY_TLS', 'MIKROTIK_HOTSPOT_SERVER', 'MIKROTIK_ADDRESS_POOL',
                    'MIKROTIK_PROFILE_PREFIX', 'MIKROTIK_VOUCHER_PREFIX',
                ], true)) {
                    throw new RuntimeException('An unsupported environment key was provided.');
                }
                $encoded = '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value).'"';
                $line = $key.'='.$encoded;
                $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
                if (preg_match($pattern, $content)) {
                    $content = preg_replace_callback($pattern, fn () => $line, $content, 1);
                } else {
                    $content = rtrim($content, "\r\n")."\n".$line."\n";
                }
            }

            $temporary = tempnam(dirname($path), '.router-env-');
            if ($temporary === false || file_put_contents($temporary, $content) === false) {
                throw new RuntimeException('The environment file could not be written.');
            }
            chmod($temporary, 0600);
            if (!rename($temporary, $path)) {
                throw new RuntimeException('The environment file could not be replaced.');
            }
            $temporary = null;
        } finally {
            if ($temporary && is_file($temporary)) unlink($temporary);
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
