<?php

/**
 * JFXX class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\Meta;

use Belisoful\Image\ImageGraphics;
use Belisoful\Image\ImageGraphicsMode;
use Belisoful\Image\Stream\StreamIO;
use Psr\Http\Message\StreamInterface;

/**
 * JFXX class.
 *
 * Parses, holds, and writes a JFXX thumbnail extension of a JPEG APP0 segment.  The
 * thumbnail is encoded one of three ways, by {@see getFormat() Format}:
 *
 * | Format        | Encoding                                                      |
 * |---------------|---------------------------------------------------------------|
 * | JPEG_THUMB    | A complete JPEG image.                                        |
 * | PALETTE_THUMB | A 256-entry RGB palette (768 bytes) plus one index per pixel. |
 * | COLOR_THUMB   | Uncompressed RGB, three bytes per pixel.                      |
 *
 * {@see parse()} reads a binary JFXX payload and {@see toBinary()} writes it back;
 * {@see setImage()} encodes a GD or Imagick image (the EFFICIENCY_THUMB format picks the
 * most compact of the three), and {@see getImage()} decodes it, in either graphics
 * library (see {@see ImageGraphics}).  Palette and color thumbnails are limited to 255x255
 * pixels.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @see https://www.w3.org/Graphics/JPEG/jfif3.pdf Official standard
 */
class JFXX
{
	public const IDENTIFIER = "JFXX\0";

	public const JPEG_THUMB = 0x10;
	public const PALETTE_THUMB = 0x11;
	public const COLOR_THUMB = 0x13;
	public const EFFICIENCY_THUMB = 0xFF;

	/** @var int The number of palette bytes (256 entries * 3 channels). */
	private const PALETTE_SIZE = 768;

	/** @var int The thumbnail encoding format. */
	private int $_format = self::EFFICIENCY_THUMB;

	/** @var int The thumbnail width in pixels (0-255). */
	private int $_xThumbnail = 0;

	/** @var int The thumbnail height in pixels (0-255). */
	private int $_yThumbnail = 0;

	/** @var ?string The RGB palette for a palette thumbnail. */
	private ?string $_palette = null;

	/** @var ?string The thumbnail data (JPEG bytes, palette indices, or RGB pixels). */
	private ?string $_thumbnail = null;

	/**
	 * Indicates whether the data begins with the JFXX identifier.
	 * @param string $data The candidate APP0 payload.
	 * @return bool Whether the data is a JFXX extension.
	 */
	public static function isJFXX(string $data): bool
	{
		return strncmp($data, self::IDENTIFIER, 5) === 0;
	}

	/**
	 * Parses a JFXX APP0 payload into a populated instance.
	 * @param mixed $data The JFXX binary data as a string, a {@see StreamInterface}, or a
	 *   PHP stream resource (read from its current position without taking ownership).
	 * @throws \InvalidArgumentException When the data is none of those.
	 * @return false|JFXX The parsed JFXX, or false when the data is not JFXX.
	 */
	public static function parse(mixed $data): false|JFXX
	{
		$bytes = StreamIO::readAll($data);
		if (strlen($bytes) < 6 || !self::isJFXX($bytes)) {
			return false;
		}
		$jfxx = new JFXX();
		$jfxx->_format = ord($bytes[5]);
		if ($jfxx->_format === self::JPEG_THUMB) {
			$jfxx->setThumbnail(substr($bytes, 6), true);
		} elseif ($jfxx->_format === self::PALETTE_THUMB) {
			$sx = ord($bytes[6]);
			$sy = ord($bytes[7]);
			$jfxx->_xThumbnail = $sx;
			$jfxx->_yThumbnail = $sy;
			$jfxx->_palette = substr($bytes, 8, self::PALETTE_SIZE);
			$jfxx->_thumbnail = substr($bytes, 8 + self::PALETTE_SIZE, $sx * $sy);
		} elseif ($jfxx->_format === self::COLOR_THUMB) {
			$sx = ord($bytes[6]);
			$sy = ord($bytes[7]);
			$jfxx->_xThumbnail = $sx;
			$jfxx->_yThumbnail = $sy;
			$jfxx->_thumbnail = substr($bytes, 8, 3 * $sx * $sy);
		}
		return $jfxx;
	}

