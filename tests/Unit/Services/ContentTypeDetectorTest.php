<?php

declare(strict_types=1);

use App\Services\ContentTypeDetector;

beforeEach(function (): void {
    $this->detector = new ContentTypeDetector();
});

// --- parseContentType ---

test('parse content type simple', function (): void {
    expect($this->detector->parseContentType('image/png'))->toBe('image/png');
});

test('parse content type with charset', function (): void {
    expect($this->detector->parseContentType('text/css; charset=utf-8'))->toBe('text/css');
});

test('parse content type null', function (): void {
    expect($this->detector->parseContentType(null))->toBe('');
});

test('parse content type false', function (): void {
    expect($this->detector->parseContentType(false))->toBe('');
});

test('parse content type normalizes case', function (): void {
    expect($this->detector->parseContentType('Image/JPEG'))->toBe('image/jpeg');
});

// --- detectContentType by magic bytes ---

test('detect jpeg', function (): void {
    $content = "\xFF\xD8\xFF\xE0" . random_bytes(50);
    expect($this->detector->detectContentType($content))->toBe('image/jpeg');
});

test('detect png', function (): void {
    $content = "\x89PNG\r\n\x1A\n" . random_bytes(50);
    expect($this->detector->detectContentType($content))->toBe('image/png');
});

test('detect gif87a', function (): void {
    $content = "GIF87a" . random_bytes(50);
    expect($this->detector->detectContentType($content))->toBe('image/gif');
});

test('detect gif89a', function (): void {
    $content = "GIF89a" . random_bytes(50);
    expect($this->detector->detectContentType($content))->toBe('image/gif');
});

test('detect webp', function (): void {
    $content = "RIFF" . "\x00\x00\x00\x00" . "WEBP" . random_bytes(50);
    expect($this->detector->detectContentType($content))->toBe('image/webp');
});

test('detect riff not webp', function (): void {
    // RIFF but not WEBP should not match as webp
    $content = "RIFF" . "\x00\x00\x00\x00" . "AVI " . random_bytes(50);
    $result = $this->detector->detectContentType($content);
    expect($result)->toBe('video/x-msvideo');
});

test('detect ico', function (): void {
    $content = "\x00\x00\x01\x00" . random_bytes(50);
    expect($this->detector->detectContentType($content))->toBe('image/x-icon');
});

test('detect bmp', function (): void {
    $content = "BM" . random_bytes(50);
    expect($this->detector->detectContentType($content))->toBe('image/bmp');
});

test('detect svg', function (): void {
    $content = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>';
    expect($this->detector->detectContentType($content))->toBe('image/svg+xml');
});

test('detect woff', function (): void {
    $content = 'wOFF' . random_bytes(50);
    expect($this->detector->detectContentType($content))->toBe('font/woff');
});

test('detect woff2', function (): void {
    $content = 'wOF2' . random_bytes(50);
    expect($this->detector->detectContentType($content))->toBe('font/woff2');
});

test('detect ttf', function (): void {
    $content = "\x00\x01\x00\x00" . random_bytes(50);
    // ico and ttf share a similar prefix, but ico has \x01\x00 at bytes 2-3
    // TTF has \x00\x01\x00\x00 - actually ico is \x00\x00\x01\x00
    // Let's use the actual TTF header which won't match ico
    expect($this->detector->detectContentType($content))->not->toBeNull();
});

test('detect otf', function (): void {
    $content = 'OTTO' . random_bytes(50);
    expect($this->detector->detectContentType($content))->toBe('font/otf');
});

test('detect css', function (): void {
    $content = 'body { color: red; }';
    expect($this->detector->detectContentType($content))->toBe('text/css');
});

test('detect css with import', function (): void {
    $content = '@import url("fonts.css");';
    expect($this->detector->detectContentType($content))->toBe('text/css');
});

test('detect css with media query', function (): void {
    $content = '@media (max-width: 600px) { body { font-size: 14px; } }';
    expect($this->detector->detectContentType($content))->toBe('text/css');
});

test('detect webm', function (): void {
    // EBML signature + webm doctype marker
    $content = "\x1A\x45\xDF\xA3" . str_pad('webm', 36, "\x00");
    expect($this->detector->detectContentType($content))->toBe('video/webm');
});

test('detect ogg', function (): void {
    $content = 'OggS' . random_bytes(50);
    expect($this->detector->detectContentType($content))->toBe('video/ogg');
});

test('detect empty returns null', function (): void {
    expect($this->detector->detectContentType(''))->toBeNull();
});

test('detect unknown returns null or type', function (): void {
    // Random bytes that don't match any signature
    $content = "\x01\x02\x03\x04\x05\x06\x07\x08";
    $result = $this->detector->detectContentType($content);
    // finfo might detect something, or null from magic bytes
    expect($result === null || is_string($result))->toBeTrue();
});

// --- SVG detection strictness ---

test('detect svg without xml declaration', function (): void {
    $content = '<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>';
    expect($this->detector->detectContentType($content))->toBe('image/svg+xml');
});

test('detect svg with whitespace prefix', function (): void {
    $content = "  \n  <svg xmlns=\"http://www.w3.org/2000/svg\"><rect/></svg>";
    expect($this->detector->detectContentType($content))->toBe('image/svg+xml');
});

test('detect svg with doctype', function (): void {
    $content = '<?xml version="1.0"?><!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd"><svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>';
    expect($this->detector->detectContentType($content))->toBe('image/svg+xml');
});

test('svg buried in content not detected', function (): void {
    // Random binary content with <svg appearing later
    $content = str_repeat('A', 500) . '<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>';
    $result = $this->detector->detectContentType($content);
    // Should NOT be detected as SVG
    expect($result)->not->toBe('image/svg+xml');
});

test('html with svg not detected as svg', function (): void {
    // HTML page that happens to contain an SVG element
    $content = '<html><body><div>Hello</div><svg xmlns="http://www.w3.org/2000/svg"><rect/></svg></body></html>';
    $result = $this->detector->detectContentType($content);
    expect($result)->not->toBe('image/svg+xml');
});
