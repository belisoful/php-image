<?php

/**
 * GIFFrame class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\GIF;

use Belisoful\Image\Compression\GIFLZWCompressor;
use Belisoful\Image\ImageGraphics;

/**
 * GIFFrame class.
 *
 * One GIF image: the Image Descriptor (position, size, interlace flag, and an optional
 * Local Color Table), the LZW-compressed pixel indexes, and the Graphic Control
 * Extension that precedes it — the delay, the disposal method, the user-input flag, and
 * the transparent color index.
 *
 * A frame is an *authored* frame, not a composited one: {@see getLeft()}/{@see getTop()}
 * and {@see getWidth()}/{@see getHeight()} are the sub-rectangle the file actually
 * stores, which is usually smaller than the logical screen.  Nothing is coalesced, so a
 * parse/compose cycle reproduces the frame byte for byte.
 *
 * {@see getPixels()} decodes the LZW data into one palette-index byte per pixel,
 * undoing the four-pass interlace when the frame is interlaced; {@see setPixels()}
 * re-interlaces and re-encodes.  {@see getImage()} renders the frame against a palette
 * through {@see ImageGraphics}, and {@see setImage()} quantizes an image back into a
 * local color table and pixel indexes.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class GIFFrame
{
	/** @var int No disposal method is specified. */
	public const DisposalUnspecified = 0;

	/** @var int The frame is left in place. */
	public const DisposalNone = 1;

	/** @var int The frame area is restored to the background color. */
	public const DisposalRestoreBackground = 2;

	/** @var int The frame area is restored to what preceded it. */
	public const DisposalRestorePrevious = 3;

	/** @var int The maximum bytes one data sub-block holds. */
	public const MaxSubBlock = 255;

	/** @var int The frame's left position on the logical screen. */
	private int $_left = 0;

	/** @var int The frame's top position on the logical screen. */
	private int $_top = 0;

	/** @var int The frame width in pixels. */
	private int $_width = 0;

	/** @var int The frame height in pixels. */
	private int $_height = 0;

	/** @var bool Whether the rows are stored in the four-pass interlace order. */
	private bool $_interlaced = false;

	/** @var bool Whether the local color table is sorted by importance. */
	private bool $_sorted = false;

	/** @var ?string The local color table as RGB triplets, or null when absent. */
	private ?string $_localColorTable = null;

	/** @var int The LZW minimum code size. */
	private int $_minCodeSize = 8;

	/** @var array<int, string> The LZW data sub-blocks, in the framing they were read with. */
	private array $_dataSubBlocks = [];

	/** @var bool Whether a Graphic Control Extension precedes the frame. */
	private bool $_hasGraphicControl = false;

	/** @var int The disposal method. */
	private int $_disposalMethod = self::DisposalUnspecified;

	/** @var bool Whether the frame waits for user input. */
	private bool $_userInput = false;

	/** @var int The delay before the next frame, in hundredths of a second. */
	private int $_delayTime = 0;

	/** @var ?int The transparent color index, or null when the frame is opaque. */
	private ?int $_transparentIndex = null;

	/** @var int The Graphic Control Extension's three reserved bits, preserved as read. */
	private int $_graphicControlReserved = 0;

	//
	// ─── Geometry ────────────────────────────────────────────────────────────
	//

	/** @return int The frame's left position on the logical screen. */
	public function getLeft(): int
	{
		return $this->_left;
	}

	/** @param int $value The frame's left position. */
	public function setLeft(int $value): void
	{
		$this->_left = $value & 0xFFFF;
	}

	/** @return int The frame's top position on the logical screen. */
	public function getTop(): int
	{
		return $this->_top;
	}

	/** @param int $value The frame's top position. */
	public function setTop(int $value): void
	{
		$this->_top = $value & 0xFFFF;
	}

	/** @return int The frame width in pixels. */
	public function getWidth(): int
	{
		return $this->_width;
	}

	/** @param int $value The frame width in pixels. */
	public function setWidth(int $value): void
	{
		$this->_width = $value & 0xFFFF;
	}

	/** @return int The frame height in pixels. */
	public function getHeight(): int
	{
		return $this->_height;
	}

	/** @param int $value The frame height in pixels. */
	public function setHeight(int $value): void
	{
		$this->_height = $value & 0xFFFF;
	}

	/** @return bool Whether the rows are interlaced. */
	public function getInterlaced(): bool
	{
		return $this->_interlaced;
	}

	/**
	 * Sets the interlace flag.  The stored rows are re-ordered to match, so the pixels
	 * {@see getPixels()} reports are unchanged.
	 * @param bool $value Whether the rows are interlaced.
	 */
	public function setInterlaced(bool $value): void
	{
		if ($value === $this->_interlaced) {
			return;
		}
		$pixels = $this->_dataSubBlocks === [] ? null : $this->getPixels();
		$this->_interlaced = $value;
		if ($pixels !== null) {
			$this->setPixels($pixels);
		}
	}

	/** @return bool Whether the local color table is sorted by importance. */
	public function getSorted(): bool
	{
		return $this->_sorted;
	}

	/** @param bool $value Whether the local color table is sorted. */
	public function setSorted(bool $value): void
	{
		$this->_sorted = $value;
	}

	//
	// ─── Local color table ───────────────────────────────────────────────────
	//

	/**
	 * Returns the local color table.
	 * @return ?string The table as RGB triplets, or null when the frame uses the global one.
	 */
	public function getLocalColorTable(): ?string
	{
		return $this->_localColorTable;
	}

	/**
	 * Sets (or clears, when null) the local color table.  The table is padded to the
	 * next power-of-two entry count the format can express.
	 * @param ?string $value The table as RGB triplets, or null to use the global table.
	 *   holds more than 256 colors.
	 */
	public function setLocalColorTable(?string $value): void
	{
		$this->_localColorTable = $value === null ? null : static::normalizeColorTable($value);
	}

	/**
	 * Indicates whether the frame carries its own color table.
	 * @return bool Whether a local color table is present.
	 */
	public function getHasLocalColorTable(): bool
	{
		return $this->_localColorTable !== null;
	}

	//
	// ─── Graphic control ─────────────────────────────────────────────────────
	//

	/** @return bool Whether a Graphic Control Extension precedes the frame. */
	public function getHasGraphicControl(): bool
	{
		return $this->_hasGraphicControl;
	}

	/** @param bool $value Whether to write a Graphic Control Extension. */
	public function setHasGraphicControl(bool $value): void
	{
		$this->_hasGraphicControl = $value;
	}

	/** @return int The delay before the next frame, in hundredths of a second. */
	public function getDelayTime(): int
	{
		return $this->_delayTime;
	}

	/**
	 * Sets the delay before the next frame; any non-zero delay implies a Graphic
	 * Control Extension.
	 * @param int $value The delay in hundredths of a second.
	 */
	public function setDelayTime(int $value): void
	{
		$this->_delayTime = $value & 0xFFFF;
		if ($this->_delayTime !== 0) {
			$this->_hasGraphicControl = true;
		}
	}

	/** @return int The disposal method, one of the Disposal* constants. */
	public function getDisposalMethod(): int
	{
		return $this->_disposalMethod;
	}

	/**
	 * Sets the disposal method; any method beyond "unspecified" implies a Graphic
	 * Control Extension.
	 * @param int $value One of the Disposal* constants (0-7).
	 * @throws \InvalidArgumentException When the method does not fit its three bits.
	 */
	public function setDisposalMethod(int $value): void
	{
		if ($value < 0 || $value > 7) {
			throw new \InvalidArgumentException(sprintf('GIF disposal method \'%s\' must be 0 (unspecified), 1 (none), 2 (restore background), or 3 (restore previous).', $value));
		}
		$this->_disposalMethod = $value;
		if ($value !== self::DisposalUnspecified) {
			$this->_hasGraphicControl = true;
		}
	}

	/** @return bool Whether the frame waits for user input. */
	public function getUserInput(): bool
	{
		return $this->_userInput;
	}

	/** @param bool $value Whether the frame waits for user input. */
	public function setUserInput(bool $value): void
	{
		$this->_userInput = $value;
		if ($value) {
			$this->_hasGraphicControl = true;
		}
	}

	/**
	 * Returns the transparent color index.
	 * @return ?int The index, or null when the frame is opaque.
	 */
	public function getTransparentIndex(): ?int
	{
		return $this->_transparentIndex;
	}

	/**
	 * Sets (or clears, when null) the transparent color index; setting one implies a
	 * Graphic Control Extension.
	 * @param ?int $value The palette index, or null for an opaque frame.
	 * @throws \InvalidArgumentException When the index is outside 0-255.
	 */
	public function setTransparentIndex(?int $value): void
	{
		if ($value !== null && ($value < 0 || $value > 255)) {
			throw new \InvalidArgumentException(sprintf('GIF transparent color index \'%s\' must be a palette index between 0 and 255.', $value));
		}
		$this->_transparentIndex = $value;
		if ($value !== null) {
			$this->_hasGraphicControl = true;
		}
	}

	/** @return int The Graphic Control Extension's reserved bits, as read. */
	public function getGraphicControlReserved(): int
	{
		return $this->_graphicControlReserved;
	}

	/** @param int $value The reserved bits (0-7). */
	public function setGraphicControlReserved(int $value): void
	{
		$this->_graphicControlReserved = $value & 0x07;
	}

	//
	// ─── Pixel data ──────────────────────────────────────────────────────────
	//

	/** @return int The LZW minimum code size. */
	public function getMinCodeSize(): int
	{
		return $this->_minCodeSize;
	}

	/** @param int $value The LZW minimum code size (2-8). */
	public function setMinCodeSize(int $value): void
	{
		if ($value < 2 || $value > 8) {
			throw new \InvalidArgumentException(sprintf('GIF LZW minimum code size \'%s\' must be between 2 and 8.', $value));
		}
		$this->_minCodeSize = $value;
	}

	/**
	 * Returns the LZW data sub-blocks in their original framing.
	 * @return array<int, string> The sub-blocks.
	 */
	public function getDataSubBlocks(): array
	{
		return $this->_dataSubBlocks;
	}

	/**
	 * Replaces the LZW data sub-blocks, keeping the given framing.
	 * @param array<int, string> $value The sub-blocks, each at most 255 bytes.
	 */
	public function setDataSubBlocks(array $value): void
	{
		$this->_dataSubBlocks = array_values($value);
	}

	/**
	 * Returns the raw LZW-compressed bytes, the sub-blocks joined.
	 * @return string The compressed data.
	 */
	public function getLzwData(): string
	{
		return implode('', $this->_dataSubBlocks);
	}

	/**
	 * Replaces the LZW-compressed bytes, re-split into maximum-size sub-blocks.
	 * @param string $value The compressed data.
	 */
	public function setLzwData(string $value): void
	{
		$this->_dataSubBlocks = $value === '' ? [] : str_split($value, self::MaxSubBlock);
	}

	/**
	 * Decodes the pixel indexes: one palette-index byte per pixel, row-major, with the
	 * four-pass interlace undone when the frame is interlaced.
	 * @return string The pixel indexes.
	 */
	public function getPixels(): string
	{
		$pixels = GIFLZWCompressor::decompress($this->getLzwData(), $this->_minCodeSize);
		$expected = $this->_width * $this->_height;
		if (strlen($pixels) > $expected) {
			$pixels = substr($pixels, 0, $expected);
		} elseif (strlen($pixels) < $expected) {
			$pixels = str_pad($pixels, $expected, "\0");
		}
		return $this->_interlaced ? static::deinterlace($pixels, $this->_width, $this->_height) : $pixels;
	}

	/**
	 * Encodes the pixel indexes, re-applying the interlace order when the frame is
	 * interlaced.
	 * The minimum code size is raised when the indexes need more bits than the current
	 * one allows, so pixels written against a larger palette encode without the caller
	 * tracking the code space.
	 * @param string $pixels One palette-index byte per pixel, row-major.
	 * @param ?int $minCodeSize The LZW minimum code size, or null to derive it.
	 * @throws \InvalidArgumentException When the pixel count does not match the frame
	 *   size, or an explicit code size is too small for the indexes.
	 */
	public function setPixels(string $pixels, ?int $minCodeSize = null): void
	{
		$expected = $this->_width * $this->_height;
		if (strlen($pixels) !== $expected) {
			throw new \InvalidArgumentException(sprintf('GIF frame pixel data of %s bytes does not match the %s pixels the frame holds.', strlen($pixels), $expected));
		}
		$required = static::minCodeSizeForPixels($pixels);
		if ($minCodeSize !== null) {
			if ($minCodeSize < $required) {
				throw new \InvalidArgumentException(sprintf('GIF minimum code size \'%s\' is too small for the frame\'s palette indexes; %s bits are needed.', $minCodeSize, $required));
			}
			$this->setMinCodeSize($minCodeSize);
		} elseif ($this->_minCodeSize < $required) {
			$this->_minCodeSize = $required;
		}
		$ordered = $this->_interlaced ? static::interlace($pixels, $this->_width, $this->_height) : $pixels;
		$this->setLzwData(GIFLZWCompressor::compress($ordered, $this->_minCodeSize));
	}

	//
	// ─── Raster conversion ───────────────────────────────────────────────────
	//

	/**
	 * Renders the frame against a color table.
	 * @param string $palette The effective color table as RGB triplets — the frame's
	 *   own when it has one, otherwise the file's global table.
	 * @param ?string $mode The graphics library name, or null for the default.
	 * @throws \InvalidArgumentException When the frame has no pixels to render.
	 * @throws \RuntimeException When the frame has no pixels to render.
	 * @return \GdImage|\Imagick The rendered frame.
	 */
	public function getImage(string $palette, ?string $mode = null): \GdImage|\Imagick
	{
		if ($this->_width < 1 || $this->_height < 1) {
			throw new \InvalidArgumentException('A GIF frame with no pixel dimensions cannot be rendered.');
		}
		$indexes = $this->getPixels();
		$colors = strlen($palette) >= 3 ? intdiv(strlen($palette), 3) : 1;
		$rgb = '';
		for ($i = 0, $n = strlen($indexes); $i < $n; $i++) {
			$index = ord($indexes[$i]);
			$offset = ($index < $colors ? $index : 0) * 3;
			$rgb .= substr($palette, $offset, 3);
		}
		$image = ImageGraphics::fromRgbPixels($rgb, $this->_width, $this->_height, $mode);
		if ($image === false) {
			throw new \InvalidArgumentException('The graphics library could not build an image from the GIF frame\'s pixels.');
		}
		return $image;
	}

	/**
	 * Quantizes an image into this frame, replacing its size, local color table, and
	 * pixel indexes.
	 * @param \GdImage|\Imagick $image The source image.
	 */
	public function setImage(\GdImage|\Imagick $image): void
	{
		[$width, $height] = ImageGraphics::getSize($image);
		[$palette, $pixels] = ImageGraphics::paletteQuantize($image);
		$this->_width = $width;
		$this->_height = $height;
		$this->setLocalColorTable($palette);
		$this->_minCodeSize = static::minCodeSizeFor($this->_localColorTable);
		$ordered = $this->_interlaced ? static::interlace($pixels, $width, $height) : $pixels;
		$this->setLzwData(GIFLZWCompressor::compress($ordered, $this->_minCodeSize));
	}

	//
	// ─── Composition ─────────────────────────────────────────────────────────
	//

	/**
	 * Packs the frame: its Graphic Control Extension when it has one, the Image
	 * Descriptor, the local color table, and the LZW sub-blocks.
	 * @return string The frame bytes.
	 */
	public function toBinary(): string
	{
		$out = '';
		if ($this->_hasGraphicControl) {
			$packed = (($this->_graphicControlReserved & 0x07) << 5)
				| (($this->_disposalMethod & 0x07) << 2)
				| ($this->_userInput ? 0x02 : 0)
				| ($this->_transparentIndex !== null ? 0x01 : 0);
			$out .= chr(GIFBlockType::ExtensionIntroducer) . chr(GIFBlockType::GraphicControlLabel) . chr(4)
				. chr($packed) . pack('v', $this->_delayTime)
				. chr($this->_transparentIndex ?? 0) . "\x00";
		}
		$packed = ($this->_localColorTable !== null ? 0x80 : 0)
			| ($this->_interlaced ? 0x40 : 0)
			| ($this->_sorted ? 0x20 : 0)
			| ($this->_localColorTable !== null ? (static::tableSizeBits($this->_localColorTable) & 0x07) : 0);
		$out .= chr(GIFBlockType::ImageSeparator) . pack('vvvv', $this->_left, $this->_top, $this->_width, $this->_height) . chr($packed);
		if ($this->_localColorTable !== null) {
			$out .= $this->_localColorTable;
		}
		$out .= chr($this->_minCodeSize);
		foreach ($this->_dataSubBlocks as $block) {
			foreach (str_split($block, self::MaxSubBlock) as $piece) {
				$out .= chr(strlen($piece)) . $piece;
			}
		}
		return $out . "\x00";
	}

	//
	// ─── Helpers ─────────────────────────────────────────────────────────────
	//

	/** @var array<int, array{int, int}> The interlace passes as [first row, row step]. */
	protected const InterlacePasses = [[0, 8], [4, 8], [2, 4], [1, 2]];

	/**
	 * Reorders interlaced rows into top-to-bottom order.
	 * @param string $pixels The pixel indexes in interlace order.
	 * @param int $width The row width in pixels.
	 * @param int $height The row count.
	 * @return string The pixel indexes in row order.
	 */
	public static function deinterlace(string $pixels, int $width, int $height): string
	{
		if ($width < 1 || $height < 1) {
			return $pixels;
		}
		$rows = [];
		$source = 0;
		foreach (self::InterlacePasses as [$start, $step]) {
			for ($y = $start; $y < $height; $y += $step) {
				$rows[$y] = substr($pixels, $source * $width, $width);
				$source++;
			}
		}
		ksort($rows);
		return implode('', $rows);
	}

	/**
	 * Reorders top-to-bottom rows into the four-pass interlace order.
	 * @param string $pixels The pixel indexes in row order.
	 * @param int $width The row width in pixels.
	 * @param int $height The row count.
	 * @return string The pixel indexes in interlace order.
	 */
	public static function interlace(string $pixels, int $width, int $height): string
	{
		if ($width < 1 || $height < 1) {
			return $pixels;
		}
		$out = '';
		foreach (self::InterlacePasses as [$start, $step]) {
			for ($y = $start; $y < $height; $y += $step) {
				$out .= substr($pixels, $y * $width, $width);
			}
		}
		return $out;
	}

	/**
	 * Pads a color table to the next power-of-two entry count.
	 * @param string $table The table as RGB triplets.
	 * @throws \InvalidArgumentException When the table is not whole triplets or is oversized.
	 * @return string The padded table.
	 */
	public static function normalizeColorTable(string $table): string
	{
		if (strlen($table) % 3 !== 0) {
			throw new \InvalidArgumentException(sprintf('A GIF color table of %s bytes is not whole RGB triplets.', strlen($table)));
		}
		$colors = intdiv(strlen($table), 3);
		if ($colors < 1 || $colors > 256) {
			throw new \InvalidArgumentException(sprintf('A GIF color table of %s colors is outside the 1 to 256 the format holds.', $colors));
		}
		$size = 2;
		while ($size < $colors) {
			$size <<= 1;
		}
		return str_pad($table, $size * 3, "\0");
	}

	/**
	 * Returns the packed-field size bits for a color table: the table holds 2^(bits+1)
	 * entries.
	 * @param string $table The table as RGB triplets.
	 * @return int The size bits (0-7).
	 */
	public static function tableSizeBits(string $table): int
	{
		$colors = max(2, intdiv(strlen($table), 3));
		$bits = 0;
		while ((2 << $bits) < $colors && $bits < 7) {
			$bits++;
		}
		return $bits;
	}

	/**
	 * Returns the smallest LZW minimum code size a color table's indexes need.
	 * @param ?string $table The table as RGB triplets, or null.
	 * @return int The minimum code size (2-8).
	 */
	public static function minCodeSizeFor(?string $table): int
	{
		return $table === null ? 8 : max(2, static::tableSizeBits($table) + 1);
	}

	/**
	 * Returns the smallest LZW minimum code size that can express every index in a
	 * block of pixels.  GIF never uses fewer than two bits, even for bilevel images.
	 * @param string $pixels One palette-index byte per pixel.
	 * @return int The minimum code size (2-8).
	 */
	public static function minCodeSizeForPixels(string $pixels): int
	{
		$highest = 0;
		for ($i = 0, $n = strlen($pixels); $i < $n; $i++) {
			$value = ord($pixels[$i]);
			if ($value > $highest) {
				$highest = $value;
			}
		}
		$bits = 2;
		while ($bits < 8 && (1 << $bits) <= $highest) {
			$bits++;
		}
		return $bits;
	}
}
