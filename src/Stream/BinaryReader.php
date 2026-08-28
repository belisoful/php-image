<?php

/**
 * BinaryReader class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\Stream;

use Psr\Http\Message\StreamInterface;

/**
 * BinaryReader class.
 *
 * A small typed reader over a *seekable* byte source that may be a PSR-7
 * {@see StreamInterface} or a raw PHP stream resource.  It exists for one job: the lazy
 * metadata scan ({@see \Belisoful\Image\TIFF\TIFFDocument::scanStream()},
 * {@see \Belisoful\Image\Meta\EXIF::scanStream()}), which walks a multi-gigabyte TIFF by
 * its offsets — seeking to each IFD and reading only the tag values — without ever
 * reading the strip data.  {@see \Belisoful\Image\Meta\IPTC} uses it to read a windowed
 * region of a source in place.
 *
 * It is a reader, not a stream: it has {@see seek()}/{@see tell()} because a scan is
 * offset-driven, and typed {@see readBytes()}/{@see readUInt16()}/{@see readUInt32()}
 * that a byte format needs, but none of the rest of a stream's surface (no write, close,
 * detach, metadata, contents, or size).  The source is read in place and never owned, so
 * the caller's handle stays open.  For whole-string reads and writes use the static
 * {@see StreamIO} helpers instead.
 *
 * ```php
 * $reader = new BinaryReader($stream);      // StreamInterface or resource
 * $reader->setBigEndian($reader->readBytes(2) === 'MM');
 * $magic = $reader->readUInt16();
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class BinaryReader
{
	/** @var resource|StreamInterface The wrapped byte source. */
	private mixed $_source;

	/** @var bool Whether multi-byte reads decode most-significant byte first. */
	private bool $_bigEndian = false;

	/**
	 * @param mixed $source A {@see StreamInterface} or PHP stream resource.
	 * @throws \InvalidArgumentException When the source is neither.
	 */
	public function __construct(mixed $source)
	{
		if (!is_resource($source) && !$source instanceof StreamInterface) {
			throw new \InvalidArgumentException(sprintf('A byte source must be a PSR-7 StreamInterface or a PHP stream resource; \'%s\' given.', get_debug_type($source)));
		}
		$this->_source = $source;
	}

	/**
	 * Sets the byte order used by the multi-byte reads.
	 * @param bool $value True to decode most-significant byte first.
	 */
	public function setBigEndian(bool $value): void
	{
		$this->_bigEndian = $value;
	}

	/**
	 * Indicates whether the source can be sought.
	 * @return bool True when seekable.
	 */
	public function isSeekable(): bool
	{
		if ($this->_source instanceof StreamInterface) {
			return $this->_source->isSeekable();
		}
		return (bool) stream_get_meta_data($this->_source)['seekable'];
	}

	/**
	 * Returns the current position.
	 * @throws \RuntimeException When the position cannot be determined.
	 * @return int The byte position.
	 */
	public function tell(): int
	{
		if ($this->_source instanceof StreamInterface) {
			return $this->_source->tell();
		}
		$pos = ftell($this->_source);
		if ($pos === false) {
			throw new \RuntimeException('Unable to determine stream position');
		}
		return $pos;
	}

	/**
	 * Seeks to a position.
	 * @param int $offset The byte offset.
	 * @param int $whence SEEK_SET, SEEK_CUR, or SEEK_END.
	 * @throws \RuntimeException When the seek fails.
	 */
	public function seek(int $offset, int $whence = SEEK_SET): void
	{
		if ($this->_source instanceof StreamInterface) {
			$this->_source->seek($offset, $whence);
			return;
		}
		if (@fseek($this->_source, $offset, $whence) !== 0) {
			throw new \RuntimeException('Unable to seek to stream position ' . $offset);
		}
	}

	/**
	 * Reads exactly $length bytes, looping over short reads.
	 * @param int $length The number of bytes to read.
	 * @throws \RuntimeException When the source ends before $length bytes are read.
	 * @return string The bytes read.
	 */
	public function readBytes(int $length): string
	{
		if ($length < 1) {
			return '';
		}
		$data = '';
		while (strlen($data) < $length) {
			$want = $length - strlen($data);
			$chunk = $this->_source instanceof StreamInterface
				? $this->_source->read($want)
				: (string) fread($this->_source, $want);
			if ($chunk === '') {
				throw new \RuntimeException(sprintf('Unexpected end of stream: %s bytes were needed but only %s were read.', $length, strlen($data)));
			}
			$data .= $chunk;
		}
		return $data;
	}

	/**
	 * Reads an unsigned 16-bit integer in the configured byte order.
	 * @throws \RuntimeException When the source ends first.
	 * @return int The value.
	 */
	public function readUInt16(): int
	{
		return unpack($this->_bigEndian ? 'n' : 'v', $this->readBytes(2))[1];
	}

	/**
	 * Reads an unsigned 32-bit integer in the configured byte order.
	 * @throws \RuntimeException When the source ends first.
	 * @return int The value.
	 */
	public function readUInt32(): int
	{
		return unpack($this->_bigEndian ? 'N' : 'V', $this->readBytes(4))[1];
	}
}
