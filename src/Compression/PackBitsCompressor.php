<?php

/**
 * PackBitsCompressor class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\Compression;

/**
 * PackBitsCompressor class.
 *
 * Implements the PackBits run-length codec used by Apple MacPaint and TIFF.  The
 * stream is a sequence of packets, each a one-byte header followed by data:
 *
 * | Header n   | Meaning                                              |
 * |------------|------------------------------------------------------|
 * | 0..127     | The next n+1 bytes are literal.                      |
 * | 129..255   | The next single byte repeats 257-n times (2..128).   |
 * | 128        | No-op (skipped).                                     |
 *
 * The algorithm lives in the incremental {@see PackBitsEncoder}/{@see PackBitsDecoder}
 * engines; {@see encoder()}/{@see decoder()} return a fresh context (like PHP's
 * {@see deflate_init()}/{@see inflate_init()}), and the whole-string {@see compress()}/
 * {@see decompress()} drive one to completion.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class PackBitsCompressor implements CompressorInterface
{
	/** @var int The maximum literal or repeat run length in one packet. */
	public const MaxRun = 128;

	/**
	 * Returns a fresh incremental encoder context (like {@see deflate_init()}).
	 * @return PackBitsEncoder The encoder engine.
	 */
	public static function encoder(): PackBitsEncoder
	{
		return new PackBitsEncoder();
	}

	/**
	 * Returns a fresh incremental decoder context (like {@see inflate_init()}).
	 * @return PackBitsDecoder The decoder engine.
	 */
	public static function decoder(): PackBitsDecoder
	{
		return new PackBitsDecoder();
	}

	/**
	 * Compresses a byte string with PackBits run-length encoding.
	 * @param string $data The raw bytes.
	 * @return string The PackBits-encoded bytes.
	 */
	public static function compress(string $data): string
	{
		$encoder = new PackBitsEncoder();
		return $encoder->add($data) . $encoder->finish();
	}

	/**
	 * Decompresses a PackBits byte string.  A truncated final packet decodes to the bytes
	 * of its complete packets; RLE carries no end marker, so tolerance matches the format.
	 * @param string $data The PackBits-encoded bytes.
	 * @return string The decoded bytes.
	 */
	public static function decompress(string $data): string
	{
		$decoder = new PackBitsDecoder();
		return $decoder->add($data) . $decoder->finish();
	}
}
