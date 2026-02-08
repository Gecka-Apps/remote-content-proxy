<?php

declare(strict_types=1);

use App\Support\BoundedStream;

test('stores writes within the limit', function (): void {
    $stream = new BoundedStream(10);

    expect($stream->write('abcd'))->toBe(4);
    expect($stream->write('efghij'))->toBe(6);
    expect($stream->exceeded())->toBeFalse();
    expect((string) $stream)->toBe('abcdefghij');
});

test('refuses the write that crosses the limit', function (): void {
    $stream = new BoundedStream(10);

    $stream->write('abcdefgh');

    expect($stream->write('ijk'))->toBe(0);
    expect($stream->exceeded())->toBeTrue();
    expect((string) $stream)->toBe('abcdefgh');
});

test('stays closed once exceeded', function (): void {
    $stream = new BoundedStream(2);

    $stream->write('abc');

    expect($stream->write('a'))->toBe(0);
    expect((string) $stream)->toBe('');
});

test('reject marks the stream before any write', function (): void {
    $stream = new BoundedStream(100);

    $stream->reject();

    expect($stream->exceeded())->toBeTrue();
    expect($stream->write('a'))->toBe(0);
});
