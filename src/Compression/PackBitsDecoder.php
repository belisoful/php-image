<?php

/**
 * PackBitsDecoder class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\Compression;

/**
 * PackBitsDecoder class.
 *
 * The incremental decoder half of the PackBits run-length codec (see
 * {@see PackBitsCompressor} for the format).  It is a {@see StreamCodec}: feed encoded
 * bytes with {@see add()}, which decodes every whole packet and carries any partial tail.
 * A truncated final packet held at {@see finish()} is discarded — RLE carries no end
 * marker, so the tolerance matches the format and {@see PackBitsCompressor::decompress()}.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class PackBitsDecoder implements StreamCodec
{
	/** @var string The buffered partial packet awaiting more input. */
	private string $_pending = '';

	/**
	 * Decodes whole packets from the carry buffer, retaining any partial tail.
	 * @param string $data The encoded chunk.
	 * @return string The decoded bytes from complete packets.
	 */
	public function add(string $data): string
	{
		$this->_pending .= $data;
		$out = '';
		$i = 0;
		$len = strlen($this->_pending);
		while ($i < $len) {
			$n = ord($this->_pending[$i]);
			if ($n === 128) {
				$i++;
				continue;
			}
			if ($n < 128) {
				$count = $n + 1;
				if ($i + 1 + $count > $len) {
					break; // incomplete literal; carry it
				}
				$out .= substr($this->_pending, $i + 1, $count);
				$i += 1 + $count;
			} else {
				if ($i + 2 > $len) {
					break; // need the run byte; carry it
				}
				$out .= str_repeat($this->_pending[$i + 1], 257 - $n);
				$i += 2;
			}
		}
		$this->_pending = substr($this->_pending, $i);
		return $out;
	}

	/**
	 * A partial packet still buffered at close is a truncated stream and is discarded.
	 * @return string Always ''.
	 */
	public function finish(): string
	{
		return '';
	}
}
