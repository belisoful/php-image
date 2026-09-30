<?php

/**
 * TIFFRaster class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\TIFF;

use Belisoful\Image\Compression\CCITTFaxCompressor;
use Belisoful\Image\Compression\HorizontalPredictor;
use Belisoful\Image\Compression\LZWCompressor;
use Belisoful\Image\Compression\PackBitsCompressor;

/**
 * TIFFRaster class.
 *
 * Decodes a baseline TIFF raster to RGB24 pixels.  It reads both organizations —
 * strips (`StripOffsets`) and tiles (`TileOffsets`, blitting each padded tile into
 * place) — in either planar configuration (chunky and separate planes), in either byte
 * order and either fill order (the reversed order is bit-mirrored per byte), through the
 * uncompressed, PackBits, LZW, and CCITT fax codings, undoing the horizontal predictor at
 * any whole-byte sample width and the floating-point predictor.
 *
 * Every `SampleFormat` with a meaning as a colour converts: unsigned integers at 1, 2, 4,
 * 8, 16, and 32 bits; signed integers at 8, 16, and 32, offset so the most negative value
 * is black; and IEEE floating point at 16 (half), 24, 32, and 64 bits, scaled from
 * `SMinSampleValue`–`SMaxSampleValue` or 0.0–1.0 when those are absent, and clamped.  The
 * "undefined" format is read as unsigned, as the specification directs.  Wider samples are
 * reduced to their most significant byte.
 *
 * Every baseline and extension photometric interpretation converts: white-is-zero and
 * black-is-zero grayscale, RGB, palette color (through the `ColorMap`), `Separated`
 * CMYK, `YCbCr` (honoring `YCbCrSubSampling`, including the subsampled unit layout),
 * and CIE/ICC `L*a*b*`.  A raster whose form is genuinely outside this set answers null
 * rather than guessing: complex samples, samples of mixed formats or depths, a palette
 * index that is not an unsigned byte or less, and an L*a*b* sample that is not unsigned,
 * since that photometric already defines the signs of a* and b*.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TIFFRaster
{
	/** The PhotometricInterpretation of a white-is-zero grayscale raster. */
	public const WhiteIsZero = 0;

	/** The PhotometricInterpretation of a black-is-zero grayscale raster. */
	public const BlackIsZero = 1;

	/** The PhotometricInterpretation of an RGB raster. */
	public const Rgb = 2;

	/** The PhotometricInterpretation of a palette-color raster. */
	public const Palette = 3;

	/** The PhotometricInterpretation of a transparency mask. */
	public const Mask = 4;

	/** The PhotometricInterpretation of a separated (CMYK) raster. */
	public const Separated = 5;

	/** The PhotometricInterpretation of a YCbCr raster. */
	public const YCbCr = 6;

	/** The PhotometricInterpretation of a CIE L*a*b* raster. */
	public const CieLab = 8;

	/** The PhotometricInterpretation of an ICC L*a*b* raster. */
	public const ICCLab = 9;

	/** The SampleFormat of unsigned integer samples, the default. */
	public const FormatUnsigned = 1;

	/** The SampleFormat of two's-complement signed integer samples. */
	public const FormatSigned = 2;

	/** The SampleFormat of IEEE floating-point samples. */
	public const FormatFloat = 3;

	/** The SampleFormat the specification leaves undefined, to be read as unsigned. */
	public const FormatUndefined = 4;

	/**
	 * The bits per sample each decodable SampleFormat admits.  The complex formats (5 and
	 * 6) are absent: a complex sample has no colour to show.
	 */
	public const FormatDepths = [
		self::FormatUnsigned => [1, 2, 4, 8, 16, 32],
		self::FormatSigned => [8, 16, 32],
		self::FormatFloat => [16, 24, 32, 64],
	];

	/**
	 * Decodes an IFD's raster to RGB24 pixels, row-major, three bytes per pixel.
	 * @param TIFFIfd $ifd The image IFD, carrying its strip or tile data as the
	 *   offsets tag's {@see TIFFTag::getExternalData() external data}.
	 * @param int $width The image width in pixels.
	 * @param int $height The image height in pixels.
	 * @param bool $bigEndian Whether the document is big-endian (MM), the order its
	 *   multi-byte samples are stored in.
	 * @return ?string The RGB pixel bytes, or null when the raster form is unsupported.
	 */
	public static function toRgb(TIFFIfd $ifd, int $width, int $height, bool $bigEndian): ?string
	{
		if ($width < 1 || $height < 1) {
			return null;
		}
		$geometry = static::readGeometry($ifd, $width, $height, $bigEndian);
		if ($geometry === null) {
			return null;
		}

		if ($geometry['photometric'] === self::YCbCr && $geometry['subsampling'] !== [1, 1]) {
			return static::decodeSubsampledYCbCr($ifd, $geometry, $width, $height);
		}

		$samples = static::decodeSamples($ifd, $geometry, $width, $height);
		if ($samples === null) {
			return null;
		}
		return static::samplesToRgb($samples, $geometry, $width, $height, $ifd);
	}

	/**
	 * Reads the raster geometry tags, rejecting the forms this decoder does not model.
	 * @param TIFFIfd $ifd The image IFD.
	 * @param int $width The image width.
	 * @param int $height The image height.
	 * @param bool $bigEndian Whether multi-byte samples are big-endian.
	 * @return ?array The geometry, or null when unsupported.
	 */
	protected static function readGeometry(TIFFIfd $ifd, int $width, int $height, bool $bigEndian): ?array
	{
		$bitsTag = $ifd->getTag(258)?->getValues();
		$bitsList = is_array($bitsTag) ? array_values($bitsTag) : [$bitsTag ?? 1];
		$bits = (int) ($bitsList[0] ?? 1);
		foreach ($bitsList as $depth) {
			if ((int) $depth !== $bits) {
				return null;   // mixed sample depths
			}
		}
		$formats = array_values((array) ($ifd->getTag(339)?->getValues() ?? []));
		$format = (int) ($formats[0] ?? self::FormatUnsigned);
		foreach ($formats as $each) {
			if ((int) $each !== $format) {
				return null;   // mixed sample formats
			}
		}
		if ($format === self::FormatUndefined) {
			$format = self::FormatUnsigned;
		}
		if (!in_array($bits, self::FormatDepths[$format] ?? [], true)) {
			return null;
		}
		$photometric = (int) ($ifd->getTagValue(262) ?? self::BlackIsZero);
		if ($photometric === self::Palette && ($format !== self::FormatUnsigned || $bits > 8)) {
			return null;   // an index is an unsigned byte or less
		}
		if (($photometric === self::CieLab || $photometric === self::ICCLab) && $format !== self::FormatUnsigned) {
			return null;   // the photometric already says which of L*, a*, b* are signed
		}
		$predictor = (int) ($ifd->getTagValue(317) ?? 1);
		$predictable = match ($predictor) {
			1 => true,
			2 => $bits >= 8,                       // differences of whole-byte samples
			3 => $format === self::FormatFloat,    // the floating-point predictor
			default => false,
		};
		if (!$predictable) {
			return null;
		}
		$samples = max(1, (int) ($ifd->getTagValue(277) ?? 1));
		$planar = (int) ($ifd->getTagValue(284) ?? 1);
		if ($planar !== 1 && $planar !== 2) {
			return null;
		}
		$subsampling = $ifd->getTag(530)?->getValues();
		$subsampling = is_array($subsampling) && count($subsampling) >= 2
			? [(int) $subsampling[0], (int) $subsampling[1]]
			: [1, 1];

		return [
			'compression' => (int) ($ifd->getTagValue(259) ?? 1),
			'photometric' => $photometric,
			'bits' => $bits,
			'format' => $format,
			'bigEndian' => $bigEndian,
			'range' => $format === self::FormatFloat ? static::sampleRange($ifd, $samples) : [],
			'samples' => $samples,
			'planar' => $planar,
			'fillOrder' => (int) ($ifd->getTagValue(266) ?? 1),
			't4options' => (int) ($ifd->getTagValue(292) ?? 0),
			'predictor' => $predictor,
			'rowsPerStrip' => (int) ($ifd->getTagValue(278) ?? $height),
			'tileWidth' => (int) ($ifd->getTagValue(322) ?? 0),
			'tileLength' => (int) ($ifd->getTagValue(323) ?? 0),
			'subsampling' => $subsampling,
			'maxValue' => $bits >= 8 ? 255 : (1 << $bits) - 1,
		];
	}

	/**
	 * Reads the range each floating-point sample is scaled from: `SMinSampleValue` and
	 * `SMaxSampleValue`, one value per sample or one for all, else 0.0 to 1.0.  A range
	 * that is empty or inverted scales nothing, so it too falls back to 0.0 to 1.0.
	 * @param TIFFIfd $ifd The image IFD.
	 * @param int $samples The samples per pixel.
	 * @return array<int, array{float, float}> The [low, high] pair of each sample.
	 */
	protected static function sampleRange(TIFFIfd $ifd, int $samples): array
	{
		$lows = array_values((array) ($ifd->getTag(340)?->getValues() ?? []));
		$highs = array_values((array) ($ifd->getTag(341)?->getValues() ?? []));
		$range = [];
		for ($sample = 0; $sample < $samples; $sample++) {
			$low = (float) ($lows[$sample] ?? $lows[0] ?? 0.0);
			$high = (float) ($highs[$sample] ?? $highs[0] ?? 1.0);
			$range[] = $high > $low ? [$low, $high] : [0.0, 1.0];
		}
		return $range;
	}

	/**
	 * Decodes every block into one interleaved buffer of samples, one byte per sample
	 * (wider samples are reduced to their most significant byte).
	 * @param TIFFIfd $ifd The image IFD.
	 * @param array $geometry The {@see readGeometry()} geometry.
	 * @param int $width The image width.
	 * @param int $height The image height.
	 * @return ?string The interleaved sample bytes, or null when the data is unusable.
	 */
	protected static function decodeSamples(TIFFIfd $ifd, array $geometry, int $width, int $height): ?string
	{
		$planes = $geometry['planar'] === 2 ? $geometry['samples'] : 1;
		$perPlane = $geometry['planar'] === 2 ? 1 : $geometry['samples'];
		$blocks = static::gatherBlocks($ifd, $geometry, $width, $height, $planes);
		if ($blocks === null) {
			return null;
		}

		$planeData = [];
		foreach (range(0, $planes - 1) as $plane) {
			$planeData[$plane] = str_repeat("\0", $width * $height * $perPlane);
		}
		foreach ($blocks as $block) {
			$decoded = static::decodeBlock($block['data'], $geometry, $block['width'], $block['rows'], $perPlane, $block['plane']);
			if ($decoded === null) {
				return null;
			}
			static::blit($planeData[$block['plane']], $decoded, $block, $width, $height, $perPlane);
		}
		if ($planes === 1) {
			return $planeData[0];
		}

		// Interleave the separate planes into chunky samples.
		$out = str_repeat("\0", $width * $height * $geometry['samples']);
		for ($plane = 0; $plane < $planes; $plane++) {
			for ($i = 0, $pixels = $width * $height; $i < $pixels; $i++) {
				$out[$i * $geometry['samples'] + $plane] = $planeData[$plane][$i];
			}
		}
		return $out;
	}

	/**
	 * Gathers the strip or tile blocks with their positions in the raster.
	 * @param TIFFIfd $ifd The image IFD.
	 * @param array $geometry The geometry.
	 * @param int $width The image width.
	 * @param int $height The image height.
	 * @param int $planes The plane count.
	 * @return ?array The blocks, or null when the raster data is absent.
	 */
	protected static function gatherBlocks(TIFFIfd $ifd, array $geometry, int $width, int $height, int $planes): ?array
	{
		$blocks = [];
		$tileWidth = $geometry['tileWidth'];
		$tileLength = $geometry['tileLength'];
		if ($tileWidth > 0 && $tileLength > 0) {
			$tiles = $ifd->getTag(324)?->getExternalData();
			if ($tiles === null) {
				return null;
			}
			$across = (int) ceil($width / $tileWidth);
			$down = (int) ceil($height / $tileLength);
			$perPlaneCount = $across * $down;
			foreach ($tiles as $index => $data) {
				$plane = $planes > 1 ? intdiv($index, $perPlaneCount) : 0;
				$within = $planes > 1 ? $index % $perPlaneCount : $index;
				$blocks[] = [
					'data' => $data,
					'plane' => $plane,
					'x' => ($within % $across) * $tileWidth,
					'y' => intdiv($within, $across) * $tileLength,
					'width' => $tileWidth,
					'rows' => $tileLength,
				];
			}
			return $blocks;
		}

		$strips = $ifd->getTag(273)?->getExternalData();
		if ($strips === null) {
			return null;
		}
		$rowsPerStrip = max(1, $geometry['rowsPerStrip']);
		$perPlaneCount = (int) ceil($height / $rowsPerStrip);
		foreach ($strips as $index => $data) {
			$plane = $planes > 1 ? intdiv($index, $perPlaneCount) : 0;
			$within = $planes > 1 ? $index % $perPlaneCount : $index;
			$y = $within * $rowsPerStrip;
			$blocks[] = [
				'data' => $data,
				'plane' => $plane,
				'x' => 0,
				'y' => $y,
				'width' => $width,
				'rows' => min($rowsPerStrip, max(0, $height - $y)),
			];
		}
		return $blocks;
	}

	/**
	 * Decompresses one block and normalizes it to one byte per sample.
	 * @param string $data The block bytes.
	 * @param array $geometry The geometry.
	 * @param int $blockWidth The block width in pixels.
	 * @param int $rows The block height in rows.
	 * @param int $perPlane The samples per pixel in this plane.
	 * @param int $plane The plane, which is the first sample's index within the pixel.
	 * @return ?string The block's sample bytes, or null when unsupported.
	 */
	protected static function decodeBlock(string $data, array $geometry, int $blockWidth, int $rows, int $perPlane, int $plane = 0): ?string
	{
		$bits = $geometry['bits'];
		$rowBytes = intdiv($blockWidth * $bits * $perPlane + 7, 8);

		switch ($geometry['compression']) {
			case 1:
				break;
			case 2:
			case 3:
			case 4:
				if ($bits !== 1 || $perPlane !== 1) {
					return null;
				}
				$mode = match ($geometry['compression']) {
					2 => CCITTFaxCompressor::ModifiedHuffman,
					3 => (($geometry['t4options'] ?? 0) & 1) ? CCITTFaxCompressor::Group3TwoD : CCITTFaxCompressor::Group3,
					default => CCITTFaxCompressor::Group4,
				};
				$data = (new CCITTFaxCompressor($blockWidth, $mode))->decode($data, $rows);
				break;
			case 5:
				$data = LZWCompressor::decompress($data);
				break;
			case 32773:
				$data = PackBitsCompressor::decompress($data);
				break;
			default:
				return null;
		}

		if ($geometry['fillOrder'] === 2) {
			$data = static::reverseBits($data);
		}
		$perRow = $blockWidth * $perPlane;
		$bigEndian = $geometry['bigEndian'];
		if ($geometry['predictor'] === 2) {
			$data = $bits === 8
				? HorizontalPredictor::decode($data, $blockWidth, $perPlane)
				: static::undoPredictor($data, intdiv($bits, 8), $bigEndian, $perRow, $perPlane, $rows);
		} elseif ($geometry['predictor'] === 3) {
			$data = static::undoFloatPredictor($data, intdiv($bits, 8), $perRow, $perPlane, $rows);
			$bigEndian = true;
		}
		if ($bits < 8 || ($bits === 8 && $geometry['format'] === self::FormatUnsigned)) {
			return static::unpackSamples($data, $bits, $rowBytes, $rows, $perRow);
		}
		return static::reduceSamples($data, ['bigEndian' => $bigEndian] + $geometry, $rows, $perRow, $perPlane, $plane);
	}

	/**
	 * Undoes the horizontal predictor on samples wider than a byte: each sample was
	 * stored as its difference from the same sample of the pixel before, so each is
	 * restored by adding that one back, carried byte by byte from the least significant.
	 * @param string $data The differenced rows.
	 * @param int $bytes The bytes per sample.
	 * @param bool $bigEndian Whether the samples are big-endian.
	 * @param int $perRow The samples per row.
	 * @param int $stride The samples per pixel.
	 * @param int $rows The row count.
	 * @return string The restored rows.
	 */
	protected static function undoPredictor(string $data, int $bytes, bool $bigEndian, int $perRow, int $stride, int $rows): string
	{
		$rowBytes = $perRow * $bytes;
		$data = str_pad($data, $rowBytes * $rows, "\0");
		for ($y = 0; $y < $rows; $y++) {
			for ($i = $stride; $i < $perRow; $i++) {
				$at = $y * $rowBytes + $i * $bytes;
				$from = $at - $stride * $bytes;
				$carry = 0;
				for ($k = 0; $k < $bytes; $k++) {
					$byte = $bigEndian ? $bytes - 1 - $k : $k;
					$sum = ord($data[$at + $byte]) + ord($data[$from + $byte]) + $carry;
					$data[$at + $byte] = chr($sum & 0xFF);
					$carry = $sum >> 8;
				}
			}
		}
		return $data;
	}

	/**
	 * Undoes the floating-point predictor (Adobe Photoshop TIFF Technical Note 3): each
	 * row's samples were split into byte planes, most significant first, and the whole row
	 * then differenced byte by byte across the pixel.  The samples come back big-endian,
	 * whatever the document's byte order, because the planes are in that order.
	 * @param string $data The predicted rows.
	 * @param int $bytes The bytes per sample.
	 * @param int $perRow The samples per row.
	 * @param int $stride The samples per pixel.
	 * @param int $rows The row count.
	 * @return string The big-endian sample rows.
	 */
	protected static function undoFloatPredictor(string $data, int $bytes, int $perRow, int $stride, int $rows): string
	{
		$rowBytes = $perRow * $bytes;
		$data = str_pad($data, $rowBytes * $rows, "\0");
		$out = '';
		for ($y = 0; $y < $rows; $y++) {
			$row = substr($data, $y * $rowBytes, $rowBytes);
			for ($i = $stride; $i < $rowBytes; $i++) {
				$row[$i] = chr((ord($row[$i]) + ord($row[$i - $stride])) & 0xFF);
			}
			for ($i = 0; $i < $perRow; $i++) {
				for ($plane = 0; $plane < $bytes; $plane++) {
					$out .= $row[$plane * $perRow + $i];
				}
			}
		}
		return $out;
	}

	/**
	 * Reduces whole-byte samples of any format to one unsigned byte each: an unsigned
	 * sample to its most significant byte, a signed one to that byte with its sign bit
	 * flipped (so the most negative value is 0 and zero is 128), and a floating-point one
	 * scaled from its sample range and clamped.
	 * @param string $data The sample rows, unpadded.
	 * @param array $geometry The geometry, its byte order that of these samples.
	 * @param int $rows The row count.
	 * @param int $perRow The samples per row.
	 * @param int $perPlane The samples per pixel in this plane.
	 * @param int $plane The plane, the first sample's index within the pixel.
	 * @return string The sample bytes.
	 */
	protected static function reduceSamples(string $data, array $geometry, int $rows, int $perRow, int $perPlane, int $plane): string
	{
		$bytes = intdiv($geometry['bits'], 8);
		$count = $rows * $perRow;
		$data = str_pad($data, $count * $bytes, "\0");
		$out = '';
		if ($geometry['format'] !== self::FormatFloat) {
			$high = $geometry['bigEndian'] ? 0 : $bytes - 1;
			$flip = $geometry['format'] === self::FormatSigned ? 0x80 : 0;
			for ($i = 0; $i < $count; $i++) {
				$out .= chr(ord($data[$i * $bytes + $high]) ^ $flip);
			}
			return $out;
		}
		for ($i = 0; $i < $count; $i++) {
			$sample = substr($data, $i * $bytes, $bytes);
			$value = static::floatSample($geometry['bigEndian'] ? $sample : strrev($sample));
			[$low, $high] = $geometry['range'][$plane + $i % $perPlane] ?? [0.0, 1.0];
			$scaled = ($value - $low) / ($high - $low);
			$out .= chr(is_nan($scaled) ? 0 : (int) round(max(0.0, min(1.0, $scaled)) * 255));
		}
		return $out;
	}

	/**
	 * Reads one big-endian IEEE floating-point sample.  The 16-bit form is the standard
	 * half (5 exponent bits); the 24-bit form is the one Adobe writes (7 exponent bits,
	 * 16 of fraction), which PHP's pack() has no code for, so both small forms are
	 * assembled from their fields.
	 * @param string $bytes The sample's 2, 3, 4, or 8 bytes, most significant first.
	 * @return float The value; infinities and NaN included.
	 */
	protected static function floatSample(string $bytes): float
	{
		$width = strlen($bytes);
		if ($width === 4) {
			return \unpack('G', $bytes)[1];
		}
		if ($width === 8) {
			return \unpack('E', $bytes)[1];
		}
		$bits = $width * 8;
		$exponentBits = $bits === 16 ? 5 : 7;
		$fractionBits = $bits - 1 - $exponentBits;
		$word = \unpack('N', str_pad($bytes, 4, "\0", STR_PAD_LEFT))[1];
		$sign = ($word >> ($bits - 1)) === 1 ? -1.0 : 1.0;
		$exponent = ($word >> $fractionBits) & ((1 << $exponentBits) - 1);
		$fraction = (float) ($word & ((1 << $fractionBits) - 1)) / (1 << $fractionBits);
		$bias = (1 << ($exponentBits - 1)) - 1;
		if ($exponent === 0) {
			return $sign * $fraction * 2 ** (1 - $bias);   // subnormal
		}
		if ($exponent === (1 << $exponentBits) - 1) {
			return $fraction === 0.0 ? $sign * INF : NAN;
		}
		return $sign * (1 + $fraction) * 2 ** ($exponent - $bias);
	}

	/**
	 * Mirrors the bits of every byte, for the reversed fill order.
	 * @param string $data The bytes.
	 * @return string The bit-reversed bytes.
	 */
	protected static function reverseBits(string $data): string
	{
		static $table = null;
		if ($table === null) {
			$table = [];
			for ($i = 0; $i < 256; $i++) {
				$table[chr($i)] = chr(((($i * 0x0802) & 0x22110) | (($i * 0x8020) & 0x88440)) * 0x10101 >> 16 & 0xFF);
			}
		}
		return strtr($data, $table);
	}

	/**
	 * Expands packed unsigned rows of a byte or less per sample to one byte per sample.
	 * @param string $data The packed rows.
	 * @param int $bits The bits per sample: 1, 2, 4, or 8.
	 * @param int $rowBytes The packed row stride.
	 * @param int $rows The row count.
	 * @param int $perRow The samples per row.
	 * @return ?string The sample bytes, or null when the data is short.
	 */
	protected static function unpackSamples(string $data, int $bits, int $rowBytes, int $rows, int $perRow): ?string
	{
		if ($bits === 8) {
			$out = '';
			for ($y = 0; $y < $rows; $y++) {
				$row = substr($data, $y * $rowBytes, $perRow);
				$out .= str_pad($row, $perRow, "\0");
			}
			return $out;
		}
		$perByte = intdiv(8, $bits);
		$mask = (1 << $bits) - 1;
		$out = '';
		for ($y = 0; $y < $rows; $y++) {
			for ($i = 0; $i < $perRow; $i++) {
				$byte = ord($data[$y * $rowBytes + intdiv($i, $perByte)] ?? "\0");
				$shift = 8 - $bits * (($i % $perByte) + 1);
				$out .= chr(($byte >> $shift) & $mask);
			}
		}
		return $out;
	}

	/**
	 * Copies a decoded block's rows into its place in the plane buffer, clipping the
	 * padding of an edge tile.
	 * @param string &$plane The plane buffer.
	 * @param string $block The block's sample bytes.
	 * @param array $position The block position and geometry.
	 * @param int $width The image width.
	 * @param int $height The image height.
	 * @param int $perPlane The samples per pixel in this plane.
	 */
	protected static function blit(string &$plane, string $block, array $position, int $width, int $height, int $perPlane): void
	{
		$copyWidth = min($position['width'], $width - $position['x']);
		if ($copyWidth < 1) {
			return;
		}
		$copyBytes = $copyWidth * $perPlane;
		$blockStride = $position['width'] * $perPlane;
		$imageStride = $width * $perPlane;
		for ($row = 0; $row < $position['rows']; $row++) {
			$y = $position['y'] + $row;
			if ($y >= $height) {
				break;
			}
			$source = substr($block, $row * $blockStride, $copyBytes);
			if ($source === '') {
				break;
			}
			$plane = substr_replace(
				$plane,
				str_pad($source, $copyBytes, "\0"),
				$y * $imageStride + $position['x'] * $perPlane,
				$copyBytes,
			);
		}
	}

	/**
	 * Converts interleaved samples to RGB by the photometric interpretation.
	 * @param string $samples The interleaved sample bytes.
	 * @param array $geometry The geometry.
	 * @param int $width The image width.
	 * @param int $height The image height.
	 * @param TIFFIfd $ifd The image IFD (for the color map).
	 * @return ?string The RGB pixel bytes, or null when unsupported.
	 */
	protected static function samplesToRgb(string $samples, array $geometry, int $width, int $height, TIFFIfd $ifd): ?string
	{
		$count = $width * $height;
		$perPixel = $geometry['samples'];
		$max = max(1, $geometry['maxValue']);
		$photometric = $geometry['photometric'];
		$rgb = '';

		if ($photometric === self::Palette) {
			$map = $ifd->getTag(320)?->getValues();
			if (!is_array($map) || $map === []) {
				return null;
			}
			$third = intdiv(count($map), 3);
			for ($i = 0; $i < $count; $i++) {
				$index = ord($samples[$i * $perPixel] ?? "\0");
				$rgb .= chr((int) (($map[$index] ?? 0) >> 8))
					. chr((int) (($map[$third + $index] ?? 0) >> 8))
					. chr((int) (($map[2 * $third + $index] ?? 0) >> 8));
			}
			return $rgb;
		}

		for ($i = 0; $i < $count; $i++) {
			$base = $i * $perPixel;
			switch ($photometric) {
				case self::WhiteIsZero:
				case self::Mask:
					$value = (int) round((1 - ord($samples[$base] ?? "\0") / $max) * 255);
					$rgb .= chr($value) . chr($value) . chr($value);
					break;
				case self::BlackIsZero:
					$value = (int) round(ord($samples[$base] ?? "\0") / $max * 255);
					$rgb .= chr($value) . chr($value) . chr($value);
					break;
				case self::Rgb:
				case self::YCbCr:
					if ($perPixel < 3) {
						return null;
					}
					$a = ord($samples[$base] ?? "\0");
					$b = ord($samples[$base + 1] ?? "\0");
					$c = ord($samples[$base + 2] ?? "\0");
					$rgb .= $photometric === self::Rgb
						? chr($a) . chr($b) . chr($c)
						: static::yCbCrToRgb($a, $b, $c);
					break;
				case self::Separated:
					if ($perPixel < 4) {
						return null;
					}
					$k = ord($samples[$base + 3] ?? "\0");
					$rgb .= chr((int) ((255 - ord($samples[$base] ?? "\0")) * (255 - $k) / 255))
						. chr((int) ((255 - ord($samples[$base + 1] ?? "\0")) * (255 - $k) / 255))
						. chr((int) ((255 - ord($samples[$base + 2] ?? "\0")) * (255 - $k) / 255));
					break;
				case self::CieLab:
				case self::ICCLab:
					if ($perPixel < 3) {
						return null;
					}
					$lightness = ord($samples[$base] ?? "\0") * 100 / 255;
					$aStar = ord($samples[$base + 1] ?? "\0");
					$bStar = ord($samples[$base + 2] ?? "\0");
					$rgb .= static::labToRgb(
						$lightness,
						$photometric === self::CieLab ? ($aStar > 127 ? $aStar - 256 : $aStar) : $aStar - 128,
						$photometric === self::CieLab ? ($bStar > 127 ? $bStar - 256 : $bStar) : $bStar - 128,
					);
					break;
				default:
					return null;
			}
		}
		return $rgb;
	}

	/**
	 * Converts one YCbCr triple to RGB by the CCIR 601-1 relation.
	 * @param int $y The luma.
	 * @param int $cb The blue chroma.
	 * @param int $cr The red chroma.
	 * @return string The three RGB bytes.
	 */
	protected static function yCbCrToRgb(int $y, int $cb, int $cr): string
	{
		$r = $y + 1.402 * ($cr - 128);
		$g = $y - 0.344136 * ($cb - 128) - 0.714136 * ($cr - 128);
		$b = $y + 1.772 * ($cb - 128);
		return chr(max(0, min(255, (int) round($r))))
			. chr(max(0, min(255, (int) round($g))))
			. chr(max(0, min(255, (int) round($b))));
	}

	/**
	 * Converts one CIE L*a*b* triple to sRGB through XYZ, on the D50 white point TIFF
	 * specifies.
	 * @param float $lightness The L* value (0-100).
	 * @param float $aStar The a* value.
	 * @param float $bStar The b* value.
	 * @return string The three RGB bytes.
	 */
	protected static function labToRgb(float $lightness, float $aStar, float $bStar): string
	{
		$fy = ($lightness + 16) / 116;
		$fx = $fy + $aStar / 500;
		$fz = $fy - $bStar / 200;
		$finv = static fn (float $t): float => $t > 6 / 29 ? $t ** 3 : 3 * (6 / 29) ** 2 * ($t - 4 / 29);
		// D50 reference white.
		$x = 0.9642 * $finv($fx);
		$y = 1.0 * $finv($fy);
		$z = 0.8249 * $finv($fz);

		// XYZ (D50) to linear sRGB, Bradford-adapted.
		$r = 3.1338561 * $x - 1.6168667 * $y - 0.4906146 * $z;
		$g = -0.9787684 * $x + 1.9161415 * $y + 0.0334540 * $z;
		$b = 0.0719453 * $x - 0.2289914 * $y + 1.4052427 * $z;
		$gamma = static fn (float $c): int => max(0, min(255, (int) round(
			255 * ($c <= 0.0031308 ? 12.92 * $c : 1.055 * max($c, 0) ** (1 / 2.4) - 0.055),
		)));
		return chr($gamma($r)) . chr($gamma($g)) . chr($gamma($b));
	}

	/**
	 * Decodes a subsampled YCbCr raster, whose data is stored in units of
	 * `YCbCrSubSampling` luma samples followed by one shared chroma pair.
	 * @param TIFFIfd $ifd The image IFD.
	 * @param array $geometry The geometry.
	 * @param int $width The image width.
	 * @param int $height The image height.
	 * @return ?string The RGB pixel bytes, or null when unsupported.
	 */
	protected static function decodeSubsampledYCbCr(TIFFIfd $ifd, array $geometry, int $width, int $height): ?string
	{
		if ($geometry['planar'] !== 1 || $geometry['bits'] !== 8 || $geometry['samples'] !== 3
			|| $geometry['tileWidth'] > 0 || $geometry['format'] !== self::FormatUnsigned
			|| $geometry['predictor'] !== 1) {
			return null;   // the unit layout is defined for plain unsigned bytes only
		}
		[$hSub, $vSub] = $geometry['subsampling'];
		if ($hSub < 1 || $vSub < 1) {
			return null;
		}
		$strips = $ifd->getTag(273)?->getExternalData();
		if ($strips === null) {
			return null;
		}
		$data = '';
		foreach ($strips as $strip) {
			$decoded = match ($geometry['compression']) {
				1 => $strip,
				5 => LZWCompressor::decompress($strip),
				32773 => PackBitsCompressor::decompress($strip),
				default => null,
			};
			if ($decoded === null) {
				return null;
			}
			$data .= $decoded;
		}
		if ($geometry['fillOrder'] === 2) {
			$data = static::reverseBits($data);
		}

		$unitLuma = $hSub * $vSub;
		$unitSize = $unitLuma + 2;
		$unitsAcross = (int) ceil($width / $hSub);
		$unitsDown = (int) ceil($height / $vSub);
		$rgb = str_repeat("\0", $width * $height * 3);
		for ($unitY = 0; $unitY < $unitsDown; $unitY++) {
			for ($unitX = 0; $unitX < $unitsAcross; $unitX++) {
				$offset = (($unitY * $unitsAcross) + $unitX) * $unitSize;
				if ($offset + $unitSize > strlen($data)) {
					break 2;
				}
				$cb = ord($data[$offset + $unitLuma]);
				$cr = ord($data[$offset + $unitLuma + 1]);
				for ($sub = 0; $sub < $unitLuma; $sub++) {
					$x = $unitX * $hSub + ($sub % $hSub);
					$y = $unitY * $vSub + intdiv($sub, $hSub);
					if ($x >= $width || $y >= $height) {
						continue;
					}
					$rgb = substr_replace($rgb, static::yCbCrToRgb(ord($data[$offset + $sub]), $cb, $cr), ($y * $width + $x) * 3, 3);
				}
			}
		}
		return $rgb;
	}
}
