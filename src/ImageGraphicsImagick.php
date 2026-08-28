<?php

/**
 * ImageGraphicsImagick class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image;

/**
 * ImageGraphicsImagick class.
 *
 * The ImageMagick (`ext-imagick`) implementation of {@see ImageGraphicsLibraryInterface},
 * operating on `\Imagick` objects.  Reach it through {@see ImageGraphics::getLibrary()}
 * rather than constructing it directly.
 *
 * ImageMagick carries the color forms GD cannot — separated (CMYK), more than eight bits
 * per sample, and ICC transforms — which {@see supports()} reports; its format support
 * follows the delegates the build was linked against.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class ImageGraphicsImagick implements ImageGraphicsLibraryInterface
{
	/**
	 * Returns the library's {@see ImageGraphicsMode} name.
	 * @return string The mode name.
	 */
	public function getMode(): string
	{
		return ImageGraphicsMode::Imagick;
	}

	/**
	 * Indicates whether ext-imagick is loaded.
	 * @return bool Whether ImageMagick can be used.
	 */
	public function getIsAvailable(): bool
	{
		return extension_loaded('imagick');
	}

	/**
	 * Indicates whether ImageMagick provides a capability on this installation.  The
	 * format capabilities are asked of the build's delegates, and the bit depth of its
	 * quantum size.
	 * @param string $capability A `Capability*` constant.
	 * @return bool Whether the capability is provided.
	 */
	public function supports(string $capability): bool
	{
		if (!$this->getIsAvailable()) {
			return false;
		}
		return match ($capability) {
			self::CapabilityJpeg => \Imagick::queryFormats('JPEG') !== [],
			self::CapabilityPng => \Imagick::queryFormats('PNG') !== [],
			self::CapabilityWebP => \Imagick::queryFormats('WEBP') !== [],
			self::CapabilityPalette, self::CapabilityCmyk,
			self::CapabilityICCEmbed, self::CapabilityICCTransform => true,
			self::CapabilityHighBitDepth => ((int) (\Imagick::getQuantumDepth()['quantumDepthLong'] ?? 8)) > 8,
			default => false,
		};
	}

	/**
	 * Indicates whether a value is an ImageMagick image object.
	 * @param mixed $image The value to test.
	 * @return bool Whether the value is an `\Imagick`.
	 */
	public function isImage(mixed $image): bool
	{
		return $image instanceof \Imagick;
	}

	/**
	 * Narrows an image to ImageMagick's own type.
	 * @param \GdImage|\Imagick $image The image.
	 * @throws \InvalidArgumentException When the image belongs to another library.
	 * @return \Imagick The ImageMagick image.
	 */
	protected function imagickImage(\GdImage|\Imagick $image): \Imagick
	{
		if (!$image instanceof \Imagick) {
			throw new \InvalidArgumentException(sprintf('The %s graphics library cannot operate on a %s image.', ImageGraphicsMode::Imagick, get_debug_type($image)));
		}
		return $image;
	}

	/**
	 * Returns the pixel dimensions of an image.
	 * @param \GdImage|\Imagick $image The image.
	 * @return array The [width, height] in pixels.
	 */
	public function getSize(\GdImage|\Imagick $image): array
	{
		$image = $this->imagickImage($image);
		return [$image->getImageWidth(), $image->getImageHeight()];
	}

	/**
	 * Exports the uncompressed RGB pixels of an image, three bytes per pixel, row-major.
	 * @param \GdImage|\Imagick $image The source image.
	 * @return string The RGB24 pixel bytes.
	 */
	public function rgbPixels(\GdImage|\Imagick $image): string
	{
		$image = $this->imagickImage($image);
		[$width, $height] = $this->getSize($image);
		$pixels = $image->exportImagePixels(0, 0, $width, $height, 'RGB', \Imagick::PIXEL_CHAR);
		return pack('C*', ...$pixels);
	}

	/**
	 * Builds an image from uncompressed RGB pixels, three bytes per pixel, row-major.
	 * @param string $rgb The RGB24 pixel bytes.
	 * @param int $width The width in pixels.
	 * @param int $height The height in pixels.
	 * @return false|\Imagick The image, or false on failure.
	 */
	public function fromRgbPixels(string $rgb, int $width, int $height): false|\Imagick
	{
		$image = new \Imagick();
		$image->newImage($width, $height, 'black', 'png');
		$image->importImagePixels(0, 0, $width, $height, 'RGB', \Imagick::PIXEL_CHAR, array_values(unpack('C*', $rgb)));
		return $image;
	}

	/**
	 * Decodes encoded image bytes (JPEG, PNG, ...) into an image.
	 * @param string $bytes The encoded image bytes.
	 * @return false|\Imagick The decoded image, or false when undecodable.
	 */
	public function decode(string $bytes): false|\Imagick
	{
		$image = new \Imagick();
		try {
			$image->readImageBlob($bytes);
		} catch (\ImagickException $e) {
			return false;
		}
		return $image;
	}

	/**
	 * Encodes an image, leaving the source image untouched.  A format is written only
	 * when the build's delegates carry it, which {@see supports()} reports; PNG is
	 * lossless and ignores the quality.
	 * @param \GdImage|\Imagick $image The source image.
	 * @param string $format A `Format*` constant. Default {@see FormatJpeg}.
	 * @param int $quality The quality of a lossy encoding. Default 75.
	 * @return false|string The encoded bytes, or false when unsupported or on failure.
	 */
	public function encode(\GdImage|\Imagick $image, string $format = self::FormatJpeg, int $quality = 75): false|string
	{
		$capability = match ($format) {
			self::FormatJpeg => self::CapabilityJpeg,
			self::FormatPng => self::CapabilityPng,
			self::FormatWebP => self::CapabilityWebP,
			default => '',
		};
		if (!$this->supports($capability)) {
			return false;
		}
		$encoded = clone $this->imagickImage($image);
		try {
			$encoded->setImageFormat($format);
			if ($format !== self::FormatPng) {
				$encoded->setImageCompressionQuality($quality);
			}
			$bytes = $encoded->getImageBlob();
		} catch (\ImagickException $e) {
			return false;
		} finally {
			$encoded->clear();
		}
		return $bytes;
	}

	/**
	 * Exports the pixels as separated (CMYK) bytes, converting through ImageMagick's own
	 * colorspace machinery — which honors an embedded profile when the image carries one.
	 * @param \GdImage|\Imagick $image The source image.
	 * @return false|string The CMYK bytes, four per pixel, or false on failure.
	 */
	public function cmykPixels(\GdImage|\Imagick $image): false|string
	{
		$image = $this->imagickImage($image);
		[$width, $height] = $this->getSize($image);
		$separated = clone $image;
		try {
			$separated->transformImageColorspace(\Imagick::COLORSPACE_CMYK);
			$values = $separated->exportImagePixels(0, 0, $width, $height, 'CMYK', \Imagick::PIXEL_CHAR);
		} catch (\ImagickException $e) {
			return false;
		} finally {
			$separated->clear();
		}
		return pack('C*', ...$values);
	}

	/**
	 * Builds an image from separated (CMYK) bytes, converting back to RGB so the result
	 * behaves like every other image this library returns.
	 * @param string $cmyk The CMYK pixel bytes, four per pixel.
	 * @param int $width The width in pixels.
	 * @param int $height The height in pixels.
	 * @return false|\Imagick The image, or false on failure.
	 */
	public function fromCmykPixels(string $cmyk, int $width, int $height): false|\Imagick
	{
		$image = new \Imagick();
		try {
			$image->newImage($width, $height, 'white', 'png');
			$image->transformImageColorspace(\Imagick::COLORSPACE_CMYK);
			$image->importImagePixels(0, 0, $width, $height, 'CMYK', \Imagick::PIXEL_CHAR, array_values(unpack('C*', $cmyk)));
			$image->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
		} catch (\ImagickException $e) {
			$image->clear();
			return false;
		}
		return $image;
	}

	/**
	 * Returns the ICC profile embedded in an image.
	 * @param \GdImage|\Imagick $image The image.
	 * @return ?string The ICC profile bytes, or null when the image carries none.
	 */
	public function getICCProfile(\GdImage|\Imagick $image): ?string
	{
		$image = $this->imagickImage($image);
		try {
			$profile = $image->getImageProfile('icc');
		} catch (\ImagickException $e) {   // thrown when the image has no such profile
			return null;
		}
		return $profile === '' ? null : $profile;
	}

	/**
	 * Attaches an ICC profile to an image, converting the pixels when the image already
	 * carries one — ImageMagick performs the ICC transform as part of the attachment.  A
	 * null removes the profile without converting.
	 * @param \GdImage|\Imagick $image The image, modified in place.
	 * @param ?string $profile The ICC profile bytes, or null to remove any profile.
	 * @return bool Whether the profile was applied or removed.
	 */
	public function setICCProfile(\GdImage|\Imagick $image, ?string $profile): bool
	{
		$image = $this->imagickImage($image);
		try {
			if ($profile === null) {
				if ($this->getICCProfile($image) === null) {
					return true;   // already carries none
				}
				$image->removeImageProfile('icc');
				return true;
			}
			return (bool) $image->profileImage('icc', $profile);
		} catch (\ImagickException $e) {   // an unusable profile, or none to remove
			return false;
		}
	}

	/**
	 * Converts an image's pixels from one color space to another by attaching the source
	 * profile and then the destination profile: the second attachment is the conversion,
	 * performed by ImageMagick's color-management library, which — unlike the software
	 * engine behind {@see ImageGraphicsGD} — also handles lookup-table profiles.  The
	 * image is left carrying the destination profile.
	 * @param \GdImage|\Imagick $image The image, converted in place.
	 * @param string $source The source ICC profile bytes.
	 * @param string $destination The destination ICC profile bytes.
	 * @return bool Whether the pixels were converted.
	 */
	public function transformICCProfile(\GdImage|\Imagick $image, string $source, string $destination): bool
	{
		$image = $this->imagickImage($image);
		try {
			if ($this->getICCProfile($image) === null) {
				$image->profileImage('icc', $source);   // no conversion: nothing to convert from
			}
			return (bool) $image->profileImage('icc', $destination);
		} catch (\ImagickException $e) {
			return false;
		}
	}

	/**
	 * Resamples an image to the given dimensions.
	 * @param \GdImage|\Imagick $image The source image.
	 * @param int $width The target width in pixels.
	 * @param int $height The target height in pixels.
	 * @return false|\Imagick The resampled image, or false on failure.
	 */
	public function resampled(\GdImage|\Imagick $image, int $width, int $height): false|\Imagick
	{
		$resampled = clone $this->imagickImage($image);
		$resampled->resizeImage($width, $height, \Imagick::FILTER_LANCZOS, 1);
		return $resampled;
	}

	/**
	 * Reduces an image to black and white, one byte per pixel row-major: "\x00" for a
	 * black pixel and "\x01" for a white pixel.
	 * @param \GdImage|\Imagick $image The source image.
	 * @param bool $dither Whether to dither the reduction. Default true.
	 * @return false|string The per-pixel bit bytes, or false on failure.
	 */
	public function monoPixels(\GdImage|\Imagick $image, bool $dither = true): false|string
	{
		$image = $this->imagickImage($image);
		[$width, $height] = $this->getSize($image);
		$mono = clone $image;
		$mono->quantizeImage(2, \Imagick::COLORSPACE_GRAY, 0, $dither, false);
		$values = $mono->exportImagePixels(0, 0, $width, $height, 'R', \Imagick::PIXEL_CHAR);
		$mono->clear();
		$min = min($values);
		$max = max($values);
		// A flat image quantizes to one level; classify it by brightness alone.
		$threshold = $min === $max ? 128 : ($min + $max) / 2;
		$data = '';
		foreach ($values as $value) {
			$data .= $value >= $threshold ? "\x01" : "\x00";
		}
		return $data;
	}

	/**
	 * Quantizes an image to at most 256 colors, returning the 768-byte RGB palette and
	 * one palette-index byte per pixel, row-major.
	 * @param \GdImage|\Imagick $image The source image.
	 * @return array The [palette, pixels] pair.
	 */
	public function paletteQuantize(\GdImage|\Imagick $image): array
	{
		$image = $this->imagickImage($image);
		[$width, $height] = $this->getSize($image);
		$quantized = clone $image;
		$quantized->quantizeImage(256, \Imagick::COLORSPACE_SRGB, 0, false, false);
		$values = $quantized->exportImagePixels(0, 0, $width, $height, 'RGB', \Imagick::PIXEL_CHAR);
		$quantized->clear();
		$indexes = [];
		$palette = '';
		$pixels = '';
		for ($i = 0, $count = count($values); $i < $count; $i += 3) {
			$key = ($values[$i] << 16) | ($values[$i + 1] << 8) | $values[$i + 2];
			if (!isset($indexes[$key])) {
				if (count($indexes) < 256) {
					$indexes[$key] = count($indexes);
					$palette .= chr($values[$i]) . chr($values[$i + 1]) . chr($values[$i + 2]);
				} else {
					$indexes[$key] = $this->closestPaletteIndex($palette, $values[$i], $values[$i + 1], $values[$i + 2]);
				}
			}
			$pixels .= chr($indexes[$key]);
		}
		return [str_pad($palette, 768, "\0", STR_PAD_RIGHT), $pixels];
	}

	/**
	 * Finds the palette entry closest to a color by squared RGB distance, for the colors
	 * beyond the 256-entry budget.
	 * @param string $palette The RGB palette bytes, three per entry.
	 * @param int $red The red component.
	 * @param int $green The green component.
	 * @param int $blue The blue component.
	 * @return int The index of the closest palette entry.
	 */
	protected function closestPaletteIndex(string $palette, int $red, int $green, int $blue): int
	{
		$best = 0;
		$bestDistance = PHP_INT_MAX;
		for ($k = 0, $count = intdiv(strlen($palette), 3); $k < $count; $k++) {
			$dr = ord($palette[$k * 3]) - $red;
			$dg = ord($palette[$k * 3 + 1]) - $green;
			$db = ord($palette[$k * 3 + 2]) - $blue;
			$distance = $dr * $dr + $dg * $dg + $db * $db;
			if ($distance < $bestDistance) {
				$bestDistance = $distance;
				$best = $k;
			}
		}
		return $best;
	}
}
