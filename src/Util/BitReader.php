<?php

/**
 * BitReader class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\Util;

/**
 * BitReader class.
 *
 * Reads fields of any bit width, most-significant-bit first, from a byte string.  It is
 * the decoding counterpart of {@see BitWriter}, and the codecs with sub-byte fields —
 * LZW and the CCITT fax modes — decode through it.
 *
 * A field crosses byte boundaries: a 12-bit read consumes two source bytes and leaves the
 * remaining 4 bits for the next read.  {@see readBits()} returns false when the string
 * ends before the whole field is available, leaving the position untouched so the caller
 * can stop cleanly.  A field of the full integer width whose top bit is set returns a
 * negative PHP integer holding the raw bit pattern, since PHP has no unsigned integer type.
 *
 * ```php
 * $r = new BitReader("\xAB\xCD");
 * $hi = $r->readBits(4);     // 0xA
 * $lo = $r->readBits(12);    // 0xBCD
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class BitReader
{
	/** @var string The bytes being read. */
	private string $_data;

	/** @var int The next byte offset to draw from. */
	private int $_pos = 0;

	/** @var int The bits drawn but not yet consumed, held least-significant-aligned. */
	private int $_buffer = 0;

	/** @var int The number of valid bits in {@see $_buffer}. */
	private int $_count = 0;

	/** @var int The total bits consumed; {@see align()} reads it to find the next boundary. */
	private int $_bitIndex = 0;

	/**
	 * @param string $data The bytes to read.
	 */
	public function __construct(string $data = '')
	{
		$this->_data = $data;
	}

	/**
	 * Returns the total number of bits consumed since construction.
	 * @return int The current bit index.
	 */
	public function getCurrentBitIndex(): int
	{
		return $this->_bitIndex;
	}

	/**
	 * Reads and discards bits until {@see getCurrentBitIndex()} is a multiple of the
	 * alignment.  An index already on the boundary reads nothing and returns true; an
	 * alignBits below 1 reads nothing and returns false.
	 * @param int $alignBits The bit boundary to align to; 8 aligns to a byte. Default 8.
	 * @return bool True when the boundary is reached, false when the data ends first or alignBits is below 1.
	 */
	public function align(int $alignBits = 8): bool
	{
		if ($alignBits < 1) {
			return false;
		}
		$n = ($alignBits - ($this->_bitIndex % $alignBits)) % $alignBits;
		while ($n > 0) {
			$take = min(PHP_INT_SIZE * 8, $n);
			if ($this->readBits($take) === false) {
				return false;
			}
			$n -= $take;
		}
		return true;
	}

	/**
	 * Reads a field of bits, most-significant bit first.
	 * @param int $numBits The number of bits to read, from 0 to PHP_INT_SIZE * 8.
	 * @throws \InvalidArgumentException When the bit count is out of range.
	 * @return false|int The value, or false when the data ends before the field is complete.
	 */
	public function readBits(int $numBits): false|int
	{
		if ($numBits < 0 || $numBits > PHP_INT_SIZE * 8) {
			if (PHP_INT_SIZE === 4 && $numBits > 32 && $numBits <= 64) {
				throw new \InvalidArgumentException(sprintf('Reading %s bits requires a 64-bit PHP build.', $numBits));
			}
			throw new \InvalidArgumentException(sprintf('Cannot read %s bits; the count must be between 0 and the platform integer width.', $numBits));
		}
		if ($numBits === 0) {
			return 0;
		}
		// A short field leaves the reader where it started, so a caller that stops on
		// false can still trust the position.
		$savePos = $this->_pos;
		$saveBuffer = $this->_buffer;
		$saveCount = $this->_count;

		$value = 0;
		$remaining = $numBits;
		$length = strlen($this->_data);
		while ($remaining > 0) {
			if ($this->_count === 0) {
				if ($this->_pos >= $length) {
					$this->_pos = $savePos;
					$this->_buffer = $saveBuffer;
					$this->_count = $saveCount;
					return false;
				}
				$this->_buffer = ord($this->_data[$this->_pos++]);
				$this->_count = 8;
			}
			// The buffer holds at most 8 bits, so each pass shifts and masks under a byte.
			$take = min($remaining, $this->_count);
			$this->_count -= $take;
			$value = ($value << $take) | (($this->_buffer >> $this->_count) & ((1 << $take) - 1));
			$this->_buffer &= (1 << $this->_count) - 1;
			$remaining -= $take;
		}
		$this->_bitIndex += $numBits;
		return $value;
	}
}
