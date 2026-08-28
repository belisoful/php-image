<?php

/**
 * GIFLZWCompressor class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\Compression;

/**
 * GIFLZWCompressor class.
 *
 * Implements the GIF variant of variable-width LZW (GIF89a Appendix F): codes are packed
 * least-significant-bit first, and the code space is sized by a per-image minimum code
 * size N (2..8, the bits per pixel).  Root codes are `0..2^N-1`, the clear code is `2^N`,
 * end-of-information is `2^N+1`, and code widths run from N+1 to 12 bits.  The input and
 * output of the codec are the raw pixel-index bytes and the raw LZW bytes; the GIF file
 * framing (the 255-byte sub-blocks around the data) is the container's concern.
 *
 * Every input byte must fit the code space (be below `2^N`), since a GIF symbol is a
 * palette index.  The decoder tolerates a "deferred clear" encoder: when the dictionary
 * fills it stops adding entries and keeps decoding until a clear code arrives.
 * {@see LZWCompressor} is the most-significant-bit-first counterpart.
 *
 * ```php
 * $lzw = GIFLZWCompressor::compress($pixelIndexes, 8);
 * $pixels = GIFLZWCompressor::decompress($lzw, 8);
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @see https://www.w3.org/Graphics/GIF/spec-gif89a.txt
 */
class GIFLZWCompressor implements CompressorInterface
{
	/** @var int The smallest GIF minimum code size; GIF uses 2 even for 1-bit images. */
	public const MinMinCodeSize = 2;

	/** @var int The largest GIF minimum code size (8-bit palette indexes). */
	public const MaxMinCodeSize = 8;

	/** @var int The maximum code width in bits. */
	public const MaxCodeSize = 12;

	/**
	 * Compresses pixel-index bytes with GIF LZW.
	 * @param string $data The pixel-index bytes, each below `2^$minCodeSize`.
	 * @param int $minCodeSize The GIF minimum code size (2..8). Default 8.
	 * @throws \UnexpectedValueException When the minimum code size is out of range or a byte does not fit it.
	 * @return string The LZW-encoded bytes.
	 */
	public static function compress(string $data, int $minCodeSize = self::MaxMinCodeSize): string
	{
		self::assertMinCodeSize($minCodeSize);
		$clear = 1 << $minCodeSize;
		$eoi = $clear + 1;
		$width = $minCodeSize + 1;

		$buf = 0;
		$cnt = 0;
		$out = '';
		$emit = function (int $code) use (&$buf, &$cnt, &$out, &$width): void {
			$buf |= $code << $cnt;   // least-significant-bit first
			$cnt += $width;
			while ($cnt >= 8) {
				$out .= chr($buf & 0xFF);
				$buf >>= 8;
				$cnt -= 8;
			}
		};

		$emit($clear);
		$len = strlen($data);
		if ($len === 0) {
			$emit($eoi);
			return $out . ($cnt > 0 ? chr($buf & 0xFF) : '');
		}

		$dict = [];
		$next = $clear + 2;
		$first = $data[0];
		if (ord($first) >= $clear) {
			throw new \UnexpectedValueException(sprintf('GIF LZW symbol \'%s\' does not fit the %s-bit minimum code size.', ord($first), $minCodeSize));
		}
		$w = $first;
		for ($i = 1; $i < $len; $i++) {
			$c = $data[$i];
			if (ord($c) >= $clear) {
				throw new \UnexpectedValueException(sprintf('GIF LZW symbol \'%s\' does not fit the %s-bit minimum code size.', ord($c), $minCodeSize));
			}
			$candidate = $w . $c;
			if (isset($dict[$candidate])) {
				$w = $candidate;
				continue;
			}
			$emit(strlen($w) === 1 ? ord($w) : $dict[$w]);
			$dict[$candidate] = $next++;
			$w = $c;
			if ($next - 1 === (1 << $width) && $width < self::MaxCodeSize) {
				$width++;      // the just-added entry needs the wider code
			} elseif ($next === (1 << self::MaxCodeSize)) {
				$emit($clear); // the dictionary is full; reset
				$dict = [];
				$next = $clear + 2;
				$width = $minCodeSize + 1;
			}
		}
		$emit(strlen($w) === 1 ? ord($w) : $dict[$w]);
		$emit($eoi);
		return $out . ($cnt > 0 ? chr($buf & 0xFF) : '');
	}

