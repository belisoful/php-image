<?php

/**
 * ImageChunk class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image;

/**
 * ImageChunk class.
 *
 * Describes one chunk of a chunked image container (a PNG chunk or a RIFF chunk).  It
 * records the four-character {@see getType() type}, the payload {@see getSize() size},
 * the byte {@see getOffset() offset} of the payload within the file, and the payload
 * {@see getData() bytes}.
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

	/** @var string The payload bytes. */
	private string $_data;

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
	 * Returns the payload bytes.
	 * @return string The payload.
	 */
	public function getData(): string
	{
		return $this->_data;
	}

	/**
	 * Sets the payload bytes, updating the recorded size.
	 * @param string $value The payload.
	 */
	public function setData(string $value): void
	{
		$this->_data = $value;
		$this->_size = strlen($value);
	}
}