	/**
	 * Returns the thumbnail encoding format.
	 * @return int A *_THUMB constant.
	 */
	public function getFormat(): int
	{
		return $this->_format;
	}

	/**
	 * Sets the thumbnail encoding format.
	 * @param int $value A *_THUMB constant.
	 */
	public function setFormat(int $value): void
	{
		$this->_format = $value;
	}

	/**
	 * Returns the thumbnail width.
	 * @return int The thumbnail width in pixels.
	 */
	public function getXThumbnail(): int
	{
		return $this->_xThumbnail;
	}

	/**
	 * Sets the thumbnail width.
	 * @param int $value The thumbnail width in pixels.
	 */
	public function setXThumbnail(int $value): void
	{
		$this->_xThumbnail = $value;
	}

	/**
	 * Returns the thumbnail height.
	 * @return int The thumbnail height in pixels.
	 */
	public function getYThumbnail(): int
	{
		return $this->_yThumbnail;
	}

	/**
	 * Sets the thumbnail height.
	 * @param int $value The thumbnail height in pixels.
	 */
	public function setYThumbnail(int $value): void
	{
		$this->_yThumbnail = $value;
	}

	/**
	 * Returns the RGB palette of a palette thumbnail.
	 * @return ?string The palette bytes, or null.
	 */
	public function getPalette(): ?string
	{
		return $this->_palette;
	}

	/**
	 * Sets the RGB palette of a palette thumbnail.
	 * @param ?string $value The palette bytes, or null.
	 */
	public function setPalette(?string $value): void
	{
		$this->_palette = $value;
	}

	/**
	 * Returns the thumbnail data.
	 * @return ?string The thumbnail bytes, or null.
	 */
	public function getThumbnail(): ?string
	{
		return $this->_thumbnail;
	}

	/**
	 * Sets the thumbnail data, reading the size from a JPEG thumbnail when requested.
	 * @param ?string $value The thumbnail bytes, or null.
	 * @param bool $jpegSizeData Whether to read the dimensions from JPEG thumbnail data.
	 */
	public function setThumbnail(?string $value, bool $jpegSizeData = false): void
	{
		$this->_thumbnail = $value;
		if ($jpegSizeData && $value !== null && ($size = @getimagesizefromstring($value)) !== false) {
			$this->_xThumbnail = $size[0];
			$this->_yThumbnail = $size[1];
		}
	}

	/**
	 * Indicates whether the JFXX carries a usable thumbnail.
	 * @return bool Whether a thumbnail is present.
	 */
	public function hasImage(): bool
	{
		$thumbnail = $this->getThumbnail();
		if ($thumbnail === null || $thumbnail === '') {
			return false;
		}
		$format = $this->getFormat();
		if ($format === self::JPEG_THUMB) {
			return true;
		}
		if ($this->getXThumbnail() <= 0 || $this->getYThumbnail() <= 0) {
			return false;
		}
		$palette = $this->getPalette();
		return $format !== self::PALETTE_THUMB || ($palette !== null && $palette !== '');
	}

	/**
	 * Builds an image from the thumbnail, in the requested graphics library.
	 * @param ?string $mode The {@see ImageGraphicsMode} to build in; null for the default.
	 * @return false|\GdImage|\Imagick The thumbnail image, or false when it cannot be built.
	 */
	public function getImage(?string $mode = null): false|\GdImage|\Imagick
	{
		if (!$this->hasImage()) {
			return false;
		}
		$format = $this->getFormat();
		if ($format === self::JPEG_THUMB) {
			$image = ImageGraphics::decode((string) $this->getThumbnail(), $mode);
			if ($image === false) {
				return false;
			}
			[$sx, $sy] = ImageGraphics::getSize($image);
			$this->setXThumbnail($sx);
			$this->setYThumbnail($sy);
			return $image;
		}
		if ($format === self::PALETTE_THUMB) {
			return ImageGraphics::fromRgbPixels($this->paletteRgbPixels(), $this->getXThumbnail(), $this->getYThumbnail(), $mode);
		}
		return ImageGraphics::fromRgbPixels((string) $this->getThumbnail(), $this->getXThumbnail(), $this->getYThumbnail(), $mode);
	}

