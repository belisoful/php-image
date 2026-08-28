<?php

/**
 * BitWriter class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\Util;

/**
 * BitWriter class.
 *
 * Packs fields of any bit width, most-significant-bit first, into a byte string.  The
 * codecs that need sub-byte fields — LZW and the CCITT fax modes — build their output
 * through it and take the finished bytes from {@see getBytes()}.
 *
 * Bits accumulate into a partial byte and every whole byte they complete is appended, so
 * a field may finish an earlier byte and leave its own remainder pending.  {@see flush()}
 * emits any trailing partial byte, zero-padding the unused low bits; the pending bits
 * reach the output only through it, so call it before reading the result.
 *
 * ```php
 * $w = new BitWriter();
 * $w->writeBits(0xA, 4);
 * $w->writeBits(0xBCD, 12);
 * $w->flush();
 * $w->getBytes();          // "\xAB\xCD"
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class BitWriter
{
	/** @var string The bytes completed so far. */
	private string $_out = '';

	/** @var int The pending bits, held most-significant first (fewer than 8 between fields). */
	private int $_buffer = 0;

	/** @var int The number of valid bits in {@see $_buffer}. */
	private int $_count = 0;

	/** @var int The total bits written; {@see align()} reads it to find the next boundary. */
	private int $_bitIndex = 0;

	/**
	 * Returns the bytes written so far, excluding any bits still pending in the partial
	 * byte; call {@see flush()} first to include those.
	 * @return string The encoded bytes.
	 */
	public function getBytes(): string
	{
		return $this->_out;
	}

	/**
	 * Returns the total number of bits written since construction.
	 * @return int The current bit index.
	 */
	public function getCurrentBitIndex(): int
	{
		return $this->_bitIndex;
	}

	/**
	 * Writes zero bits until {@see getCurrentBitIndex()} is a multiple of the alignment.
	 * An index already on the boundary, and an alignBits below 1, write nothing.
	 * @param int $alignBits The bit boundary to align to; 8 aligns to a byte. Default 8.
	 */
	public function align(int $alignBits = 8): void
	{
		if ($alignBits < 1) {
			return;
		}
		$n = ($alignBits - ($this->_bitIndex % $alignBits)) % $alignBits;
		while ($n > 0) {
			$take = min(PHP_INT_SIZE * 8, $n);
			$this->writeBits(0, $take);
			$n -= $take;
		}
	}

	/**
	 * Writes the low $numBits of a value, most-significant bit first.  A wider value is
	 * truncated, and a negative integer is written as its two's-complement low bits.
	 * @param int $value The value to write, right-aligned to the least-significant bit.
	 * @param int $numBits The number of bits to write, from 0 to PHP_INT_SIZE * 8.
	 * @throws \InvalidArgumentException When the bit count is out of range.
	 */
	public function writeBits(int $value, int $numBits): void
	{
		if ($numBits < 0 || $numBits > PHP_INT_SIZE * 8) {
			if (PHP_INT_SIZE === 4 && $numBits > 32 && $numBits <= 64) {
				throw new \InvalidArgumentException(sprintf('Writing %s bits requires a 64-bit PHP build.', $numBits));
			}
			throw new \InvalidArgumentException(sprintf('Cannot write %s bits; the count must be between 0 and the platform integer width.', $numBits));
		}
		if ($numBits === 0) {
			return;
		}
		$remaining = $numBits;
		while ($remaining > 0) {
			// Take only as many bits as finish the pending byte, so the shifts below stay
			// under a byte and the buffer never exceeds 8 bits.
			$take = min($remaining, 8 - $this->_count);
			$remaining -= $take;
			$bits = ($value >> $remaining) & ((1 << $take) - 1);
			$this->_buffer = ($this->_buffer << $take) | $bits;
			$this->_count += $take;
			if ($this->_count === 8) {
				$this->_out .= chr($this->_buffer);
				$this->_buffer = 0;
				$this->_count = 0;
			}
		}
		$this->_bitIndex += $numBits;
	}

	/**
	 * Writes any pending partial byte, zero-padding the unused low bits.
	 * @return int The number of bytes written by the flush (0 or 1).
	 */
	public function flush(): int
	{
		if ($this->_count === 0) {
			return 0;
		}
		$pad = 8 - $this->_count;
		$this->_out .= chr(($this->_buffer << $pad) & 0xFF);
		$this->_bitIndex += $pad;
		$this->_buffer = 0;
		$this->_count = 0;
		return 1;
	}
}
