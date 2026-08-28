<?php

/**
 * LZWDecoder class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\Compression;

/**
 * LZWDecoder class.
 *
 * The incremental decoder half of the variable-width LZW codec (see {@see LZWCompressor}
 * for the wire format).  It is a {@see StreamCodec}: {@see add()} reads variable-width
 * codes as enough bits accumulate and stops at end-of-information; bytes arriving after it
 * are ignored, so trailing padding split across chunks is tolerated.  {@see finish()}
 * carries nothing.  The only state held is the dictionary (bounded at the 4096-code
 * ceiling) and a sub-byte bit accumulator.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class LZWDecoder implements StreamCodec
{
	/** @var int The pending bits, most-significant first. */
	private int $_bitBuffer = 0;

	/** @var int The count of valid bits in the bit buffer. */
	private int $_bitCount = 0;

	/** @var int The current code width in bits. */
	private int $_codeSize = LZWCompressor::MinCodeSize;

	/** @var int The next dictionary code to assign. */
	private int $_next = LZWCompressor::FirstCode;

	/** @var bool Whether the dictionary has been seeded. */
	private bool $_started = false;

	/** @var array<int, string> The dictionary (code to string). */
	private array $_dict = [];

	/** @var ?int The previous code. */
	private ?int $_prev = null;

	/** @var bool Whether end-of-information has been read. */
	private bool $_finished = false;

	/**
	 * Decodes a chunk, reading variable-width codes as enough bits accumulate.
	 * @param string $data The encoded chunk.
	 * @throws \UnexpectedValueException When the data contains a code beyond the dictionary (corrupt input).
	 * @return string The decoded bytes produced from this chunk.
	 */
	public function add(string $data): string
	{
		if (!$this->_started) {
			$this->resetDictionary();
			$this->_started = true;
		}
		if ($this->_finished) {
			return '';
		}
		// Pull state into locals and inline the bit unpacking and code expansion (the per-code
		// method call and property access dominate this hot loop).
		$out = '';
		$buf = $this->_bitBuffer;
		$cnt = $this->_bitCount;
		$codeSize = $this->_codeSize;
		$next = $this->_next;
		$prev = $this->_prev;
		$dict = $this->_dict;
		$finished = false;
		$len = strlen($data);
		for ($i = 0; $i < $len; $i++) {
			$buf = ($buf << 8) | ord($data[$i]);
			$cnt += 8;
			while ($cnt >= $codeSize) {
				$cnt -= $codeSize;
				$code = ($buf >> $cnt) & ((1 << $codeSize) - 1);
				$buf &= (1 << $cnt) - 1;
				if ($code === LZWCompressor::EndOfInformation) {
					$finished = true;
					break 2;
				}
				if ($code === LZWCompressor::ClearCode) {
					$dict = [];
					for ($k = 0; $k < 256; $k++) {
						$dict[$k] = chr($k);
					}
					$next = LZWCompressor::FirstCode;
					$codeSize = LZWCompressor::MinCodeSize;
					$prev = null;
					continue;
				}
				if ($prev === null) {
					if (!isset($dict[$code])) {
						throw new \UnexpectedValueException(sprintf('LZW data contains code \'%s\' but the dictionary extends only to code \'%s\'.', $code, $next));
					}
					$prev = $code;
					$out .= $dict[$code];
					continue;
				}
				if (isset($dict[$code])) {
					$entry = $dict[$code];
				} elseif ($code === $next) {
					// The one legal not-yet-defined code (the encoder's just-added entry).
					$entry = $dict[$prev] . $dict[$prev][0];
				} else {
					throw new \UnexpectedValueException(sprintf('LZW data contains code \'%s\' but the dictionary extends only to code \'%s\'.', $code, $next));
				}
				$dict[$next++] = $dict[$prev] . $entry[0];
				$prev = $code;
				if ($next + 1 === (1 << $codeSize) && $codeSize < LZWCompressor::MaxCodeSize) {
					$codeSize++;
				}
				$out .= $entry;
			}
		}
		$this->_bitBuffer = $buf;
		$this->_bitCount = $cnt;
		$this->_codeSize = $codeSize;
		$this->_next = $next;
		$this->_prev = $prev;
		$this->_dict = $dict;
		if ($finished) {
			$this->_finished = true;
		}
		return $out;
	}

	/**
	 * The decoder carries no trailing state.
	 * @return string Always ''.
	 */
	public function finish(): string
	{
		return '';
	}

	/**
	 * Seeds the dictionary, next code, and code width to their initial state.
	 */
	private function resetDictionary(): void
	{
		$this->_dict = [];
		for ($c = 0; $c < 256; $c++) {
			$this->_dict[$c] = chr($c);
		}
		$this->_next = LZWCompressor::FirstCode;
		$this->_codeSize = LZWCompressor::MinCodeSize;
	}
}
