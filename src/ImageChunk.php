<?php

/**
 * ImageChunk class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image;

use Belisoful\Image\Stream\SourceRange;

/**
 * ImageChunk class.
 *
 * Describes one chunk of a chunked image container (a PNG chunk or a RIFF chunk).  It
 * records the four-character {@see getType() type}, the payload {@see getSize() size},
 * the byte {@see getOffset() offset} of the payload within the file, and the payload
 * {@see getData() bytes}.
 *
 * A chunk read by a streaming (lazy) parse may hold its bytes as a {@see getDeferredRange()
 * deferred range} into the still-open source instead of loading them, so a large payload
 * (a PNG `IDAT`) is copied straight through on {@see \Belisoful\Image\ImageFile::streamTo()}
 * rather than materialized; {@see getData()} materializes it on demand for the whole-string
 * path, and {@see setData()} loads it.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class ImageChunk
{
	/** @var string The four-character chunk type. */
	private string $_type;

	/** @var int The payload size in bytes. */
	private int $_size;

	/** @var int The byte offset of the payload within the file. */
	private int $_offset;

	/** @var string The payload bytes (materialized; a deferred chunk fills this on demand). */
	private string $_data;

	/** @var ?SourceRange The deferred whole-chunk range (length + type + payload + CRC), or null when loaded. */
	private ?SourceRange $_range = null;

	/**
	 * @param string $type The four-character chunk type.
	 * @param int $size The payload size in bytes.
	 * @param int $offset The byte offset of the payload within the file.
	 * @param string $data The payload bytes.
	 */
	public function __construct(string $type, int $size, int $offset, string $data)
	{
		$this->_type = $type;
		$this->_size = $size;
		$this->_offset = $offset;
		$this->_data = $data;
	}

	/**
	 * Builds a chunk whose payload is deferred to a range in a still-open source, for a
	 * streaming parse that reads the framing but not the bytes.
	 * @param string $type The four-character chunk type.
	 * @param int $size The payload size in bytes.
	 * @param int $offset The byte offset of the payload within the source.
	 * @param SourceRange $wholeChunk The whole on-disk chunk range (length + type + payload + CRC).
	 * @return self The deferred chunk.
	 */
	public static function deferred(string $type, int $size, int $offset, SourceRange $wholeChunk): self
	{
		$chunk = new self($type, $size, $offset, '');
		$chunk->_range = $wholeChunk;
		return $chunk;
	}

	/**
	 * Returns the deferred whole-chunk range, or null when the payload is loaded.  A
	 * streaming writer copies this range straight through; a loaded chunk it rebuilds.
	 * @return ?SourceRange The deferred whole-chunk range.
	 */
	public function getDeferredRange(): ?SourceRange
	{
		return $this->_range;
	}

	/**
	 * Returns the four-character chunk type.
	 * @return string The chunk type.
	 */
	public function getType(): string
	{
		return $this->_type;
	}

	/**
	 * Returns the payload size in bytes.
	 * @return int The payload size.
	 */
	public function getSize(): int
	{
		return $this->_size;
	}

	/**
	 * Returns the byte offset of the payload within the file.
	 * @return int The payload offset.
	 */
	public function getOffset(): int
	{
		return $this->_offset;
	}

	/**
	 * Returns the payload bytes, materializing a deferred chunk from its source on demand.
	 * @return string The payload.
	 */
	public function getData(): string
	{
		if ($this->_range !== null) {
			return substr($this->_range->read(), 8, $this->_size);   // drop the 4-byte length + 4-byte type
		}
		return $this->_data;
	}

	/**
	 * Sets the payload bytes, updating the recorded size and loading the chunk (a deferred
	 * range is dropped, since the payload is now held directly).
	 * @param string $value The payload.
	 */
	public function setData(string $value): void
	{
		$this->_data = $value;
		$this->_size = strlen($value);
		$this->_range = null;
	}
}
