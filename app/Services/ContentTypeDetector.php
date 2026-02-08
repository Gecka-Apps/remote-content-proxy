<?php

declare(strict_types=1);

/**
 * @author Laurent Dinclaux <laurent@gecka.nc>
 * @copyright 2026 Gecka
 * @license AGPL-3.0-or-later
 */

namespace App\Services;

/**
 * Content type detection service using finfo and magic byte signatures.
 *
 * Detects MIME types of binary content using PHP's finfo extension as the
 * primary detection method, with a comprehensive magic-byte fallback covering
 * images, fonts, CSS, and video formats.
 *
 * @author Laurent Dinclaux <laurent@gecka.nc>
 * @copyright 2026 Gecka
 * @license AGPL-3.0-or-later
 */
class ContentTypeDetector
{
    /** @var \finfo|null The finfo instance for MIME type detection */
    private ?\finfo $finfo = null;

    /**
     * Initialize the content type detector.
     *
     * Creates an finfo instance for MIME type detection. Falls back to
     * magic byte detection only if finfo initialization fails.
     */
    public function __construct()
    {
        // Initialize finfo for MIME type detection
        // In PHP 8.2+, finfo_open() returns an \finfo object (not a resource)
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $this->finfo = $finfo;
        } else {
            // finfo initialization failed, will rely on magic bytes detection only
            $this->finfo = null;
        }
    }

    /**
     * Parse content type from an HTTP Content-Type header value.
     *
     * Extracts the MIME type portion, stripping any charset or boundary
     * parameters that follow the semicolon.
     *
     * @param mixed $contentType The raw Content-Type header value
     * @return string The lowercase MIME type, or empty string if unavailable
     */
    public function parseContentType(mixed $contentType): string
    {
        if ($contentType === null || $contentType === false) {
            return '';
        }

        $parts = explode(';', (string) $contentType);
        return strtolower(trim($parts[0]));
    }

    /**
     * Detect content type from file content.
     *
     * Uses finfo_buffer for robust detection with fallback to magic bytes.
     *
     * @param string $content The binary content to analyze
     * @return string|null The detected MIME type, or null if detection failed
     */
    public function detectContentType(string $content): ?string
    {
        // Try finfo first (most reliable)
        if ($this->finfo !== null) {
            $detected = @finfo_buffer($this->finfo, $content);
            if ($detected !== false && !empty($detected)) {
                // Normalize some common MIME types
                $normalized = $this->normalizeMimeType($detected);
                if ($normalized !== null) {
                    return $normalized;
                }
            }
        }

        // Fallback to magic bytes for types that finfo might not detect well
        return $this->detectByMagicBytes($content);
    }

    /**
     * Normalize MIME types that finfo might return in non-standard format.
     *
     * Maps legacy or non-standard MIME type strings (e.g. application/x-font-*)
     * to their modern equivalents.
     *
     * @param string $mimeType The raw MIME type from finfo
     * @return string|null The normalized MIME type, or the original if no mapping exists
     */
    private function normalizeMimeType(string $mimeType): ?string
    {
        // finfo sometimes returns these variations
        $normalizations = [
            'application/x-font-ttf' => 'font/ttf',
            'application/x-font-otf' => 'font/otf',
            'application/x-font-woff' => 'font/woff',
            'application/x-font-woff2' => 'font/woff2',
            'application/vnd.ms-opentype' => 'font/otf',
            'application/ogg' => 'video/ogg',
        ];

        if (isset($normalizations[$mimeType])) {
            return $normalizations[$mimeType];
        }

        // Types that finfo misidentifies — fall through to magic bytes
        $genericTypes = [
            'application/octet-stream',
            'text/plain',
            'application/x-empty',
            'image/x-tga', // finfo confuses ICO (00 00 01 00) with TGA
        ];

        if (in_array($mimeType, $genericTypes, true)) {
            return null;
        }

        return $mimeType;
    }

    /**
     * Detect content type from file signatures (magic bytes).
     *
     * Used as fallback when finfo doesn't give good results. Supports
     * images (JPEG, PNG, GIF, WebP, AVIF, ICO, BMP, SVG), fonts
     * (WOFF, WOFF2, TTF, OTF, EOT), CSS, and video formats
     * (MP4, QuickTime, WebM, Matroska, Ogg, AVI).
     *
     * @param string $content The binary content to analyze
     * @return string|null The detected MIME type, or null if no signature matched
     */
    private function detectByMagicBytes(string $content): ?string
    {
        if (empty($content)) {
            return null;
        }

        // Image signatures
        $imageSignatures = [
            "\xFF\xD8\xFF" => 'image/jpeg',
            "\x89PNG\r\n\x1A\n" => 'image/png',
            "GIF87a" => 'image/gif',
            "GIF89a" => 'image/gif',
            "RIFF" => 'image/webp', // Will verify WEBP marker below
            "\x00\x00\x01\x00" => 'image/x-icon',
            "\x00\x00\x02\x00" => 'image/x-icon',
            "BM" => 'image/bmp',
        ];

        foreach ($imageSignatures as $signature => $type) {
            if (str_starts_with($content, $signature)) {
                if ($type === 'image/webp' && strlen($content) >= 12) {
                    if (substr($content, 8, 4) !== 'WEBP') {
                        continue;
                    }
                }
                return $type;
            }
        }

        // AVIF detection (based on ftyp box)
        if (strlen($content) >= 12) {
            $ftyp = substr($content, 4, 4);
            if ($ftyp === 'ftyp') {
                $brand = substr($content, 8, 4);
                if (in_array($brand, ['avif', 'avis', 'mif1'], true)) {
                    return 'image/avif';
                }
            }
        }

        // SVG detection — require <svg to appear near the start with valid XML preamble
        $svgPrefix = substr($content, 0, 1024);
        if (preg_match('/^\s*(<\?xml[^?]*\?\>\s*)?(<!\s*DOCTYPE[^>]*>\s*)?(<!--[\s\S]*?-->\s*)*<svg[\s>]/i', $svgPrefix)) {
            return 'image/svg+xml';
        }

        // Font signatures
        if (str_starts_with($content, 'wOFF')) {
            return 'font/woff';
        }

        if (str_starts_with($content, 'wOF2')) {
            return 'font/woff2';
        }

        if (strlen($content) >= 4) {
            $header = substr($content, 0, 4);
            // TrueType
            if ($header === "\x00\x01\x00\x00") {
                return 'font/ttf';
            }
            // OpenType with CFF
            if ($header === "OTTO") {
                return 'font/otf';
            }
        }

        // EOT (Embedded OpenType)
        if (strlen($content) >= 36) {
            $eotMagic = substr($content, 34, 2);
            if ($eotMagic === "LP") {
                return 'application/vnd.ms-fontobject';
            }
        }

        // Video signatures (must be checked before CSS to avoid false positives)
        if (strlen($content) >= 12) {
            // MP4 (ftyp box)
            if (substr($content, 4, 4) === 'ftyp') {
                $brand = substr($content, 8, 4);
                // Common MP4 brands
                if (in_array($brand, ['isom', 'iso2', 'iso3', 'iso4', 'iso5', 'iso6', 'mp41', 'mp42', 'avc1', 'M4V ', 'M4A '], true)) {
                    return 'video/mp4';
                }
                // QuickTime
                if (in_array($brand, ['qt  ', 'qtif'], true)) {
                    return 'video/quicktime';
                }
            }
        }

        // WebM (EBML signature)
        if (str_starts_with($content, "\x1A\x45\xDF\xA3")) {
            // Check for WebM doctype
            if (strlen($content) >= 40 && str_contains(substr($content, 0, 40), 'webm')) {
                return 'video/webm';
            }
            // Matroska (same signature but different doctype)
            if (strlen($content) >= 40 && str_contains(substr($content, 0, 40), 'matroska')) {
                return 'video/x-matroska';
            }
        }

        // Ogg (for video/ogg - Theora video)
        if (str_starts_with($content, 'OggS')) {
            // This could be audio or video, but we'll classify as video/ogg
            // More precise detection would require parsing Ogg pages
            return 'video/ogg';
        }

        // AVI (RIFF with AVI subtype)
        if (str_starts_with($content, 'RIFF') && strlen($content) >= 12) {
            $aviType = substr($content, 8, 4);
            if ($aviType === 'AVI ' || $aviType === 'AVIX') {
                return 'video/x-msvideo';
            }
        }

        // CSS detection (text-based — must be last as the regex is generic)
        $trimmedContent = ltrim($content);
        if (preg_match('/^(@charset|@import|@font-face|@media|@keyframes|[a-z\-\.\#\*\[\:][^{]*\{)/i', $trimmedContent)) {
            return 'text/css';
        }

        return null;
    }
}
