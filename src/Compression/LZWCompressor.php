<?php

/**
 * LZWCompressor class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\Compression;

/**
 * LZWCompressor class.
 *
 * Implements variable-width LZW compression of the kind used by GIF and TIFF.  Codes
 * start at 9 bits and grow to a 12-bit ceiling; code 256 is the clear code (resets the
 * dictionary) and code 257 is end-of-information.  The stream begins with a clear code
 * and ends with end-of-information.  Codes are packed most-significant-bit first.
 *
 * The algorithm lives in the incremental {@see LZWEncoder}/{@see LZWDecoder} engines;
 * {@see encoder()}/{@see decoder()} return a fresh context (like PHP's {@see deflate_init()}/
 * {@see inflate_init()}), and the whole-string {@see compress()}/{@see decompress()} drive
 * one to completion.  Neither image wire format is decoded as-is: a GIF bitstream packs
 * codes least-significant-bit first, and standard TIFF LZW widens each code size one code
 * early (the TIFF 6 "EarlyChange" off-by-one); each needs its own variant path.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @see United States patent #4,558,302 (1983) by Unisys Corporation (expired 2003-2004).
 */
class LZWCompressor implements CompressorInterface
{
	/** @var int The initial code width in bits. */
	public const MinCodeSize = 9;

	/** @var int The maximum code width in bits. */
	public const MaxCodeSize = 12;

	/** @var int The clear code, which resets the dictionary. */
	public const ClearCode = 256;

	/** @var int The end-of-information code. */
	public const EndOfInformation = 257;

	/** @var int The first dynamic dictionary code. */
	public const FirstCode = 258;

	/**
	 * Returns a fresh incremental encoder context (like {@see deflate_init()}).
	 * @return LZWEncoder The encoder engine.
	 */
	public static function encoder(): LZWEncoder
	{
		return new LZWEncoder();
	}

	/**
	 * Returns a fresh incremental decoder context (like {@see inflate_init()}).
	 * @return LZWDecoder The decoder engine.
	 */
	public static function decoder(): LZWDecoder
	{
		return new LZWDecoder();
	}

	/**
	 * Compresses a byte string with variable-width LZW.
	 * @param string $data The raw bytes.
	 * @return string The LZW-encoded bytes.
	 */
	public static function compress(string $data): string
	{
		$encoder = new LZWEncoder();
		return $encoder->add($data) . $encoder->finish();
	}

	/**
	 * Decompresses an LZW byte string produced by {@see compress()}.
	 * @param string $data The LZW-encoded bytes.
	 * @throws \UnexpectedValueException When the data contains a code beyond the dictionary (corrupt input).
	 * @return string The decoded bytes.
	 */
	public static function decompress(string $data): string
	{
		$decoder = new LZWDecoder();
		return $decoder->add($data) . $decoder->finish();
	}
}
