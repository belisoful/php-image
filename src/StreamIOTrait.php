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
 * parsing factories.  {@see writeFile()} is what a class's `save()` writes through, so a
 * file is replaced whole or left alone.
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
	 * Writes bytes to a file so that it holds the old content or the new and never part of
	 * one: they go to a temporary file beside the target, which is then renamed over it.  A
	 * write that fails part-way -- a full disk -- therefore leaves the original as it was
	 * and throws.  The replacement keeps the target's permission bits, and a symbolic link
	 * is followed to the file it names rather than replaced.
	 *
	 * Where a rename is not available the bytes are written in place, as a plain write
	 * would: a path with a stream-wrapper scheme, a target that is not a regular file (a
	 * device, a pipe, a link to nothing), and a directory that takes neither the temporary
	 * file nor the rename (read-only, or a file mounted on its own).
	 * @param string $path The destination file path.
	 * @param string $bytes The bytes to write.
	 * @throws \RuntimeException When the file cannot be written.
	 * @return int The number of bytes written.
	 */
	protected function writeFile(string $path, string $bytes): int
	{
		$written = str_contains($path, '://') ? null : $this->replaceFile($path, $bytes);
		$written ??= @file_put_contents($path, $bytes);
		if ($written === false) {
			throw new \RuntimeException(sprintf('Image file \'%s\' cannot be written.', $path));
		}
		return $written;
	}

	/**
	 * Writes bytes beside a file and renames them over it.
	 * @param string $path The destination file path.
	 * @param string $bytes The bytes to write.
	 * @return null|false|int The number of bytes written, false when the write stopped
	 *   short, or null when the file cannot be replaced this way and nothing was changed.
	 */
	private function replaceFile(string $path, string $bytes): null|false|int
	{
		$target = realpath($path);
		if ($target === false) {
			if (is_link($path)) {
				return null;
			}
			$target = $path;
		} elseif (!is_file($target)) {
			return null;
		}
		$temp = $target . '.' . bin2hex(random_bytes(6)) . '.tmp';
		$handle = @fopen($temp, 'xb');
		if ($handle === false) {
			return null;
		}
		$written = $this->writeHandle($handle, $bytes);
		fclose($handle);
		if ($written !== strlen($bytes)) {
			@unlink($temp);
			return false;
		}
		$mode = @fileperms($target);
		if ($mode !== false) {
			@chmod($temp, $mode & 0o7777);
		}
		if (!@rename($temp, $target)) {
			@unlink($temp);
			return null;
		}
		return $written;
	}

	/**
	 * Writes bytes to an open file.  A seam: a test overrides it to stand in for a disk
	 * that fills part-way through a write, which no test can arrange for real.
	 * @param resource $handle The open file.
	 * @param string $bytes The bytes to write.
	 * @return false|int The number of bytes written, or false on failure.
	 */
	protected function writeHandle($handle, string $bytes): false|int
	{
		return fwrite($handle, $bytes);
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
