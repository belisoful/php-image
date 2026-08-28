<?php

/**
 * LZWEncoder class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\Compression;

/**
 * LZWEncoder class.
 *
 * The incremental encoder half of the variable-width LZW codec (see {@see LZWCompressor}
 * for the wire format).  It is a {@see StreamCodec}: the leading clear code is emitted on
 * the first {@see add()}, codes are packed most-significant-bit first as symbols arrive,
 * and {@see finish()} emits the final code, end-of-information, and the padding byte.  The
 * only state held is the dictionary (bounded at the 4096-code ceiling) and a sub-byte bit
 * accumulator, so it encodes a stream of any size in constant memory.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class LZWEncoder implements StreamCodec
{
	/** @var int The pending bits, most-significant first. */
	private int $_bitBuffer = 0;

	/** @var int The count of valid bits in the bit buffer. */
	private int $_bitCount = 0;

	/** @var int The current code width in bits. */
	private int $_codeSize = LZWCompressor::MinCodeSize;

	/** @var int The next dictionary code to assign. */
	private int $_next = LZWCompressor::FirstCode;

	/** @var bool Whether the clear-code prologue has been emitted. */
	private bool $_started = false;

	/** @var array<string, int> The dictionary (string to code). */
	private array $_dict = [];

	/** @var ?string The current prefix. */
	private ?string $_w = null;

	/**
	 * Encodes a chunk, emitting whole output bytes as codes complete them.
	 * @param string $data The raw chunk.
	 * @return string The encoded bytes produced from this chunk.
	 */
	public function add(string $data): string
	{
		$out = $this->startEncode();
		// Pull state into locals and inline the bit packing; per-byte property access and method
		// calls dominate this hot loop.
		$w = $this->_w;
		$next = $this->_next;
		$codeSize = $this->_codeSize;
		$buf = $this->_bitBuffer;
		$cnt = $this->_bitCount;
		$dict = $this->_dict;
		$len = strlen($data);
		for ($i = 0; $i < $len; $i++) {
			$c = $data[$i];
			if ($w === null) {
				$w = $c;
				continue;
			}
			$candidate = $w . $c;
			if (isset($dict[$candidate])) {
				$w = $candidate;
				continue;
			}
			$buf = ($buf << $codeSize) | $dict[$w];   // emit the code for the current prefix
			$cnt += $codeSize;
			$dict[$candidate] = $next++;
			$w = $c;
			if ($next === (1 << $codeSize)) {
				if ($codeSize < LZWCompressor::MaxCodeSize) {
					$codeSize++;
				} else {
					$buf = ($buf << $codeSize) | LZWCompressor::ClearCode;
					$cnt += $codeSize;
					$dict = [];
					for ($k = 0; $k < 256; $k++) {
						$dict[chr($k)] = $k;
					}
					$next = LZWCompressor::FirstCode;
					$codeSize = LZWCompressor::MinCodeSize;
				}
			}
			while ($cnt >= 8) {                       // drain whole bytes so the accumulator never overflows
				$cnt -= 8;
				$out .= chr(($buf >> $cnt) & 0xFF);
				$buf &= (1 << $cnt) - 1;
			}
		}
		$this->_w = $w;
		$this->_next = $next;
		$this->_codeSize = $codeSize;
		$this->_bitBuffer = $buf;
		$this->_bitCount = $cnt;
		$this->_dict = $dict;
		return $out;
	}

	/**
	 * Emits the final code, end-of-information, and padding.
	 * @return string The final bytes.
	 */
	public function finish(): string
	{
		$out = $this->startEncode();
		if ($this->_w !== null) {
			$this->pushBits($this->_dict[$this->_w], $this->_codeSize);
		}
		$this->pushBits(LZWCompressor::EndOfInformation, $this->_codeSize);
		return $out . $this->drainBytes() . $this->flushBits();
	}

	/**
	 * Emits the leading clear code and seeds the dictionary, once.
	 * @return string The drained bytes of the clear-code prologue (empty after the first call).
	 */
	private function startEncode(): string
	{
		if ($this->_started) {
			return '';
		}
		$this->_dict = [];
		for ($c = 0; $c < 256; $c++) {
			$this->_dict[chr($c)] = $c;
		}
		$this->_next = LZWCompressor::FirstCode;
		$this->_codeSize = LZWCompressor::MinCodeSize;
		$this->pushBits(LZWCompressor::ClearCode, $this->_codeSize);
		$this->_started = true;
		return $this->drainBytes();
	}

	/**
	 * Appends bits to the accumulator, most-significant first.
	 * @param int $value The value to append.
	 * @param int $bits The number of bits.
	 */
	private function pushBits(int $value, int $bits): void
	{
		$this->_bitBuffer = ($this->_bitBuffer << $bits) | $value;
		$this->_bitCount += $bits;
	}

	/**
	 * Pops whole bytes from the accumulator, most-significant first.
	 * @return string The completed bytes.
	 */
	private function drainBytes(): string
	{
		$out = '';
		while ($this->_bitCount >= 8) {
			$out .= chr(($this->_bitBuffer >> ($this->_bitCount - 8)) & 0xFF);
			$this->_bitCount -= 8;
			$this->_bitBuffer &= (1 << $this->_bitCount) - 1;
		}
		return $out;
	}

	/**
	 * Emits the final partial byte, zero-padding the unused low bits.
	 * @return string The padded byte, or '' when nothing is pending.
	 */
	private function flushBits(): string
	{
		if ($this->_bitCount === 0) {
			return '';
		}
		$byte = ($this->_bitBuffer << (8 - $this->_bitCount)) & 0xFF;
		$this->_bitBuffer = 0;
		$this->_bitCount = 0;
		return chr($byte);
	}
}
