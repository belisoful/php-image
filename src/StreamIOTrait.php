<?php

/**
 * StreamIOTrait trait file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image;

use Belisoful\Image\Stream\StreamIO;
use Psr\Http\Message\StreamInterface;

/**
 * StreamIOTrait trait.
 *
 * Gives a composable metadata or container class PSR-7 stream and PHP resource IO:
 * {@see writeTo()} composes the object (via its `toBinary()`) into any writable
 * {@see StreamInterface} or stream resource, and {@see sourceBytes()} drains any
 * string, {@see StreamInterface}, or stream resource into bytes for the class's
 * parsing factories.
 *
 * The library consumes streams rather than providing them: any PSR-7 implementation is
 * accepted, as is a raw PHP stream resource, which is read and written in place so the
 * caller's handle stays open and usable.  {@see \Belisoful\Image\Stream\StreamIO} holds
 * the one place that tells the two apart.
 *
 * ```php
 * $jpeg = JPEGImage::fromStream($psr7Stream);        // any PSR-7 stream in
 * $jpeg->writeTo(fopen('php://temp', 'w+b'));        // resource out
 * $exif->writeTo($psr7Stream);
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
trait StreamIOTrait
{
	/**
	 * Composes the object and writes the bytes to a stream or stream resource,
	 * honoring partial writes.
	 * @param mixed $target A writable {@see StreamInterface} or PHP stream resource.
	 * @throws \InvalidArgumentException When the target is neither.
	 * @throws \RuntimeException When the target stops accepting bytes.
	 * @return int The number of bytes written.
	 */
	public function writeTo(mixed $target): int
	{
		return StreamIO::writeAll($target, (string) $this->toBinary());
	}

	/**
	 * Drains a byte source: a string is returned as-is, and a {@see StreamInterface} or
	 * PHP stream resource is read from its current position to the end.
	 * @param mixed $source The string, stream, or stream resource.
	 * @throws \InvalidArgumentException When the source is none of those.
	 * @return string The bytes.
	 */
	protected static function sourceBytes(mixed $source): string
	{
		return StreamIO::readAll($source);
	}
}