	/**
	 * Decompresses GIF LZW bytes produced by {@see compress()} or read from a GIF image.
	 * The data ending without end-of-information decodes to the codes present, since some
	 * encoders rely on the block framing alone.
	 * @param string $data The LZW-encoded bytes.
	 * @param int $minCodeSize The GIF minimum code size (2..8). Default 8.
	 * @throws \UnexpectedValueException When the minimum code size is out of range or the data contains a
	 *   code beyond the dictionary (corrupt input).
	 * @return string The decoded pixel-index bytes.
	 */
	public static function decompress(string $data, int $minCodeSize = self::MaxMinCodeSize): string
	{
		self::assertMinCodeSize($minCodeSize);
		$clear = 1 << $minCodeSize;
		$eoi = $clear + 1;
		$width = $minCodeSize + 1;
		$dict = self::baseDictionary($clear);
		$next = $clear + 2;
		$prev = null;
		$out = '';
		$buf = 0;
		$cnt = 0;
		$len = strlen($data);

		for ($i = 0; $i < $len; $i++) {
			$buf |= ord($data[$i]) << $cnt;   // least-significant-bit first
			$cnt += 8;
			while ($cnt >= $width) {
				$code = $buf & ((1 << $width) - 1);
				$buf >>= $width;
				$cnt -= $width;
				if ($code === $eoi) {
					return $out;
				}
				if ($code === $clear) {
					$dict = self::baseDictionary($clear);
					$next = $clear + 2;
					$width = $minCodeSize + 1;
					$prev = null;
					continue;
				}
				if ($prev === null) {
					if (!isset($dict[$code])) {
						throw new \UnexpectedValueException(sprintf('LZW data contains code \'%s\' but the dictionary extends only to code \'%s\'.', $code, $next));
					}
					$out .= $dict[$code];
					$prev = $code;
					continue;
				}
				if (isset($dict[$code])) {
					$entry = $dict[$code];
				} elseif ($code === $next && $next < (1 << self::MaxCodeSize)) {
					// The one legal not-yet-defined code (the encoder's just-added entry).
					$entry = $dict[$prev] . $dict[$prev][0];
				} else {
					throw new \UnexpectedValueException(sprintf('LZW data contains code \'%s\' but the dictionary extends only to code \'%s\'.', $code, $next));
				}
				$out .= $entry;
				if ($next < (1 << self::MaxCodeSize)) {   // a full dictionary defers to the next clear
					$dict[$next++] = $dict[$prev] . $entry[0];
					// The decoder's dictionary lags the encoder's by one entry, so it widens one
					// entry earlier than the encoder's own bump to read the next code correctly.
					if ($next === (1 << $width) && $width < self::MaxCodeSize) {
						$width++;
					}
				}
				$prev = $code;
			}
		}
		return $out;
	}

	/**
	 * Validates a GIF minimum code size.
	 * @param int $minCodeSize The minimum code size to check.
	 * @throws \InvalidArgumentException When it is outside 2..8.
	 */
	private static function assertMinCodeSize(int $minCodeSize): void
	{
		if ($minCodeSize < self::MinMinCodeSize || $minCodeSize > self::MaxMinCodeSize) {
			throw new \RuntimeException(sprintf('GIF LZW minimum code size \'%s\' must be between 2 and 8.', $minCodeSize));
		}
	}

	/**
	 * Builds the initial root dictionary for the code space.
	 * @param int $clear The clear code (`2^minCodeSize`), bounding the root symbols.
	 * @return array<int, string> The code-to-string dictionary.
	 */
	private static function baseDictionary(int $clear): array
	{
		$dict = [];
		for ($c = 0; $c < $clear; $c++) {
			$dict[$c] = chr($c);
		}
		return $dict;
	}
}
