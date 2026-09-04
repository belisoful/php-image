<?php

/**
 * SourceRange class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\Stream;

/**
 * SourceRange class.
 *
 * A deferred byte payload: a `[offset, length]` window into a still-open, seekable source
 * stream, standing in for a large region — a TIFF strip, a PNG `IDAT`, a JPEG entropy scan
 * — that a streaming reader parsed past without loading.  {@see writeTo()} copies the
 * window straight to a target in bounded memory (via {@see StreamIO::copyRange()}), so a
 * container can rewrite its metadata and pass a payload far larger than memory through from
 * source to target untouched.  {@see read()} materializes it, for the whole-string path.
 *
 * The source stream must stay open and seekable for the life of the range.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
final class SourceRange
{
	/**
	 * @param mixed $source The seekable {@see \Psr\Http\Message\StreamInterface} or PHP stream resource.
	 * @param int $offset The absolute byte offset of the payload in the source.
	 * @param int $length The payload length in bytes.
	 */
	public function __construct(
		private mixed $source,
		private int $offset,
		private int $length,
	) {
	}

	/**
	 * Returns the payload length in bytes.
	 * @return int The length.
	 */
	public function getLength(): int
	{
		return $this->length;
	}

	/**
	 * Copies the payload from the source to a target in bounded memory.
	 * @param mixed $target A writable {@see \Psr\Http\Message\StreamInterface} or PHP stream resource.
	 * @return int The number of bytes written (equal to {@see getLength()}).
	 */
	public function writeTo(mixed $target): int
	{
		return StreamIO::copyRange($this->source, $this->offset, $this->length, $target);
	}

	/**
	 * Materializes the payload into a string.  This defeats the streaming, so it is for the
	 * whole-string path (a caller that asked a streamed container for its bytes).
	 * @return string The payload bytes.
	 */
	public function read(): string
	{
		$buffer = StreamIO::temp();
		$this->writeTo($buffer);
		rewind($buffer);
		$bytes = stream_get_contents($buffer);
		fclose($buffer);
		return $bytes === false ? '' : $bytes;
	}
}
