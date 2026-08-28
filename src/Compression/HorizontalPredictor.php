<?php

/**
 * HorizontalPredictor class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\Compression;

/**
 * HorizontalPredictor class.
 *
 * Implements TIFF horizontal differencing (Predictor 2) for 8-bit samples: within each
 * row, every sample is replaced by its difference from the same channel of the previous
 * pixel, modulo 256.  Image rows differ little pixel to pixel, so the differenced bytes
 * cluster near zero and compress far better under LZW or Deflate; the predictor is a
 * companion transform, applied before compression and reversed after decompression.
 *
 * A row is `columns * samples` bytes: `columns` pixels of `samples` interleaved channels
 * (1 for grayscale, 3 for RGB).  Each row restarts the prediction, and a trailing partial
 * row transforms by the same rule.  {@see encode()} differences, {@see decode()}
 * accumulates.  The incremental form is {@see encoder()}/{@see decoder()} (the
 * {@see HorizontalPredictorEncoder}/{@see HorizontalPredictorDecoder} engines).
 *
 * ```php
 * $packed = LZWCompressor::compress(HorizontalPredictor::encode($rgb, $width, 3));
 * $rgb = HorizontalPredictor::decode(LZWCompressor::decompress($packed), $width, 3);
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @see https://download.osgeo.org/libtiff/doc/TIFF6.pdf TIFF 6.0, Section 14
 */
class HorizontalPredictor
{
	/**
	 * Returns a fresh incremental encoder context (like {@see deflate_init()}), buffering
	 * whole rows of the given geometry.
	 * @param int $columns The pixels per row.
	 * @param int $samples The interleaved channels per pixel. Default 1.
	 * @return HorizontalPredictorEncoder The encoder engine.
	 */
	public static function encoder(int $columns, int $samples = 1): HorizontalPredictorEncoder
	{
		return new HorizontalPredictorEncoder($columns, $samples);
	}

	/**
	 * Returns a fresh incremental decoder context (like {@see inflate_init()}), buffering
	 * whole rows of the given geometry.
	 * @param int $columns The pixels per row.
	 * @param int $samples The interleaved channels per pixel. Default 1.
	 * @return HorizontalPredictorDecoder The decoder engine.
	 */
	public static function decoder(int $columns, int $samples = 1): HorizontalPredictorDecoder
	{
		return new HorizontalPredictorDecoder($columns, $samples);
	}

	/**
	 * Applies horizontal differencing to rows of 8-bit samples.
	 * @param string $data The raw sample bytes, in row-major order.
	 * @param int $columns The pixels per row.
	 * @param int $samples The interleaved channels per pixel. Default 1.
	 * @return string The differenced bytes.
	 */
	public static function encode(string $data, int $columns, int $samples = 1): string
	{
		self::assertGeometry($columns, $samples);
		$rowBytes = $columns * $samples;
		$len = strlen($data);
		$out = '';
		for ($row = 0; $row < $len; $row += $rowBytes) {
			$end = min($row + $rowBytes, $len);
			$out .= substr($data, $row, min($samples, $end - $row));   // the first pixel is verbatim
			for ($i = $row + $samples; $i < $end; $i++) {
				$out .= chr((ord($data[$i]) - ord($data[$i - $samples])) & 0xFF);
			}
		}
		return $out;
	}

	/**
	 * Reverses horizontal differencing, accumulating each sample onto the previous pixel's
	 * channel.
	 * @param string $data The differenced bytes, in row-major order.
	 * @param int $columns The pixels per row.
	 * @param int $samples The interleaved channels per pixel. Default 1.
	 * @return string The raw sample bytes.
	 */
	public static function decode(string $data, int $columns, int $samples = 1): string
	{
		self::assertGeometry($columns, $samples);
		$rowBytes = $columns * $samples;
		$len = strlen($data);
		$out = $data;
		for ($row = 0; $row < $len; $row += $rowBytes) {
			$end = min($row + $rowBytes, $len);
			for ($i = $row + $samples; $i < $end; $i++) {
				$out[$i] = chr((ord($out[$i]) + ord($out[$i - $samples])) & 0xFF);
			}
		}
		return $out;
	}

	/**
	 * Validates the row geometry.
	 * @param int $columns The pixels per row.
	 * @param int $samples The channels per pixel.
	 * @throws \RuntimeException When either is not positive.
	 */
	private static function assertGeometry(int $columns, int $samples): void
	{
		if ($columns < 1 || $samples < 1) {
			throw new \RuntimeException(sprintf('The horizontal predictor requires positive columns and samples; columns \'%s\' and samples \'%s\' were given.', $columns, $samples));
		}
	}
}
