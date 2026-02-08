<?php

declare(strict_types=1);

pest()->extend(Tests\TestCase::class)->in('Feature', 'Unit');

/**
 * Encode a URL the way the proxy expects it in the path: URL-safe Base64, no padding.
 */
function base64url(string $url): string
{
    return rtrim(strtr(base64_encode($url), '+/', '-_'), '=');
}

/**
 * Remove a flat temporary directory and its files, ignoring what is already gone.
 */
function removeDirectory(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    foreach (glob($path . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }

    @rmdir($path);
}