	/**
	 * Encodes a GD or Imagick image into the thumbnail, choosing the most compact format
	 * when asked.
	 * @param null|\GdImage|\Imagick $image The image, or null to clear the thumbnail.
	 * @param int $format A *_THUMB constant; EFFICIENCY_THUMB picks the smallest encoding.
	 * @param int $quality The JPEG quality for JPEG/efficiency encoding. Default 75.
	 * @throws \InvalidArgumentException When a palette/color dimension exceeds 255 pixels.
	 * @return bool Whether the thumbnail was set.
	 */
	public function setImage(\GdImage|\Imagick|null $image, int $format = self::EFFICIENCY_THUMB, int $quality = 75): bool
	{
		if ($image === null) {
			$this->setXThumbnail(0);
			$this->setYThumbnail(0);
			$this->setPalette(null);
			$this->setThumbnail(null);
			return true;
		}
		if (!in_array($format, [self::JPEG_THUMB, self::PALETTE_THUMB, self::COLOR_THUMB, self::EFFICIENCY_THUMB], true)) {
			return false;
		}
		[$sx, $sy] = ImageGraphics::getSize($image);
		if ($sx > 255 || $sy > 255) {
			throw new \InvalidArgumentException(sprintf('JFXX thumbnail of %sx%s pixels exceeds the 255x255 limit.', $sx, $sy));
		}

		$jpeg = null;
		if ($format === self::EFFICIENCY_THUMB) {
			$jpeg = ImageGraphics::encodeJpeg($image, $quality);
			$colorSize = $sx * $sy * 3;
			$paletteSize = self::PALETTE_SIZE + $sx * $sy;
			$format = $paletteSize < $colorSize ? self::PALETTE_THUMB : self::COLOR_THUMB;
			$format = min($paletteSize, $colorSize) < strlen($jpeg) ? $format : self::JPEG_THUMB;
		}

		$this->setFormat($format);
		$this->setXThumbnail($sx);
		$this->setYThumbnail($sy);
		if ($format === self::COLOR_THUMB) {
			$this->setPalette(null);
			$this->setThumbnail(ImageGraphics::rgbPixels($image));
		} elseif ($format === self::PALETTE_THUMB) {
			[$palette, $pixels] = ImageGraphics::paletteQuantize($image);
			$this->setPalette($palette);
			$this->setThumbnail($pixels);
		} else {
			$this->setPalette(null);
			$this->setThumbnail($jpeg ?? ImageGraphics::encodeJpeg($image, $quality));
		}
		return true;
	}

	/**
	 * Writes the JFXX as a binary APP0 payload.
	 * @throws \InvalidArgumentException When a palette/color dimension exceeds 255 pixels.
	 * @return false|string The JFXX binary data, or false for an unknown format.
	 */
	public function toBinary(): false|string
	{
		$width = $this->getXThumbnail();
		$height = $this->getYThumbnail();
		$format = $this->getFormat();
		$thumbnail = (string) $this->getThumbnail();
		if ($width > 255 || $height > 255) {
			throw new \InvalidArgumentException(sprintf('JFXX thumbnail of %sx%s pixels exceeds the 255x255 limit.', $width, $height));
		}
		$head = pack('a5C', self::IDENTIFIER, $format);
		if ($format === self::COLOR_THUMB) {
			return $head . pack('CC', $width, $height) . $thumbnail;
		}
		if ($format === self::PALETTE_THUMB) {
			$palette = str_pad((string) $this->getPalette(), self::PALETTE_SIZE, "\0", STR_PAD_RIGHT);
			return $head . pack('CC', $width, $height) . $palette . $thumbnail;
		}
		if ($format === self::JPEG_THUMB) {
			return $head . $thumbnail;
		}
		return false;
	}

	/**
	 * Expands the palette and per-pixel index data to RGB pixels, three bytes per pixel.
	 * @return string The RGB pixel bytes.
	 */
	private function paletteRgbPixels(): string
	{
		$palette = (string) $this->getPalette();
		$thumbnail = (string) $this->getThumbnail();
		$data = '';
		for ($i = 0, $count = strlen($thumbnail); $i < $count; $i++) {
			$index = ord($thumbnail[$i]) * 3;
			$data .= substr($palette, $index, 3);
		}
		return $data;
	}
}
