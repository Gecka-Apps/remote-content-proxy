<?php

declare(strict_types=1);

/**
 * @author Laurent Dinclaux <laurent@gecka.nc>
 * @copyright 2026 Gecka
 * @license AGPL-3.0-or-later
 */

namespace App\Support;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/**
 * In-memory response body that refuses to grow past a byte limit.
 *
 * Handed to the HTTP client as the download sink: when a write would
 * cross the limit, nothing is stored and the write reports zero bytes,
 * which makes cURL abort the transfer at once (CURLE_WRITE_ERROR).
 * Compressed responses are counted after decompression, since cURL
 * inflates them before calling the write function.
 *
 * @author Laurent Dinclaux <laurent@gecka.nc>
 * @copyright 2026 Gecka
 * @license AGPL-3.0-or-later
 */
final class BoundedStream implements StreamInterface
{
    use StreamDecoratorTrait;

    /** @var StreamInterface Backing store, created by the trait on first access */
    private $stream;

    private bool $exceeded = false;

    private int $written = 0;

    /**
     * @param int $limit Maximum number of bytes accepted
     */
    public function __construct(private int $limit)
    {
        // An unset property is what routes the first access through __get()
        unset($this->stream);
    }

    /**
     * Create the backing php://temp stream on first access.
     *
     * @return StreamInterface
     */
    protected function createStream(): StreamInterface
    {
        return Utils::streamFor(Utils::tryFopen('php://temp', 'w+'));
    }

    /**
     * Append data unless it would push the body past the limit.
     *
     * @param string $string The bytes to store
     * @return int Bytes stored, zero once the limit is hit
     */
    public function write($string): int
    {
        if ($this->exceeded || $this->written + strlen($string) > $this->limit) {
            $this->exceeded = true;

            return 0;
        }

        $written = $this->stream->write($string);
        $this->written += $written;

        return $written;
    }

    /**
     * Mark the body as too large before any byte arrives, when the
     * announced Content-Length already crosses the limit.
     *
     * @return void
     */
    public function reject(): void
    {
        $this->exceeded = true;
    }

    /**
     * Whether a write was refused because of the limit.
     *
     * @return bool
     */
    public function exceeded(): bool
    {
        return $this->exceeded;
    }

    /**
     * The byte limit this stream enforces.
     *
     * @return int
     */
    public function limit(): int
    {
        return $this->limit;
    }
}
