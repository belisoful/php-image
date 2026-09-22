<?php

/**
 * JXLSizeHeader class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image;

/**
 * JXLSizeHeader class.
 *
 * The `SizeHeader` a JPEG XL codestream opens with (ISO/IEC 18181-1), which is the only
 * place a JXL states its pixel dimensions.  It is a bit-packed structure rather than a
 * field layout, so reading it is the one part of JPEG XL that cannot be done with byte
 * arithmetic:
 *
 * - a `small` flag; when set, the height is five bits of eighths-minus-one, so a small
 *   image's height is always a multiple of eight;
 * - otherwise the height is a `U32`: a two-bit selector choosing a 9-, 13-, 18- or 30-bit
 *   field, and the stored value is one less than the height;
 * - then a three-bit aspect ratio.  Zero means the width is stored the same way the height
 *   was; anything else names a ratio in {@see AspectRatios} and the width is derived, which
 *   is why most JXL files store no width at all.
 *
 * The bits are packed **least-significant first within each byte**, and a field's first bit
 * is its *low* bit.  That is neither of the orders the framework's bit reader offers — its
 * LSB-first mode still packs a field most-significant first — so the few lines of bit
 * reading here are deliberately local rather than borrowed.
 *
 * Verified against 24 files written by `cjxl` covering every aspect ratio, both height
 * forms, and the 9-, 13- and 18-bit selectors.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @see https://www.iso.org/standard/85018.html ISO/IEC 18181-1 (JPEG XL codestream)
 */
class JXLSizeHeader
{
	/** The two bytes a codestream begins with. */
	public const Signature = "\xFF\x0A";

	/**
	 * The aspect ratios the three-bit code names, as [numerator, denominator].  Code 0 is
	 * absent from the table: it means the width is stored rather than derived.
	 */
	public const AspectRatios = [
		1 => [1, 1],
		2 => [12, 10],
		3 => [4, 3],
		4 => [3, 2],
		5 => [16, 9],
		6 => [5, 4],
		7 => [2, 1],
	];

	/** The field widths a `U32` selector chooses between. */
	protected const SelectorWidths = [9, 13, 18, 30];

	/** @var int The pixel width. */
	private int $_width = 0;

	/** @var int The pixel height. */
	private int $_height = 0;

	/** @var int The stored aspect ratio code; zero when the width was stored instead. */
	private int $_aspectRatio = 0;

	/** @var bool Whether the compact height form was used. */
	private bool $_isSmall = false;

	/** @var string The bits being read. */
	private string $_bits = '';

	/** @var int The next bit position. */
	private int $_pos = 0;

	/** @var int The number of bits available. */
	private int $_end = 0;

	/**
	 * Reads the size header from the start of a codestream.
	 * @param string $codestream The codestream, beginning with {@see Signature}.
	 * @return ?self The header, or null when the bytes are not a codestream or end early.
	 */
	public static function fromCodestream(string $codestream): ?self
	{
		if (!str_starts_with($codestream, self::Signature)) {
			return null;
		}
		$header = new self();
		return $header->read(substr($codestream, strlen(self::Signature), 16)) ? $header : null;
	}

	/**
	 * Returns the pixel width.
	 * @return int The width.
	 */
	public function getWidth(): int
	{
		return $this->_width;
	}

	/**
	 * Returns the pixel height.
	 * @return int The height.
	 */
	public function getHeight(): int
	{
		return $this->_height;
	}

	/**
	 * Returns the stored aspect ratio code, which is zero when the width was stored rather
	 * than derived from the height.
	 * @return int The ratio code; a key of {@see AspectRatios}, or zero.
	 */
	public function getAspectRatio(): int
	{
		return $this->_aspectRatio;
	}

	/**
	 * Indicates whether the compact height form was used, which constrains the height to a
	 * multiple of eight.
	 * @return bool Whether the small form was used.
	 */
	public function getIsSmall(): bool
	{
		return $this->_isSmall;
	}

	/**
	 * Reads the header out of the bits after the signature.
	 * @param string $bits The bytes following the signature.
	 * @return bool Whether a whole header was read.
	 */
	protected function read(string $bits): bool
	{
		$this->_bits = $bits;
		$this->_pos = 0;
		$this->_end = strlen($bits) * 8;

		$small = $this->readBits(1);
		if ($small === null) {
			return false;
		}
		$this->_isSmall = $small === 1;
		$height = $this->_isSmall ? $this->readSmallDimension() : $this->readDimension();
		$ratio = $this->readBits(3);
		if ($height === null || $ratio === null) {
			return false;
		}
		$this->_aspectRatio = $ratio;
		$this->_height = $height;

		if ($ratio === 0) {
			$width = $this->_isSmall ? $this->readSmallDimension() : $this->readDimension();
			if ($width === null) {
				return false;
			}
			$this->_width = $width;
			return true;
		}
		[$numerator, $denominator] = self::AspectRatios[$ratio];
		$this->_width = intdiv($height * $numerator, $denominator);
		return true;
	}

	/**
	 * Reads the compact dimension form: five bits of eighths, one less than the real count.
	 * @return ?int The dimension, or null when the bits run out.
	 */
	private function readSmallDimension(): ?int
	{
		$value = $this->readBits(5);
		return $value === null ? null : ($value + 1) * 8;
	}

	/**
	 * Reads the full dimension form: a two-bit selector naming a field width, then the
	 * dimension one less than its real value.
	 * @return ?int The dimension, or null when the bits run out.
	 */
	private function readDimension(): ?int
	{
		$selector = $this->readBits(2);
		if ($selector === null) {
			return null;
		}
		$value = $this->readBits(self::SelectorWidths[$selector]);
		return $value === null ? null : $value + 1;
	}

	/**
	 * Reads a field, taking its bits least-significant first within each byte and making the
	 * first bit read the field's low bit.
	 * @param int $count The number of bits.
	 * @return ?int The value, or null when that many bits are not there.
	 */
	private function readBits(int $count): ?int
	{
		if ($this->_pos + $count > $this->_end) {
			return null;
		}
		$value = 0;
		for ($i = 0; $i < $count; $i++, $this->_pos++) {
			$value |= ((ord($this->_bits[$this->_pos >> 3]) >> ($this->_pos & 7)) & 1) << $i;
		}
		return $value;
	}
}
