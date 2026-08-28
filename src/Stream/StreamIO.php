<?php

/**
 * StreamIO class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\Stream;

use Psr\Http\Message\StreamInterface;

/**
 * StreamIO class.
 *
 * Static helpers for moving whole byte strings to and from a byte source that may be a
 * PSR-7 {@see StreamInterface} (any implementation) or a raw PHP stream resource.  The
 * library consumes streams rather than providing one, so these are the one place that
 * knows how to read from, write to, and rewind either kind.
 *
 * These are convenience over the platform, not a stream abstraction: {@see readAll()} and
 * {@see writeAll()} transfer a whole string, {@see rewind()} rewinds a seekable source,
 * and {@see temp()} makes a rewound `php://temp` resource for handing composed bytes back.
 * The offset-driven typed reader the lazy metadata scan needs is {@see BinaryReader}.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class StreamIO
{
	/**
	 * Reads a byte source in full, from its current position.  A string passes through
	 * unchanged, so a caller can hand any of the three accepted forms straight in.
	 * @param mixed $source A string, {@see StreamInterface}, or PHP stream resource.
	 * @throws \InvalidArgumentException When the source is none of those.
	 * @return string The bytes.
	 */
	public static function readAll(mixed $source): string
	{
		if (is_string($source)) {
			return $source;
		}
		if ($source instanceof StreamInterface) {
			return $source->getContents();
		}
		if (is_resource($source)) {
			$data = stream_get_contents($source);
			return $data === false ? '' : $data;
		}
		throw new \InvalidArgumentException(sprintf('A byte source must be a string, PSR-7 StreamInterface, or PHP stream resource; \'%s\' given.', get_debug_type($source)));
	}

	/**
	 * Writes a whole string to a target, honoring partial writes.
	 * @param mixed $target A writable {@see StreamInterface} or PHP stream resource.
	 * @param string $bytes The bytes to write.
	 * @throws \InvalidArgumentException When the target is neither.
	 * @throws \RuntimeException When the target stops accepting bytes.
	 * @return int The number of bytes written.
	 */
	public static function writeAll(mixed $target, string $bytes): int
	{
		$isStream = $target instanceof StreamInterface;
		if (!$isStream && !is_resource($target)) {
			throw new \InvalidArgumentException(sprintf('A stream write target must be a PSR-7 StreamInterface or a PHP stream resource; \'%s\' given.', get_debug_type($target)));
		}
		$length = strlen($bytes);
		$total = 0;
		while ($total < $length) {
			$slice = $total === 0 ? $bytes : substr($bytes, $total);
			$written = $isStream ? $target->write($slice) : fwrite($target, $slice);
			if ($written === false || $written < 1) {
				throw new \RuntimeException(sprintf('The target stream stopped accepting bytes after %s of %s.', $total, $length));
			}
			$total += $written;
		}
		return $total;
	}

	/**
	 * Rewinds a seekable source to its start; a non-seekable source (or a string) is left
	 * untouched.  Used before draining a whole-image stream so the read starts at byte 0.
	 * @param mixed $source A {@see StreamInterface}, PHP stream resource, or string.
	 */
	public static function rewind(mixed $source): void
	{
		if ($source instanceof StreamInterface) {
			if ($source->isSeekable()) {
				$source->seek(0);
			}
			return;
		}
		if (is_resource($source) && stream_get_meta_data($source)['seekable']) {
			rewind($source);
		}
	}

	/**
	 * Creates a rewound in-memory stream resource holding the given bytes, for the
	 * accessors that hand a caller a readable stream of composed output.
	 * @param string $bytes The contents.
	 * @throws \RuntimeException When the temp stream cannot be opened.
	 * @return resource The stream resource, positioned at the start.
	 */
	public static function temp(string $bytes = '')
	{
		$handle = fopen('php://temp', 'r+b');
		if ($handle === false) {
			throw new \RuntimeException('Unable to open a temporary stream');
		}
		if ($bytes !== '') {
			fwrite($handle, $bytes);
			rewind($handle);
		}
		return $handle;
	}
}
