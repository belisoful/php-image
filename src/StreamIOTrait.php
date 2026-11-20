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
 * parsing factories.  {@see readFile()} and {@see fileBytes()} read a whole file within
 * the {@see ImageFile::getMaxFileSize() file ceiling}, and {@see writeFile()} is what a
 * class's `save()` writes through, so a file is replaced whole or left alone.
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
	 * one: they go to a temporary file beside the target, which is then renamed over it.
	 * The replacement keeps the target's permission bits, and a symbolic link is followed
	 * to the file it names rather than replaced.
	 *
	 * Whatever stops that leaves the original as it was and throws: a write that fails
	 * part-way (a full disk), a directory that will not take the temporary file (read-only),
	 * a rename that is refused (a file mounted on its own).  The bytes are never written
	 * over the file in place instead, because a write in place that fails has already
	 * destroyed what it was replacing; a caller who wants one all the same hands
	 * {@see writeTo()} a handle it opened itself.
	 *
	 * Only where there is no file to replace are the bytes written directly: a path with a
	 * stream-wrapper scheme, and a target that is not a regular file -- a device, a pipe, a
	 * link to nothing.
	 * @param string $path The destination file path.
	 * @param string $bytes The bytes to write.
	 * @throws \RuntimeException When the file cannot be written, or cannot be replaced.
	 * @return int The number of bytes written.
	 */
	protected function writeFile(string $path, string $bytes): int
	{
		$target = str_contains($path, '://') ? null : self::replaceableFile($path);
		if ($target === null) {
			$written = @file_put_contents($path, $bytes);
			if ($written === false) {
				throw new \RuntimeException(sprintf('Image file \'%s\' cannot be written.', $path));
			}
			return $written;
		}
		// Named for nothing but chance, so a target whose own name is as long as a name may
		// be still has room for one beside it.
		$temp = dirname($target) . DIRECTORY_SEPARATOR . '.' . bin2hex(random_bytes(8)) . '.tmp';
		$handle = @fopen($temp, 'xb');
		if ($handle === false) {
			throw new \RuntimeException(sprintf('Image file \'%s\' cannot be written: its directory does not take the temporary file the new bytes go to first. A file already there is left as it was; to write over it in place, hand writeTo() an open handle.', $path));
		}
		$written = $this->writeHandle($handle, $bytes);
		fclose($handle);
		if ($written !== strlen($bytes)) {
			@unlink($temp);
			throw new \RuntimeException(sprintf('Image file \'%s\' cannot be written.', $path));
		}
		$mode = @fileperms($target);
		if ($mode !== false) {
			@chmod($temp, $mode & 0o7777);
		}
		if (!@rename($temp, $target)) {
			@unlink($temp);
			throw new \RuntimeException(sprintf('Image file \'%s\' cannot be replaced: the new bytes could not be renamed over it. It is left as it was; to write over it in place, hand writeTo() an open handle.', $path));
		}
		return $written;
	}

	/**
	 * Resolves the file a path's bytes are to replace.
	 * @param string $path The destination file path.
	 * @return ?string The file to write beside and rename over -- a link followed to the
	 *   file it names, a path that names nothing yet as it stands -- or null when the path
	 *   is not a regular file to replace: a device, a pipe, a directory, a link to nothing.
	 */
	private static function replaceableFile(string $path): ?string
	{
		$target = realpath($path);
		if ($target === false) {
			return is_link($path) ? null : $path;
		}
		return is_file($target) ? $target : null;
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
	 * @param ?int $limit The most bytes to read from a stream, or null to read to its end.
	 *   A caller that must not hold more than a ceiling asks for one byte over it, and
	 *   getting that many is how it knows the source is longer.
	 * @throws \InvalidArgumentException When the source is none of those.
	 * @return string The bytes.
	 */
	protected static function sourceBytes(mixed $source, ?int $limit = null): string
	{
		return StreamIO::readAll($source, $limit);
	}

	/**
	 * Drains a byte source that is a whole file, within the
	 * {@see ImageFile::getMaxFileSize() file ceiling}: reading stops one byte past the
	 * ceiling, so a source longer than memory is refused instead of exhausting it.
	 * @param mixed $source The string, stream, or stream resource.
	 * @throws \InvalidArgumentException When the source is none of those.
	 * @throws \UnexpectedValueException When the source is longer than the ceiling.
	 * @return string The bytes.
	 */
	protected static function fileBytes(mixed $source): string
	{
		$ceiling = ImageFile::getMaxFileSize();
		$bytes = static::sourceBytes($source, $ceiling === null ? null : min($ceiling, PHP_INT_MAX - 1) + 1);
		ImageFile::assertFileSize(strlen($bytes));
		return $bytes;
	}

	/**
	 * Reads a file whole, within the {@see ImageFile::getMaxFileSize() file ceiling}.
	 * @param string $path The file path.
	 * @throws \RuntimeException When the file cannot be read.
	 * @throws \UnexpectedValueException When the file is longer than the ceiling.
	 * @return string The bytes.
	 */
	protected static function readFile(string $path): string
	{
		$handle = @fopen($path, 'rb');
		if ($handle === false) {
			throw new \RuntimeException(sprintf('Image file \'%s\' cannot be read.', $path));
		}
		try {
			return static::fileBytes($handle);
		} finally {
			fclose($handle);
		}
	}
}
