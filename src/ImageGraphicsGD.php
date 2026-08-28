<?php

/**
 * ImageGraphicsGD class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image;

use Belisoful\Image\ICC\ICCProfile;
use Belisoful\Image\ICC\ICCTransform;

/**
 * ImageGraphicsGD class.
 *
 * The GD (`ext-gd`) implementation of {@see ImageGraphicsLibraryInterface}, operating on
 * `\GdImage` objects.  Reach it through {@see ImageGraphics::getLibrary()} rather than
 * constructing it directly.
 *
 * GD is a true-color 8-bit-per-sample library, so it cannot carry an embedded ICC profile
 * and cannot hold more than eight bits per sample; {@see supports()} reports both as
 * false.  The abilities that are only arithmetic are provided here rather than given up:
 * {@see cmykPixels()}/{@see fromCmykPixels()} separate and recombine CMYK, and
 * {@see transformICCProfile()} converts between matrix/TRC color spaces through
 * {@see ICCTransform}.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class ImageGraphicsGD implements ImageGraphicsLibraryInterface
{
	/**
	 * Returns the library's {@see ImageGraphicsMode} name.
	 * @return string The mode name.
	 */
	public function getMode(): string
	{
		return ImageGraphicsMode::GD;
	}

	/**
	 * Indicates whether ext-gd is loaded.
	 * @return bool Whether GD can be used.
	 */
	public function getIsAvailable(): bool
	{
		return extension_loaded('gd');
	}

	/**
	 * Indicates whether GD provides a capability on this installation.  The format
	 * capabilities follow how the extension was built.  Carrying an embedded profile and
	 * holding deep samples are outside GD's image model; the CMYK conversion and the
	 * matrix/TRC transform are provided in software.
	 * @param string $capability A `Capability*` constant.
	 * @return bool Whether the capability is provided.
	 */
	public function supports(string $capability): bool
	{
		return match ($capability) {
			self::CapabilityJpeg => function_exists('imagejpeg'),
			self::CapabilityPng => function_exists('imagepng'),
			self::CapabilityWebP => function_exists('imagewebp'),
			// The CMYK conversion and the matrix/TRC transform are this class's own
			// arithmetic, so they hold wherever GD does.
			self::CapabilityPalette, self::CapabilityCmyk, self::CapabilityICCTransform => $this->getIsAvailable(),
			self::CapabilityICCEmbed, self::CapabilityHighBitDepth => false,
			default => false,
		};
	}

	/**
	 * Indicates whether a value is a GD image object.
	 * @param mixed $image The value to test.
	 * @return bool Whether the value is a `\GdImage`.
	 */
	public function isImage(mixed $image): bool
	{
		return $image instanceof \GdImage;
	}

	/**
	 * Narrows an image to GD's own type.
	 * @param \GdImage|\Imagick $image The image.
	 * @throws \InvalidArgumentException When the image belongs to another library.
	 * @return \GdImage The GD image.
	 */
	protected function gdImage(\GdImage|\Imagick $image): \GdImage
	{
		if (!$image instanceof \GdImage) {
			throw new \InvalidArgumentException(sprintf('The %s graphics library cannot operate on a %s image.', ImageGraphicsMode::GD, get_debug_type($image)));
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
		$image = $this->gdImage($image);
		return [imagesx($image), imagesy($image)];
	}

	/**
	 * Exports the uncompressed RGB pixels of an image, three bytes per pixel, row-major.
	 * A palette image's indices are resolved to their colors.
	 * @param \GdImage|\Imagick $image The source image.
	 * @return string The RGB24 pixel bytes.
	 */
	public function rgbPixels(\GdImage|\Imagick $image): string
	{
		$image = $this->gdImage($image);
		$width = imagesx($image);
		$height = imagesy($image);
		$data = '';
		for ($y = 0; $y < $height; $y++) {
			for ($x = 0; $x < $width; $x++) {
				$rgb = imagecolorat($image, $x, $y);
				if (!imageistruecolor($image)) {
					$c = imagecolorsforindex($image, $rgb);
					$rgb = ($c['red'] << 16) | ($c['green'] << 8) | $c['blue'];
				}
				$data .= chr(($rgb >> 16) & 0xFF) . chr(($rgb >> 8) & 0xFF) . chr($rgb & 0xFF);
			}
		}
		return $data;
	}

	/**
	 * Builds an image from uncompressed RGB pixels, three bytes per pixel, row-major.
	 * @param string $rgb The RGB24 pixel bytes.
	 * @param int $width The width in pixels.
	 * @param int $height The height in pixels.
	 * @return false|\GdImage The image, or false on failure.
	 */
	public function fromRgbPixels(string $rgb, int $width, int $height): false|\GdImage
	{
		$image = imagecreatetruecolor($width, $height);
		if ($image === false) {
			return false;
		}
		$i = 0;
		for ($y = 0; $y < $height; $y++) {
			for ($x = 0; $x < $width; $x++) {
				$color = (ord($rgb[$i]) << 16) | (ord($rgb[$i + 1]) << 8) | ord($rgb[$i + 2]);
				imagesetpixel($image, $x, $y, $color);
				$i += 3;
			}
		}
		return $image;
	}

	/**
	 * Decodes encoded image bytes (JPEG, PNG, ...) into an image.
	 * @param string $bytes The encoded image bytes.
	 * @return false|\GdImage The decoded image, or false when undecodable.
	 */
	public function decode(string $bytes): false|\GdImage
	{
		return @imagecreatefromstring($bytes);
	}

	/**
	 * Encodes an image.  GD writes each format only when the extension was built with
	 * it, which {@see supports()} reports.  PNG is lossless: the quality is mapped onto
	 * GD's inverted 0-9 zlib compression level rather than discarded.
	 * @param \GdImage|\Imagick $image The source image.
	 * @param string $format A `Format*` constant. Default {@see FormatJpeg}.
	 * @param int $quality The quality of a lossy encoding. Default 75.
	 * @return false|string The encoded bytes, or false when unsupported or on failure.
	 */
	public function encode(\GdImage|\Imagick $image, string $format = self::FormatJpeg, int $quality = 75): false|string
	{
		$image = $this->gdImage($image);
		$capability = match ($format) {
			self::FormatJpeg => self::CapabilityJpeg,
			self::FormatPng => self::CapabilityPng,
			self::FormatWebP => self::CapabilityWebP,
			default => '',
		};
		if (!$this->supports($capability)) {
			return false;
		}
		ob_start();
		$written = match ($format) {
			self::FormatJpeg => imagejpeg($image, null, $quality),
			self::FormatPng => imagepng($image, null, max(0, min(9, (int) round((100 - $quality) / 11.2)))),
			default => imagewebp($image, null, $quality),
		};
		$bytes = (string) ob_get_clean();
		return $written ? $bytes : false;
	}

	/**
	 * Exports the pixels as separated (CMYK) bytes.  GD holds only RGB, so the separation
	 * is the straightforward one — the complement of each channel with the common black
	 * pulled out — without a device profile's ink behavior.  Pass a profile-driven
	 * conversion through {@see ImageGraphicsImagick} when the ink matters.
	 * @param \GdImage|\Imagick $image The source image.
	 * @return false|string The CMYK bytes, four per pixel.
	 */
	public function cmykPixels(\GdImage|\Imagick $image): false|string
	{
		$rgb = $this->rgbPixels($image);
		$out = '';
		for ($i = 0, $count = intdiv(strlen($rgb), 3); $i < $count; $i++) {
			$red = ord($rgb[$i * 3]);
			$green = ord($rgb[$i * 3 + 1]);
			$blue = ord($rgb[$i * 3 + 2]);
			$black = 255 - max($red, $green, $blue);
			$ink = 255 - $black;
			$out .= $ink === 0
				? "\0\0\0\xFF"   // pure black carries no chromatic ink
				: chr((int) round((255 - $red - $black) * 255 / $ink))
					. chr((int) round((255 - $green - $black) * 255 / $ink))
					. chr((int) round((255 - $blue - $black) * 255 / $ink))
					. chr($black);
		}
		return $out;
	}

	/**
	 * Builds an image from separated (CMYK) bytes, inverting the {@see cmykPixels()}
	 * separation.
	 * @param string $cmyk The CMYK pixel bytes, four per pixel.
	 * @param int $width The width in pixels.
	 * @param int $height The height in pixels.
	 * @return false|\GdImage The image, or false on failure.
	 */
	public function fromCmykPixels(string $cmyk, int $width, int $height): false|\GdImage
	{
		$rgb = '';
		for ($i = 0, $count = intdiv(strlen($cmyk), 4); $i < $count; $i++) {
			$black = ord($cmyk[$i * 4 + 3]);
			$ink = 255 - $black;
			for ($channel = 0; $channel < 3; $channel++) {
				$rgb .= chr((int) round((255 - ord($cmyk[$i * 4 + $channel])) * $ink / 255));
			}
		}
		return $this->fromRgbPixels($rgb, $width, $height);
	}

	/**
	 * Returns the ICC profile embedded in an image.  GD has no place to keep one and
	 * drops any profile on decode, so this is always null.
	 * @param \GdImage|\Imagick $image The image.
	 * @return ?string Always null.
	 */
	public function getICCProfile(\GdImage|\Imagick $image): ?string
	{
		$this->gdImage($image);
		return null;
	}

	/**
	 * Attaches an ICC profile to an image.  A `\GdImage` cannot carry one, so this always
	 * fails ({@see CapabilityICCEmbed} is false); to convert pixels between two spaces
	 * without carrying a profile, use {@see transformICCProfile()}.
	 * @param \GdImage|\Imagick $image The image.
	 * @param ?string $profile The ICC profile bytes, or null to remove any profile.
	 * @return bool Always false.
	 */
	public function setICCProfile(\GdImage|\Imagick $image, ?string $profile): bool
	{
		$this->gdImage($image);
		return false;
	}

	/**
	 * Converts an image's pixels between two color spaces with {@see ICCTransform}, the
	 * software matrix/TRC engine that stands in for the color management GD lacks.  A
	 * profile whose conversion needs multi-dimensional lookup tables (CMYK and printer
	 * profiles) is refused rather than approximated.
	 * @param \GdImage|\Imagick $image The image, converted in place.
	 * @param string $source The source ICC profile bytes.
	 * @param string $destination The destination ICC profile bytes.
	 * @return bool Whether the pixels were converted.
	 */
	public function transformICCProfile(\GdImage|\Imagick $image, string $source, string $destination): bool
	{
		$image = $this->gdImage($image);
		$sourceProfile = ICCProfile::parse($source);
		$destinationProfile = ICCProfile::parse($destination);
		if ($sourceProfile === null || $destinationProfile === null) {
			return false;
		}
		$transform = ICCTransform::between($sourceProfile, $destinationProfile);
		if ($transform === null) {
			return false;
		}
		$width = imagesx($image);
		$height = imagesy($image);
		$converted = $transform->rgbPixels($this->rgbPixels($image));
		$i = 0;
		for ($y = 0; $y < $height; $y++) {
			for ($x = 0; $x < $width; $x++) {
				imagesetpixel($image, $x, $y, (ord($converted[$i]) << 16) | (ord($converted[$i + 1]) << 8) | ord($converted[$i + 2]));
				$i += 3;
			}
		}
		return true;
	}

	/**
	 * Resamples an image to the given dimensions.
	 * @param \GdImage|\Imagick $image The source image.
	 * @param int $width The target width in pixels.
	 * @param int $height The target height in pixels.
	 * @return false|\GdImage The resampled image, or false on failure.
	 */
	public function resampled(\GdImage|\Imagick $image, int $width, int $height): false|\GdImage
	{
		$image = $this->gdImage($image);
		$resampled = imagecreatetruecolor($width, $height);
		if ($resampled === false || !imagecopyresampled($resampled, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image))) {
			return false;
		}
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
		$image = $this->gdImage($image);
		$width = imagesx($image);
		$height = imagesy($image);
		$mono = imagecreatetruecolor($width, $height);
		if ($mono === false || !imagecopy($mono, $image, 0, 0, 0, 0, $width, $height)) {
			return false;
		}
		imagefilter($mono, IMG_FILTER_GRAYSCALE);
		imagetruecolortopalette($mono, $dither, 2);
		$blackIndex = imagecolorclosest($mono, 0, 0, 0);
		$data = '';
		for ($y = 0; $y < $height; $y++) {
			for ($x = 0; $x < $width; $x++) {
				$data .= imagecolorat($mono, $x, $y) === $blackIndex ? "\x00" : "\x01";
			}
		}
		imagedestroy($mono);
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
		$image = $this->gdImage($image);
		$width = imagesx($image);
		$height = imagesy($image);
		$quantized = imagecreatetruecolor($width, $height);
		imagecopy($quantized, $image, 0, 0, 0, 0, $width, $height);
		imagetruecolortopalette($quantized, true, 256);

		$palette = '';
		$total = imagecolorstotal($quantized);
		for ($k = 0; $k < 256; $k++) {
			$c = $k < $total ? imagecolorsforindex($quantized, $k) : ['red' => 0, 'green' => 0, 'blue' => 0];
			$palette .= chr($c['red']) . chr($c['green']) . chr($c['blue']);
		}
		$pixels = '';
		for ($y = 0; $y < $height; $y++) {
			for ($x = 0; $x < $width; $x++) {
				$pixels .= chr(imagecolorat($quantized, $x, $y) & 0xFF);
			}
		}
		imagedestroy($quantized);
		return [$palette, $pixels];
	}
}
