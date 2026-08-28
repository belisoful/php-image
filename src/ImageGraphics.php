<?php

/**
 * ImageGraphics class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image;

/**
 * ImageGraphics class.
 *
 * Converts raster data between raw bytes and the image objects of the available graphics
 * libraries — GD (`\GdImage`) and ImageMagick (`\Imagick`) — so the metadata classes
 * ({@see \Belisoful\Image\Meta\JFIF}, {@see \Belisoful\Image\Meta\JFXX},
 * {@see \Belisoful\Image\Meta\IPTC}) accept and produce either without binding to one.
 *
 * This class is the routing facade: each operation resolves an
 * {@see ImageGraphicsLibraryInterface} — {@see ImageGraphicsGD} or {@see ImageGraphicsImagick} —
 * and delegates to it.  Methods transforming an existing image route by the image's own
 * type and answer in that same library; methods producing a new image take an optional
 * {@see ImageGraphicsMode} name, where a null uses the {@see getDefaultMode() default}
 * (GD when loaded, otherwise Imagick).
 *
 * The primitives are {@see getSize()}, {@see rgbPixels()}/{@see fromRgbPixels()} (RGB24
 * export and import), {@see decode()} (encoded bytes to an image), {@see encodeJpeg()},
 * {@see resampled()}, {@see monoPixels()} (black-and-white reduction with optional
 * dithering), and {@see paletteQuantize()} (a 256-color palette plus per-pixel indices).
 *
 * ```php
 * $image = ImageGraphics::fromRgbPixels($rgb, $w, $h);            // the default library
 * $jpeg  = ImageGraphics::encodeJpeg($image, 90);                 // routed by image type
 * ImageGraphics::supports(ImageGraphicsLibraryInterface::CapabilityCmyk); // what can this do?
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class ImageGraphics
{
	/** @var ?string The explicit default mode, or null to auto-select. */
	private static ?string $_defaultMode = null;

	/** @var array<string, ImageGraphicsLibraryInterface> The resolved libraries, by mode name. */
	private static array $_libraries = [];

	/**
	 * Indicates whether the GD extension is loaded.
	 * @return bool Whether GD is available.
	 */
	public static function hasGd(): bool
	{
		return extension_loaded('gd');
	}

	/**
	 * Indicates whether the Imagick extension is loaded.
	 * @return bool Whether ImageMagick is available.
	 */
	public static function hasImagick(): bool
	{
		return extension_loaded('imagick');
	}

	/**
	 * Indicates whether a graphics library is available.
	 * @param ?string $mode A {@see ImageGraphicsMode} name, or null for "any library".
	 * @return bool Whether the named library (or any library) is available.
	 */
	public static function hasMode(?string $mode = null): bool
	{
		if ($mode === null) {
			return static::hasGd() || static::hasImagick();
		}
		if ($mode === ImageGraphicsMode::GD) {
			return static::hasGd();
		}
		if ($mode === ImageGraphicsMode::Imagick) {
			return static::hasImagick();
		}
		return false;
	}

	/**
	 * Returns the default graphics library mode: the {@see setDefaultMode()} choice when
	 * made, otherwise GD when loaded, otherwise Imagick when loaded.
	 * @return ?string The default {@see ImageGraphicsMode} name, or null when none is available.
	 */
	public static function getDefaultMode(): ?string
	{
		if (self::$_defaultMode !== null) {
			return self::$_defaultMode;
		}
		if (static::hasGd()) {
			return ImageGraphicsMode::GD;
		}
		if (static::hasImagick()) {
			return ImageGraphicsMode::Imagick;
		}
		return null;
	}

	/**
	 * Sets (or clears, when null) the default graphics library mode.
	 * @param ?string $mode A {@see ImageGraphicsMode} name, or null to auto-select.
	 */
	public static function setDefaultMode(?string $mode): void
	{
		if ($mode !== null) {
			static::validateMode($mode);
		}
		self::$_defaultMode = $mode;
	}

	/**
	 * Resolves a mode name to a loaded library, applying the default for null.
	 * @param ?string $mode A {@see ImageGraphicsMode} name, or null for the default.
	 * @throws \RuntimeException When the name is not a graphics library.
	 * @throws \InvalidArgumentException When the name is not a graphics library.
	 * @throws \RuntimeException When no graphics library is available or the named
	 * @throws \InvalidArgumentException When no graphics library is available or the named
	 *   library's extension is not loaded.
	 * @return string The resolved {@see ImageGraphicsMode} name.
	 */
	protected static function validateMode(?string $mode): string
	{
		if ($mode === null) {
			$mode = static::getDefaultMode();
			if ($mode === null) {
				throw new \RuntimeException('No graphics library is available; enable ext-gd or ext-imagick.');
			}
			return $mode;
		}
		if ($mode !== ImageGraphicsMode::GD && $mode !== ImageGraphicsMode::Imagick) {
			throw new \InvalidArgumentException(sprintf('\'%s\' is not a supported graphics library; use \'GD\' or \'Imagick\'.', $mode));
		}
		if (!static::hasMode($mode)) {
			throw new \RuntimeException(sprintf('The %s graphics library extension is not loaded.', $mode));
		}
		return $mode;
	}

	/**
	 * Returns the library implementation for a mode, applying the default for null.  The
	 * implementations are stateless and shared.
	 * @param ?string $mode A {@see ImageGraphicsMode} name, or null for the default.
	 * @return ImageGraphicsLibraryInterface The library.
	 */
	public static function getLibrary(?string $mode = null): ImageGraphicsLibraryInterface
	{
		$mode = static::validateMode($mode);
		return self::$_libraries[$mode] ??= $mode === ImageGraphicsMode::Imagick
			? new ImageGraphicsImagick()
			: new ImageGraphicsGD();
	}

	/**
	 * Returns the library an image object belongs to.
	 * @param \GdImage|\Imagick $image The image.
	 * @return ImageGraphicsLibraryInterface The image's library.
	 */
	public static function getLibraryOf(\GdImage|\Imagick $image): ImageGraphicsLibraryInterface
	{
		return static::getLibrary(static::getModeOf($image));
	}

	/**
	 * Returns the mode name an image object belongs to.
	 * @param \GdImage|\Imagick $image The image.
	 * @return string The {@see ImageGraphicsMode} name of the image.
	 */
	public static function getModeOf(\GdImage|\Imagick $image): string
	{
		return $image instanceof \GdImage ? ImageGraphicsMode::GD : ImageGraphicsMode::Imagick;
	}

	/**
	 * Indicates whether a graphics library provides a capability on this installation.
	 * Unlike the operations, an unavailable or unknown library answers false rather than
	 * throwing, so a caller can ask about a library it does not have.
	 * @param string $capability An {@see ImageGraphicsLibraryInterface} `Capability*` constant.
	 * @param ?string $mode A {@see ImageGraphicsMode} name, or null for the default.
	 * @return bool Whether the capability is provided.
	 */
	public static function supports(string $capability, ?string $mode = null): bool
	{
		$mode ??= static::getDefaultMode();
		if ($mode === null || !static::hasMode($mode)) {
			return false;
		}
		return static::getLibrary($mode)->supports($capability);
	}

	/**
	 * Returns a library that provides a capability, preferring the default one.  This is
	 * the explicit alternative to a silent fallback: the operations always answer in the
	 * image's own library, so a caller who needs an ability the default library lacks —
	 * deep samples, an embedded profile — asks for the capable library and builds there.
	 * @param string $capability An {@see ImageGraphicsLibraryInterface} `Capability*` constant.
	 * @return ?ImageGraphicsLibraryInterface The capable library, or null when neither provides it.
	 */
	public static function getCapableLibrary(string $capability): ?ImageGraphicsLibraryInterface
	{
		$default = static::getDefaultMode();
		$modes = $default === null
			? [ImageGraphicsMode::GD, ImageGraphicsMode::Imagick]
			: [$default, $default === ImageGraphicsMode::GD ? ImageGraphicsMode::Imagick : ImageGraphicsMode::GD];
		foreach ($modes as $mode) {
			if (static::supports($capability, $mode)) {
				return static::getLibrary($mode);
			}
		}
		return null;
	}

	/**
	 * Indicates whether a value is a graphics-library image object.
	 * @param mixed $image The value to test.
	 * @return bool Whether the value is a `\GdImage` or `\Imagick`.
	 */
	public static function isImage(mixed $image): bool
	{
		return $image instanceof \GdImage || $image instanceof \Imagick;
	}

	/**
	 * Returns the pixel dimensions of an image.
	 * @param \GdImage|\Imagick $image The image.
	 * @return array The [width, height] in pixels.
	 */
	public static function getSize(\GdImage|\Imagick $image): array
	{
		return static::getLibraryOf($image)->getSize($image);
	}

	/**
	 * Exports the uncompressed RGB pixels of an image, three bytes per pixel, row-major.
	 * @param \GdImage|\Imagick $image The source image.
	 * @return string The RGB24 pixel bytes.
	 */
	public static function rgbPixels(\GdImage|\Imagick $image): string
	{
		return static::getLibraryOf($image)->rgbPixels($image);
	}

	/**
	 * Builds an image from uncompressed RGB pixels, three bytes per pixel, row-major.
	 * @param string $rgb The RGB24 pixel bytes.
	 * @param int $width The width in pixels.
	 * @param int $height The height in pixels.
	 * @param ?string $mode The {@see ImageGraphicsMode} to build in; null for the default.
	 * @return false|\GdImage|\Imagick The image, or false on failure.
	 */
	public static function fromRgbPixels(string $rgb, int $width, int $height, ?string $mode = null): false|\GdImage|\Imagick
	{
		return static::getLibrary($mode)->fromRgbPixels($rgb, $width, $height);
	}

	/**
	 * Decodes encoded image bytes (JPEG, PNG, ...) into an image.
	 * @param string $bytes The encoded image bytes.
	 * @param ?string $mode The {@see ImageGraphicsMode} to decode in; null for the default.
	 * @return false|\GdImage|\Imagick The decoded image, or false when undecodable.
	 */
	public static function decode(string $bytes, ?string $mode = null): false|\GdImage|\Imagick
	{
		return static::getLibrary($mode)->decode($bytes);
	}

	/**
	 * Encodes an image in the image's own library, leaving the source untouched.  Both
	 * libraries write only the formats their build carries; ask
	 * {@see supports()} with the matching capability before relying on one.
	 * @param \GdImage|\Imagick $image The source image.
	 * @param string $format An {@see ImageGraphicsLibraryInterface} `Format*` constant. Default JPEG.
	 * @param int $quality The quality of a lossy encoding; PNG is lossless. Default 75.
	 * @return false|string The encoded bytes, or false when unsupported or on failure.
	 */
	public static function encode(\GdImage|\Imagick $image, string $format = ImageGraphicsLibraryInterface::FormatJpeg, int $quality = 75): false|string
	{
		return static::getLibraryOf($image)->encode($image, $format, $quality);
	}

	/**
	 * Encodes an image to JPEG bytes, the encoding the thumbnail carriers use.
	 * @param \GdImage|\Imagick $image The source image.
	 * @param int $quality The JPEG quality. Default 75.
	 * @return string The JPEG bytes, or an empty string when the build cannot write JPEG.
	 */
	public static function encodeJpeg(\GdImage|\Imagick $image, int $quality = 75): string
	{
		return (string) static::encode($image, ImageGraphicsLibraryInterface::FormatJpeg, $quality);
	}

	/**
	 * Exports the pixels of an image as separated (CMYK) bytes, four per pixel.  GD
	 * separates arithmetically; ImageMagick uses its colorspace machinery.
	 * @param \GdImage|\Imagick $image The source image.
	 * @return false|string The CMYK bytes, or false on failure.
	 */
	public static function cmykPixels(\GdImage|\Imagick $image): false|string
	{
		return static::getLibraryOf($image)->cmykPixels($image);
	}

	/**
	 * Builds an image from separated (CMYK) bytes, four per pixel, row-major.
	 * @param string $cmyk The CMYK pixel bytes.
	 * @param int $width The width in pixels.
	 * @param int $height The height in pixels.
	 * @param ?string $mode The {@see ImageGraphicsMode} to build in; null for the default.
	 * @return false|\GdImage|\Imagick The image, or false on failure.
	 */
	public static function fromCmykPixels(string $cmyk, int $width, int $height, ?string $mode = null): false|\GdImage|\Imagick
	{
		return static::getLibrary($mode)->fromCmykPixels($cmyk, $width, $height);
	}

	/**
	 * Returns the ICC profile embedded in an image.
	 * @param \GdImage|\Imagick $image The image.
	 * @return ?string The ICC profile bytes, or null when the image carries none or its
	 *   library cannot carry profiles.
	 */
	public static function getICCProfile(\GdImage|\Imagick $image): ?string
	{
		return static::getLibraryOf($image)->getICCProfile($image);
	}

	/**
	 * Attaches an ICC profile to an image, converting the pixels when the image already
	 * carries one; a null removes the profile.  Only ImageMagick can carry a profile on
	 * the image object, so check {@see supports()} with
	 * {@see ImageGraphicsLibraryInterface::CapabilityICCEmbed} — or use
	 * {@see transformICCProfile()}, which both libraries provide.
	 * @param \GdImage|\Imagick $image The image, modified in place.
	 * @param ?string $profile The ICC profile bytes, or null to remove any profile.
	 * @return bool Whether the profile was applied or removed.
	 */
	public static function setICCProfile(\GdImage|\Imagick $image, ?string $profile): bool
	{
		return static::getLibraryOf($image)->setICCProfile($image, $profile);
	}

	/**
	 * Converts an image's pixels from one ICC profile's color space to another's, in the
	 * image's own library: ImageMagick through its color-management library, GD through
	 * the software matrix/TRC engine ({@see ICCTransform}).  A lookup-table profile — the
	 * usual CMYK or printer profile — is only convertible in ImageMagick.
	 * @param \GdImage|\Imagick $image The image, converted in place.
	 * @param string $source The source ICC profile bytes.
	 * @param string $destination The destination ICC profile bytes.
	 * @return bool Whether the pixels were converted.
	 */
	public static function transformICCProfile(\GdImage|\Imagick $image, string $source, string $destination): bool
	{
		return static::getLibraryOf($image)->transformICCProfile($image, $source, $destination);
	}

	/**
	 * Resamples an image to the given dimensions, answering in the image's own library.
	 * @param \GdImage|\Imagick $image The source image.
	 * @param int $width The target width in pixels.
	 * @param int $height The target height in pixels.
	 * @return false|\GdImage|\Imagick The resampled image, or false on failure.
	 */
	public static function resampled(\GdImage|\Imagick $image, int $width, int $height): false|\GdImage|\Imagick
	{
		return static::getLibraryOf($image)->resampled($image, $width, $height);
	}

	/**
	 * Reduces an image to black and white, one byte per pixel row-major: "\x00" for a
	 * black pixel and "\x01" for a white pixel.
	 * @param \GdImage|\Imagick $image The source image.
	 * @param bool $dither Whether to dither the reduction. Default true.
	 * @return false|string The per-pixel bit bytes, or false on failure.
	 */
	public static function monoPixels(\GdImage|\Imagick $image, bool $dither = true): false|string
	{
		return static::getLibraryOf($image)->monoPixels($image, $dither);
	}

	/**
	 * Quantizes an image to at most 256 colors, returning the 768-byte RGB palette and
	 * one palette-index byte per pixel, row-major.
	 * @param \GdImage|\Imagick $image The source image.
	 * @return array The [palette, pixels] pair.
	 */
	public static function paletteQuantize(\GdImage|\Imagick $image): array
	{
		return static::getLibraryOf($image)->paletteQuantize($image);
	}
}
