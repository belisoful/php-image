<?php

/**
 * ImageGraphicsLibraryInterface interface file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image;

/**
 * ImageGraphicsLibraryInterface interface.
 *
 * The raster operations one graphics library performs on its own image objects.  A
 * library is reached through {@see ImageGraphics}, which resolves the implementation
 * from a {@see ImageGraphicsMode} name (or from an image object's own type) and
 * delegates; the metadata classes call the facade and never an implementation directly.
 *
 * The image-taking methods only accept images of the implementation's own library and
 * throw {@see \InvalidArgumentException} otherwise — the facade routes
 * by image type, so a mismatch is a programming error rather than a supported conversion.
 *
 * {@see supports()} answers what the library can actually do on this installation, so a
 * caller can prefer the capable backend instead of assuming the two are interchangeable:
 * GD cannot represent CMYK, more than eight bits per sample, or ICC transforms at all,
 * and both libraries' format support depends on how the extension was built.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
interface ImageGraphicsLibraryInterface
{
	/** @var string Encodes and decodes JPEG. */
	public const CapabilityJpeg = 'jpeg';

	/** @var string Encodes and decodes PNG. */
	public const CapabilityPng = 'png';

	/** @var string Encodes and decodes WebP. */
	public const CapabilityWebP = 'webp';

	/** @var string Quantizes to a color palette. */
	public const CapabilityPalette = 'palette';

	/** @var string Converts separated (CMYK) pixel data to and from its images. */
	public const CapabilityCmyk = 'cmyk';

	/** @var string Carries an embedded ICC profile on the image object. */
	public const CapabilityICCEmbed = 'iccEmbed';

	/** @var string Converts pixels from one ICC profile's color space to another's. */
	public const CapabilityICCTransform = 'iccTransform';

	/**
	 * @var string Holds more than eight bits per sample.  Unlike the other capabilities
	 *   this one backs no operation: it exists so a caller decoding deep samples can pick
	 *   the library that will not truncate them.  GD's image model is eight bits per
	 *   channel, so no software fallback can honestly provide it.
	 */
	public const CapabilityHighBitDepth = 'highBitDepth';

	/** @var string The JPEG encoding of {@see encode()}; lossy, so it honors the quality. */
	public const FormatJpeg = 'jpeg';

	/** @var string The PNG encoding of {@see encode()}; lossless, so the quality is ignored. */
	public const FormatPng = 'png';

	/** @var string The WebP encoding of {@see encode()}; lossy, so it honors the quality. */
	public const FormatWebP = 'webp';

	/**
	 * Returns the library's {@see ImageGraphicsMode} name.
	 * @return string The mode name.
	 */
	public function getMode(): string;

	/**
	 * Indicates whether the library's extension is loaded.
	 * @return bool Whether the library can be used.
	 */
	public function getIsAvailable(): bool;

	/**
	 * Indicates whether the library provides a capability on this installation.
	 * @param string $capability A `Capability*` constant.
	 * @return bool Whether the capability is provided; false for an unknown capability.
	 */
	public function supports(string $capability): bool;

	/**
	 * Indicates whether a value is one of this library's image objects.
	 * @param mixed $image The value to test.
	 * @return bool Whether the value is this library's image type.
	 */
	public function isImage(mixed $image): bool;

	/**
	 * Returns the pixel dimensions of an image.
	 * @param \GdImage|\Imagick $image The image.
	 * @return array The [width, height] in pixels.
	 */
	public function getSize(\GdImage|\Imagick $image): array;

	/**
	 * Exports the uncompressed RGB pixels of an image, three bytes per pixel, row-major.
	 * @param \GdImage|\Imagick $image The source image.
	 * @return string The RGB24 pixel bytes.
	 */
	public function rgbPixels(\GdImage|\Imagick $image): string;

	/**
	 * Builds an image from uncompressed RGB pixels, three bytes per pixel, row-major.
	 * @param string $rgb The RGB24 pixel bytes.
	 * @param int $width The width in pixels.
	 * @param int $height The height in pixels.
	 * @return false|\GdImage|\Imagick The image, or false on failure.
	 */
	public function fromRgbPixels(string $rgb, int $width, int $height): false|\GdImage|\Imagick;

	/**
	 * Decodes encoded image bytes (JPEG, PNG, ...) into an image.
	 * @param string $bytes The encoded image bytes.
	 * @return false|\GdImage|\Imagick The decoded image, or false when undecodable.
	 */
	public function decode(string $bytes): false|\GdImage|\Imagick;

	/**
	 * Encodes an image, leaving the source image untouched.  The quality applies to the
	 * lossy formats; PNG is lossless and ignores it.
	 * @param \GdImage|\Imagick $image The source image.
	 * @param string $format A `Format*` constant. Default {@see FormatJpeg}.
	 * @param int $quality The quality of a lossy encoding. Default 75.
	 * @return false|string The encoded bytes, or false when this build cannot write the
	 *   format (the matching {@see supports()} capability is false) or the encoding fails.
	 */
	public function encode(\GdImage|\Imagick $image, string $format = self::FormatJpeg, int $quality = 75): false|string;

	/**
	 * Exports the pixels of an image as separated (CMYK) bytes, four per pixel,
	 * row-major, with 0 meaning no ink.
	 * @param \GdImage|\Imagick $image The source image.
	 * @return false|string The CMYK bytes, or false when unsupported or on failure.
	 */
	public function cmykPixels(\GdImage|\Imagick $image): false|string;

	/**
	 * Builds an image from separated (CMYK) bytes, four per pixel, row-major.
	 * @param string $cmyk The CMYK pixel bytes.
	 * @param int $width The width in pixels.
	 * @param int $height The height in pixels.
	 * @return false|\GdImage|\Imagick The image, or false when unsupported or on failure.
	 */
	public function fromCmykPixels(string $cmyk, int $width, int $height): false|\GdImage|\Imagick;

	/**
	 * Returns the ICC profile embedded in an image.
	 * @param \GdImage|\Imagick $image The image.
	 * @return ?string The ICC profile bytes, or null when the image carries none or the
	 *   library cannot carry profiles ({@see CapabilityICCEmbed}).
	 */
	public function getICCProfile(\GdImage|\Imagick $image): ?string;

	/**
	 * Attaches an ICC profile to an image, **converting the pixels** when the image
	 * already carries one — that conversion is the ICC transform.  A null removes the
	 * profile without converting.
	 * @param \GdImage|\Imagick $image The image, modified in place.
	 * @param ?string $profile The ICC profile bytes, or null to remove any profile.
	 * @return bool Whether the profile was applied or removed; false when the library
	 *   cannot carry profiles ({@see CapabilityICCEmbed}) or the profile is unusable.
	 */
	public function setICCProfile(\GdImage|\Imagick $image, ?string $profile): bool;

	/**
	 * Converts an image's pixels from one ICC profile's color space to another's.  Unlike
	 * {@see setICCProfile()} the source space is given rather than read from the image, so
	 * a library that cannot carry a profile can still perform the conversion.
	 * @param \GdImage|\Imagick $image The image, converted in place.
	 * @param string $source The source ICC profile bytes.
	 * @param string $destination The destination ICC profile bytes.
	 * @return bool Whether the pixels were converted; false when either profile is
	 *   unreadable or of a form this library cannot evaluate.
	 */
	public function transformICCProfile(\GdImage|\Imagick $image, string $source, string $destination): bool;

	/**
	 * Resamples an image to the given dimensions, answering in the same library.
	 * @param \GdImage|\Imagick $image The source image.
	 * @param int $width The target width in pixels.
	 * @param int $height The target height in pixels.
	 * @return false|\GdImage|\Imagick The resampled image, or false on failure.
	 */
	public function resampled(\GdImage|\Imagick $image, int $width, int $height): false|\GdImage|\Imagick;

	/**
	 * Reduces an image to black and white, one byte per pixel row-major: "\x00" for a
	 * black pixel and "\x01" for a white pixel.
	 * @param \GdImage|\Imagick $image The source image.
	 * @param bool $dither Whether to dither the reduction. Default true.
	 * @return false|string The per-pixel bit bytes, or false on failure.
	 */
	public function monoPixels(\GdImage|\Imagick $image, bool $dither = true): false|string;

	/**
	 * Quantizes an image to at most 256 colors, returning the 768-byte RGB palette and
	 * one palette-index byte per pixel, row-major.
	 * @param \GdImage|\Imagick $image The source image.
	 * @return array The [palette, pixels] pair.
	 */
	public function paletteQuantize(\GdImage|\Imagick $image): array;
}
