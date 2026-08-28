<?php

/**
 * CompressorInterface interface file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\Compression;

/**
 * CompressorInterface interface.
 *
 * A whole-string compression codec: {@see compress()} encodes a byte string and
 * {@see decompress()} restores it.  Implementing it lets a caller select a compression
 * codec without binding to one algorithm, whether hand-written (LZW, run-length) or an
 * extension-gated wrapper (zstd, brotli).  An implementation may accept additional
 * optional parameters (a compression level, a format-specific setting) after the data.
 *
 * A codec that transforms incrementally, without holding the whole string, exposes a
 * {@see StreamCodec} context (an {@see LZWEncoder}, a {@see PackBitsDecoder}, ...); the
 * two are companion forms of the same algorithm, one whole-string and one streaming.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
interface CompressorInterface
{
	/**
	 * Compresses a byte string.
	 * @param string $data The raw bytes.
	 * @return string The encoded bytes.
	 */
	public static function compress(string $data): string;

	/**
	 * Decompresses a byte string produced by {@see compress()}.
	 * @param string $data The encoded bytes.
	 * @return string The decoded bytes.
	 */
	public static function decompress(string $data): string;
}
