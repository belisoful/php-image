<?php

/**
 * PhotoshopResourceNames class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image;

/**
 * PhotoshopResourceNames class.
 *
 * The Photoshop image-resource id vocabulary: {@see nameOf()} and {@see describe()}
 * answer the name and description of an 8BIM resource id, including the
 * 0x07D0-0x0BB6 path-information range.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class PhotoshopResourceNames
{
	/** The first id of the path-information range. */
	public const PathInfoFirst = 0x07D0;

	/** The last id of the path-information range. */
	public const PathInfoLast = 0x0BB6;

	/** @var array<int, array{name: string, description: string}> The resource id vocabulary. */
	public const Names = [
		0x03E8 => [
			'name' => 'Number of channels, rows, columns, depth, and mode. (Obsolete)',
			'description' => 'Obsolete - Photoshop 2.0 only. Number of channels, rows, columns, depth, and mode.',
		],
		0x03E9 => [
			'name' => 'Macintosh print manager info',
			'description' => 'Optional. Macintosh print manager print info record.',
		],
		0x03EB => [
			'name' => 'Indexed color table (Obsolete)',
			'description' => 'Obsolete - Photoshop 2.0 only. Contains the indexed color table.',
		],
		0x03ED => [
			'name' => 'Resolution Info',
			'description' => 'ResolutionInfo structure. See Appendix A in Photoshop SDK Guide.pdf',
		],
		0x03EE => [
			'name' => 'Alpha Channel Names',
			'description' => 'Names of the alpha channels as a series of Pascal strings.',
		],
		0x03EF => [
			'name' => 'Display Info',
			'description' => 'DisplayInfo structure. See Appendix A in Photoshop SDK Guide.pdf',
		],
		0x03F0 => [
			'name' => 'Caption String',
			'description' => 'Optional. The caption as a Pascal string.',
		],
		0x03F1 => [
			'name' => 'Border information',
			'description' => 'Border information. border width, border units',
		],
		0x03F2 => [
			'name' => 'Background color',
			'description' => 'Background color.',
		],
		0x03F3 => [
			'name' => 'Print flags',
			'description' => 'Print flags. labels, crop marks, color bars, registration marks, negative, flip, interpolate, caption.',
		],
		0x03F4 => [
			'name' => 'Grayscale and multichannel halftoning information',
			'description' => 'Grayscale and multichannel halftoning information.',
		],
		0x03F5 => [
			'name' => 'Color halftoning information',
			'description' => 'Color halftoning information.',
		],
		0x03F6 => [
			'name' => 'Duotone halftoning information',
			'description' => 'Duotone halftoning information.',
		],
		0x03F7 => [
			'name' => 'Grayscale and multichannel transfer function',
			'description' => 'Grayscale and multichannel transfer function.',
		],
		0x03F8 => [
			'name' => 'Color transfer functions',
			'description' => 'Color transfer functions.',
		],
		0x03F9 => [
			'name' => 'Duotone transfer functions',
			'description' => 'Duotone transfer functions.',
		],
		0x03FA => [
			'name' => 'Duotone image information',
			'description' => 'Duotone image information.',
		],
		0x03FB => [
			'name' => 'Black and white values',
			'description' => 'Effective black and white values for the dot range.',
		],
		0x03FC => [
			'name' => 'Obsolete Resource.',
			'description' => 'Obsolete Resource.',
		],
		0x03FD => [
			'name' => 'EPS options',
			'description' => 'EPS options.',
		],
		0x03FE => [
			'name' => 'Quick Mask information',
			'description' => 'Quick Mask information. Quick Mask channel ID, Mask initially empty.',
		],
		0x03FF => [
			'name' => 'Obsolete Resource',
			'description' => 'Obsolete Resource.',
		],
		0x0400 => [
			'name' => 'Layer state information',
			'description' => 'Layer state information. Index of target layer.',
		],
		0x0401 => [
			'name' => 'Working path (not saved)',
			'description' => 'Working path (not saved).',
		],
		0x0402 => [
			'name' => 'Layers group information',
			'description' => 'Layers group information. Group ID for the dragging groups. Layers in a group have the same group ID.',
		],
		0x0403 => [
			'name' => 'Obsolete Resource',
			'description' => 'Obsolete Resource.',
		],
		0x0404 => [
			'name' => 'IPTC-NAA record',
			'description' => 'IPTC-NAA record. This contains the File Info... information. See the IIMV4.pdf document.',
		],
		0x0405 => [
			'name' => 'Raw Format Image mode',
			'description' => 'Image mode for raw format files.',
		],
		0x0406 => [
			'name' => 'JPEG quality',
			'description' => 'JPEG quality. Private.',
		],
		0x0408 => [
			'name' => 'Grid and guides information',
			'description' => 'Grid and guides information.',
		],
		0x0409 => [
			'name' => 'Thumbnail resource',
			'description' => 'Thumbnail resource.',
		],
		0x040A => [
			'name' => 'Copyright flag',
			'description' => 'Copyright flag. Boolean indicating whether image is copyrighted. Can be set via Property suite or by user in File Info...',
		],
		0x040B => [
			'name' => 'URL',
			'description' => 'URL. Handle of a text string with uniform resource locator. Can be set via Property suite or by user in File Info...',
		],
		0x040C => [
			'name' => 'Thumbnail resource',
			'description' => 'Thumbnail resource.',
		],
		0x040D => [
			'name' => 'Global Angle',
			'description' => 'Global Angle. Global lighting angle for effects layer.',
		],
		0x040E => [
			'name' => 'Color samplers resource',
			'description' => 'Color samplers resource.',
		],
		0x040F => [
			'name' => 'ICC Profile',
			'description' => 'ICC Profile. The raw bytes of an ICC format profile, see the ICC34.pdf and ICC34.h files from the International Color Consortium.',
		],
		0x0410 => [
			'name' => 'Watermark',
			'description' => 'Watermark.',
		],
		0x0411 => [
			'name' => 'ICC Untagged',
			'description' => 'ICC Untagged. Disables any assumed profile handling when opening the file. 1 = intentionally untagged.',
		],
		0x0412 => [
			'name' => 'Effects visible',
			'description' => 'Effects visible. Show/hide all the effects layer.',
		],
		0x0413 => [
			'name' => 'Spot Halftone',
			'description' => 'Spot Halftone. Version, length, variable length data.',
		],
		0x0414 => [
			'name' => 'Document Specific IDs',
			'description' => 'Document specific IDs for layer identification',
		],
		0x0415 => [
			'name' => 'Unicode Alpha Names',
			'description' => 'Unicode Alpha Names. Length and the string',
		],
		0x0416 => [
			'name' => 'Indexed Color Table Count',
			'description' => 'Indexed Color Table Count. Number of colors in table that are actually defined',
		],
		0x0417 => [
			'name' => 'Transparent Index. Index of transparent color, if any.',
			'description' => 'Transparent Index. Index of transparent color, if any.',
		],
		0x0419 => [
			'name' => 'Global Altitude',
			'description' => 'Global Altitude.',
		],
		0x041A => [
			'name' => 'Slices',
			'description' => 'Slices.',
		],
		0x041B => [
			'name' => 'Workflow URL',
			'description' => 'Workflow URL. Length, string.',
		],
		0x041C => [
			'name' => 'Jump To XPEP',
			'description' => 'Jump To XPEP. Major version, Minor version, Count. Table which can include: Dirty flag, Mod date.',
		],
		0x041D => [
			'name' => 'Alpha Identifiers',
			'description' => 'Alpha Identifiers.',
		],
		0x041E => [
			'name' => 'URL List',
			'description' => 'URL List. Count of URLs, IDs, and strings',
		],
		0x0421 => [
			'name' => 'Version Info',
			'description' => 'Version Info. Version, HasRealMergedData, string of writer name, string of reader name, file version.',
		],
		0x0BB7 => [
			'name' => 'Name of clipping path.',
			'description' => 'Name of clipping path.',
		],
		0x2710 => [
			'name' => 'Print flags information',
			'description' => 'Print flags information. Version, Center crop marks, Bleed width value, Bleed width scale.',
		],
	];

	/**
	 * Returns the name of a resource id.
	 * @param int $id The resource id.
	 * @return ?string The name, or null when unknown.
	 */
	public static function nameOf(int $id): ?string
	{
		if ($id >= self::PathInfoFirst && $id <= self::PathInfoLast) {
			return 'Path Information';
		}
		return self::Names[$id]['name'] ?? null;
	}

	/**
	 * Returns the description of a resource id.
	 * @param int $id The resource id.
	 * @return ?string The description, or null when unknown.
	 */
	public static function describe(int $id): ?string
	{
		if ($id >= self::PathInfoFirst && $id <= self::PathInfoLast) {
			return 'Saved working path information';
		}
		return self::Names[$id]['description'] ?? null;
	}
}
