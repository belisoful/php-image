<?php

/**
 * PNGChunkType class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\PNG;

/**
 * PNGChunkType class.
 *
 * The four-character type codes of the PNG chunks this library knows by name, as a single
 * vocabulary in place of scattered string literals.  The values are the on-disk codes, so
 * a constant is interchangeable with the raw string a {@see \Belisoful\Image\ImageChunk}
 * carries.
 *
 * This is a **vocabulary of the known codes, not a closed type**: a PNG may carry private
 * or newer chunks this list does not name, and {@see \Belisoful\Image\PNGImage} preserves them
 * byte-faithfully by keeping {@see \Belisoful\Image\ImageChunk::getType()} a raw string.
 * These constants name the ones the writer orders and the reader decodes.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @see https://www.w3.org/TR/png-3/
 */
class PNGChunkType
{
	// Critical chunks.
	public const Header = 'IHDR';
	public const Palette = 'PLTE';
	public const ImageData = 'IDAT';
	public const End = 'IEND';

	// Colour-space and rendering ancillary chunks.
	public const Chromaticities = 'cHRM';
	public const Gamma = 'gAMA';
	public const ICCProfile = 'iCCP';
	public const SignificantBits = 'sBIT';
	public const StandardRgb = 'sRGB';

	// PNG Third Edition colour-volume chunks (placed before the palette).
	public const CodingIndependentCodePoints = 'cICP';
	public const MasteringDisplayColorVolume = 'mDCv';
	public const ContentLightLevel = 'cLLi';

	// Palette-dependent and image ancillary chunks.
	public const BackgroundColor = 'bKGD';
	public const Histogram = 'hIST';
	public const Transparency = 'tRNS';
	public const PhysicalDimensions = 'pHYs';
	public const SuggestedPalette = 'sPLT';
	public const ModificationTime = 'tIME';

	// Textual chunks.
	public const Text = 'tEXt';
	public const CompressedText = 'zTXt';
	public const InternationalText = 'iTXt';

	// EXIF metadata.
	public const Exif = 'eXIf';

	// Animated PNG (APNG) chunks.
	public const AnimationControl = 'acTL';
	public const FrameControl = 'fcTL';
	public const FrameData = 'fdAT';
}
