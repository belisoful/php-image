<?php

/**
 * APNGFrame class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\PNG;

/**
 * APNGFrame class.
 *
 * One frame of an Animated PNG (APNG): the `fcTL` frame-control fields — the frame's own
 * width and height, its offset on the canvas, the delay, and the disposal and blend
 * operations — together with the frame's image data (the payload of its `IDAT` or `fdAT`
 * chunks, which is a standalone PNG image datastream for the frame's sub-rectangle).
 *
 * Frames are **authored, not composited**: each keeps the geometry and operations the
 * file stores, exactly as {@see \Belisoful\Image\GIF\GIFFrame} does for GIF, so a
 * parse/compose cycle reproduces the animation rather than flattening it.  The default
 * image (the `IDAT`) is the first frame when an `fcTL` precedes it; {@see getIsDefault()}
 * marks it, because its data is written as `IDAT` rather than `fdAT`.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @see https://www.w3.org/TR/png-3/#5APNG
 */
class APNGFrame
{
	/** @var int Disposal: leave the frame's region as it is for the next frame. */
	public const DisposeNone = 0;

	/** @var int Disposal: clear the frame's region to transparent black before the next frame. */
	public const DisposeBackground = 1;

	/** @var int Disposal: restore the region to its content before this frame. */
	public const DisposePrevious = 2;

	/** @var int Blend: replace the region's contents with the frame. */
	public const BlendSource = 0;

	/** @var int Blend: composite the frame over the region using its alpha. */
	public const BlendOver = 1;

	/** @var int The frame width in pixels. */
	private int $_width = 0;

	/** @var int The frame height in pixels. */
	private int $_height = 0;

	/** @var int The x offset of the frame on the canvas. */
	private int $_xOffset = 0;

	/** @var int The y offset of the frame on the canvas. */
	private int $_yOffset = 0;

	/** @var int The delay numerator. */
	private int $_delayNum = 0;

	/** @var int The delay denominator (0 is treated as 100, i.e. hundredths of a second). */
	private int $_delayDen = 100;

	/** @var int The disposal operation (a `Dispose*` constant). */
	private int $_disposeOp = self::DisposeNone;

	/** @var int The blend operation (a `Blend*` constant). */
	private int $_blendOp = self::BlendSource;

	/** @var string The frame image data (the joined IDAT/fdAT payload). */
	private string $_data = '';

	/** @var bool Whether this frame is the default image, written as IDAT rather than fdAT. */
	private bool $_isDefault = false;

	/** @return int The frame width in pixels. */
	public function getWidth(): int
	{
		return $this->_width;
	}

	/** @param int $value The frame width in pixels. */
	public function setWidth(int $value): void
	{
		$this->_width = max(0, $value);
	}

	/** @return int The frame height in pixels. */
	public function getHeight(): int
	{
		return $this->_height;
	}

	/** @param int $value The frame height in pixels. */
	public function setHeight(int $value): void
	{
		$this->_height = max(0, $value);
	}

	/** @return int The x offset of the frame on the canvas. */
	public function getXOffset(): int
	{
		return $this->_xOffset;
	}

	/** @param int $value The x offset of the frame on the canvas. */
	public function setXOffset(int $value): void
	{
		$this->_xOffset = max(0, $value);
	}

	/** @return int The y offset of the frame on the canvas. */
	public function getYOffset(): int
	{
		return $this->_yOffset;
	}

	/** @param int $value The y offset of the frame on the canvas. */
	public function setYOffset(int $value): void
	{
		$this->_yOffset = max(0, $value);
	}

	/** @return int The delay numerator. */
	public function getDelayNum(): int
	{
		return $this->_delayNum;
	}

	/** @param int $value The delay numerator. */
	public function setDelayNum(int $value): void
	{
		$this->_delayNum = max(0, $value) & 0xFFFF;
	}

	/** @return int The delay denominator (0 means 100). */
	public function getDelayDen(): int
	{
		return $this->_delayDen;
	}

	/** @param int $value The delay denominator (0 means 100). */
	public function setDelayDen(int $value): void
	{
		$this->_delayDen = max(0, $value) & 0xFFFF;
	}

	/**
	 * Returns the display delay in seconds, resolving the specification's rule that a
	 * zero denominator stands for 100.
	 * @return float The delay in seconds.
	 */
	public function getDelaySeconds(): float
	{
		$den = $this->_delayDen === 0 ? 100 : $this->_delayDen;
		return $this->_delayNum / $den;
	}

	/**
	 * Sets the display delay from a seconds value, storing it as hundredths.
	 * @param float $seconds The delay in seconds.
	 */
	public function setDelaySeconds(float $seconds): void
	{
		$this->_delayNum = max(0, (int) round($seconds * 100)) & 0xFFFF;
		$this->_delayDen = 100;
	}

	/** @return int The disposal operation (a `Dispose*` constant). */
	public function getDisposeOp(): int
	{
		return $this->_disposeOp;
	}

	/** @param int $value The disposal operation (a `Dispose*` constant). */
	public function setDisposeOp(int $value): void
	{
		$this->_disposeOp = $value;
	}

	/** @return int The blend operation (a `Blend*` constant). */
	public function getBlendOp(): int
	{
		return $this->_blendOp;
	}

	/** @param int $value The blend operation (a `Blend*` constant). */
	public function setBlendOp(int $value): void
	{
		$this->_blendOp = $value;
	}

	/**
	 * Returns the frame image data: the concatenated payload of the frame's `IDAT` (for
	 * the default image) or `fdAT` chunks (with their sequence numbers removed).
	 * @return string The frame image data.
	 */
	public function getData(): string
	{
		return $this->_data;
	}

	/** @param string $value The frame image data. */
	public function setData(string $value): void
	{
		$this->_data = $value;
	}

	/** @return bool Whether this frame is the default image (written as IDAT). */
	public function getIsDefault(): bool
	{
		return $this->_isDefault;
	}

	/** @param bool $value Whether this frame is the default image. */
	public function setIsDefault(bool $value): void
	{
		$this->_isDefault = $value;
	}

	/**
	 * Builds the 26-byte `fcTL` payload for this frame, minus the sequence number, which
	 * the writer assigns.  {@see \Belisoful\Image\PNGImage} prepends the sequence number.
	 * @return string The fcTL fields after the sequence number (22 bytes).
	 */
	public function fcTlFields(): string
	{
		return pack('NNNN', $this->_width, $this->_height, $this->_xOffset, $this->_yOffset)
			. pack('nn', $this->_delayNum, $this->_delayDen)
			. chr($this->_disposeOp & 0xFF) . chr($this->_blendOp & 0xFF);
	}

	/**
	 * Reads the frame's fcTL fields (the 26-byte payload) into this frame; the leading
	 * four-byte sequence number is ignored.
	 * @param string $payload The fcTL chunk payload.
	 * @return static This frame.
	 */
	public function loadFcTl(string $payload): static
	{
		if (strlen($payload) >= 26) {
			$this->_width = (int) unpack('N', substr($payload, 4, 4))[1];
			$this->_height = (int) unpack('N', substr($payload, 8, 4))[1];
			$this->_xOffset = (int) unpack('N', substr($payload, 12, 4))[1];
			$this->_yOffset = (int) unpack('N', substr($payload, 16, 4))[1];
			$this->_delayNum = (int) unpack('n', substr($payload, 20, 2))[1];
			$this->_delayDen = (int) unpack('n', substr($payload, 22, 2))[1];
			$this->_disposeOp = ord($payload[24]);
			$this->_blendOp = ord($payload[25]);
		}
		return $this;
	}
}
