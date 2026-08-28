<?php

/**
 * EXIFTags class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\Meta;

use Belisoful\Image\TIFF\TIFFDataType;
use Belisoful\Image\TIFF\TIFFTag;

/**
 * EXIFTags class.
 *
 * The tag knowledge base for the EXIF family of IFDs: the TIFF (IFD0/IFD1), EXIF, GPS,
 * and Interoperability groups, plus the Kodak Meta (APP3) groups.  For each tag it
 * knows the name, the interpretation type (numeric, string, lookup, special decoder,
 * sub-IFD or embedded-metadata pointer), the units, and the value-to-meaning table of
 * enumerated tags — the vocabulary the EXIF 2.2, TIFF 6.0, and GPS specifications
 * define.
 *
 * {@see definition()}/{@see nameOf()} query a group; {@see findByName()} resolves a
 * tag name back to its group and id; {@see textValue()} renders a {@see TIFFTag}
 * human-readably — enumerations through their lookup tables, rationals simplified with
 * units, GPS coordinates as degrees-minutes-seconds, and the special encodings
 * (components configuration, YCbCr subsampling, CFA pattern, user comments) through
 * dedicated decoders.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class EXIFTags
{
	/** The TIFF (IFD0/IFD1) tag group. */
	public const TIFF = 'TIFF';

	/** The EXIF sub-IFD tag group. */
	public const EXIF = 'EXIF';

	/** The Interoperability sub-IFD tag group. */
	public const Interoperability = 'Interoperability';

	/** The GPS sub-IFD tag group. */
	public const GPS = 'GPS';

	/** The Kodak Meta (APP3) tag group. */
	public const Meta = 'Meta';

	/** The Kodak Special Effects sub-IFD tag group. */
	public const KodakSpecialEffects = 'KodakSpecialEffects';

	/** The Kodak Borders sub-IFD tag group. */
	public const KodakBorders = 'KodakBorders';

	/**
	 * @var array<int, string> The `LearningOptOutIn` usage values (Exif 3.1): what the
	 *   copyright holder's intention applies to.
	 */
	public const LearningUsages = [
		0 => 'All / Individual usage is not specified',
		1 => 'Non-Generative AI/ML Training',
		2 => 'Generative AI/ML Training',
		3 => 'Data Mining',
		4 => 'Input to Foundation Model (Trained AI/ML Model)',
	];

	/** @var array<int, string> The `LearningOptOutIn` indication-of-intention values. */
	public const LearningIntentions = [
		0 => 'Opt-out',
		1 => 'Opt-in',
		2 => 'Unspecified',
	];

	/**
	 * @var array The tag definitions: group => tag id => definition.  A definition has
	 *   'name' and 'type' ('Numeric', 'String', 'CodedString', 'Lookup', 'Special',
	 *   'SubIFD', 'MakerNote', 'PIM', 'IPTC', 'XMP', 'IRB', 'Unknown'), and optionally
	 *   'units', 'lookup' (value => meaning), 'special' (decoder id), and 'subIfd'.
	 */
	public const Definitions = [
		'TIFF' => [
			256 => [
				'name' => 'ImageWidth',
				'type' => 'Numeric',
				'units' => 'pixels',
			],
			257 => [
				'name' => 'ImageLength',
				'type' => 'Numeric',
				'units' => 'pixels',
			],
			258 => [
				'name' => 'BitsPerSample',
				'type' => 'Numeric',
				'units' => 'bits ( for each colour component )',
			],
			259 => [
				'name' => 'Compression',
				'type' => 'Lookup',
				'lookup' => [
					1 => 'Uncompressed',
					5 => 'LZW Compression',
					6 => 'Thumbnail compressed with JPEG compression',
					7 => 'JPEG Compression',
					8 => 'ZIP Compression',
				],
			],
			262 => [
				'name' => 'PhotometricInterpretation',
				'type' => 'Lookup',
				'lookup' => [
					2 => 'RGB (Red Green Blue)',
					6 => 'YCbCr (Luminance, Chroma minus Blue, and Chroma minus Red)',
				],
			],
			274 => [
				'name' => 'Orientation',
				'type' => 'Lookup',
				'lookup' => [
					1 => 'No Rotation, No Flip 
(Row 0 is at the visual top of the image,
 and column 0 is the visual left-hand side)',
					2 => 'No Rotation, Flipped Horizontally 
(Row 0 is at the visual top of the image,
 and column 0 is the visual right-hand side)',
					3 => 'Rotated 180 degrees, No Flip 
(Row 0 is at the visual bottom of the image,
 and column 0 is the visual right-hand side)',
					4 => 'No Rotation, Flipped Vertically 
(Row 0 is at the visual bottom of the image,
 and column 0 is the visual left-hand side)',
					5 => 'Flipped Horizontally, Rotated 90 degrees counter clockwise 
(Row 0 is at the visual left-hand side of of the image,
 and column 0 is the visual top)',
					6 => 'No Flip, Rotated 90 degrees clockwise 
(Row 0 is at the visual right-hand side of of the image,
 and column 0 is the visual top)',
					7 => 'Flipped Horizontally, Rotated 90 degrees clockwise 
(Row 0 is at the visual right-hand side of of the image,
 and column 0 is the visual bottom)',
					8 => 'No Flip, Rotated 90 degrees counter clockwise 
(Row 0 is at the visual left-hand side of of the image,
 and column 0 is the visual bottom)',
				],
			],
			277 => [
				'name' => 'SamplesPerPixel',
				'type' => 'Numeric',
				'units' => 'Components (colours)',
			],
			284 => [
				'name' => 'PlanarConfiguration',
				'type' => 'Lookup',
				'lookup' => [
					1 => 'Chunky Format',
					2 => 'Planar Format',
				],
			],
			530 => [
				'name' => 'YCbCrSubSampling',
				'type' => 'Special',
				'special' => 'YCbCrSubSampling',
			],
			531 => [
				'name' => 'YCbCrPositioning',
				'type' => 'Lookup',
				'lookup' => [
					1 => 'Chrominance components Centred in relation to luminance components',
					2 => 'Chrominance and luminance components Co-Sited',
				],
			],
			282 => [
				'name' => 'XResolution',
				'type' => 'Numeric',
				'units' => 'pixels per \'Resolution Unit\'',
			],
			283 => [
				'name' => 'YResolution',
				'type' => 'Numeric',
				'units' => 'pixels per \'Resolution Unit\'',
			],
			296 => [
				'name' => 'ResolutionUnit',
				'type' => 'Lookup',
				'lookup' => [
					2 => 'Inches',
					3 => 'Centimetres',
				],
			],
			273 => [
				'name' => 'StripOffsets',
				'type' => 'Numeric',
				'units' => 'bytes offset',
			],
			278 => [
				'name' => 'RowsPerStrip',
				'type' => 'Numeric',
				'units' => 'rows',
			],
			279 => [
				'name' => 'StripByteCounts',
				'type' => 'Numeric',
				'units' => 'bytes',
			],
			513 => [
				'name' => 'JPEGInterchangeFormat',
				'type' => 'Special',
				'special' => 'JPEGInterchangeFormat',
			],
			514 => [
				'name' => 'JPEGInterchangeFormatLength',
				'type' => 'Numeric',
				'units' => 'bytes',
			],
			301 => [
				'name' => 'TransferFunction',
				'type' => 'Numeric',
			],
			318 => [
				'name' => 'WhitePoint',
				'type' => 'Numeric',
				'units' => '(x,y coordinates on a 1931 CIE xy chromaticity diagram)',
			],
			319 => [
				'name' => 'PrimaryChromaticities',
				'type' => 'Numeric',
				'units' => '(Red x,y, Green x,y, Blue x,y coordinates on a 1931 CIE xy chromaticity diagram)',
			],
			529 => [
				'name' => 'YCbCrCoefficients',
				'type' => 'Numeric',
				'units' => '(LumaRed, LumaGreen, LumaBlue [proportions of red, green, and blue in luminance])',
			],
			532 => [
				'name' => 'ReferenceBlackWhite',
				'type' => 'Numeric',
				'units' => '(R or Y White Headroom, R or Y Black Footroom, G or Cb White Headroom, G or Cb Black Footroom, B or Cr White Headroom, B or Cr Black Footroom)',
			],
			306 => [
				'name' => 'DateTime',
				'type' => 'Numeric',
				'units' => '(Format: YYYY:MM:DD HH:mm:SS)',
			],
			270 => [
				'name' => 'ImageDescription',
				'type' => 'String',
			],
			271 => [
				'name' => 'Make',
				'type' => 'String',
			],
			272 => [
				'name' => 'Model',
				'type' => 'String',
			],
			305 => [
				'name' => 'Software',
				'type' => 'String',
			],
			315 => [
				'name' => 'Artist',
				'type' => 'String',
			],
			700 => [
				'name' => 'XMP',
				'type' => 'XMP',
			],
			33432 => [
				'name' => 'Copyright',
				'type' => 'String',
			],
			34665 => [
				'name' => 'ExifIFD',
				'type' => 'SubIFD',
				'subIfd' => 'EXIF',
			],
			33723 => [
				'name' => 'IPTC',
				'type' => 'IPTC',
			],
			34377 => [
				'name' => 'PhotoshopIRB',
				'type' => 'IRB',
			],
			34853 => [
				'name' => 'GPSInfoIFD',
				'type' => 'SubIFD',
				'subIfd' => 'GPS',
			],
			50341 => [
				'name' => 'PrintImageMatching',
				'type' => 'PIM',
			],
			// TIFF 6.0 document/host fields and the Windows Explorer "XP" fields — all
			// free text a user or machine writes, so all identifying.
			269 => [
				'name' => 'DocumentName',
				'type' => 'String',
			],
			285 => [
				'name' => 'PageName',
				'type' => 'String',
			],
			316 => [
				'name' => 'HostComputer',
				'type' => 'String',
			],
			40091 => [
				'name' => 'XPTitle',
				'type' => 'Unknown',   // UCS-2LE text stored as BYTE
			],
			40092 => [
				'name' => 'XPComment',
				'type' => 'Unknown',
			],
			40093 => [
				'name' => 'XPAuthor',
				'type' => 'Unknown',
			],
			40094 => [
				'name' => 'XPKeywords',
				'type' => 'Unknown',
			],
			40095 => [
				'name' => 'XPSubject',
				'type' => 'Unknown',
			],
		],
		'EXIF' => [
			36864 => [
				'name' => 'ExifVersion',
				'type' => 'String',
			],
			40965 => [
				'name' => 'InteroperabilityIFD',
				'type' => 'SubIFD',
				'subIfd' => 'Interoperability',
			],
			40960 => [
				'name' => 'FlashpixVersion',
				'type' => 'String',
			],
			40961 => [
				'name' => 'ColorSpace',
				'type' => 'Lookup',
				'lookup' => [
					1 => 'sRGB',
					65535 => 'Uncalibrated',
				],
			],
			40962 => [
				'name' => 'PixelXDimension',
				'type' => 'Numeric',
				'units' => 'pixels',
			],
			40963 => [
				'name' => 'PixelYDimension',
				'type' => 'Numeric',
				'units' => 'pixels',
			],
			37121 => [
				'name' => 'ComponentsConfiguration',
				'type' => 'Special',
				'special' => 'ComponentsConfiguration',
			],
			37122 => [
				'name' => 'CompressedBitsPerPixel',
				'type' => 'Numeric',
				'units' => 'bits',
			],
			37500 => [
				'name' => 'MakerNote',
				'type' => 'MakerNote',
			],
			37510 => [
				'name' => 'UserComment',
				'type' => 'CodedString',
			],
			40964 => [
				'name' => 'RelatedSoundFile',
				'type' => 'String',
			],
			36867 => [
				'name' => 'DateTimeOriginal',
				'type' => 'String',
				'units' => '(Format: YYYY:MM:DD HH:mm:SS)',
			],
			36868 => [
				'name' => 'DateTimeDigitized',
				'type' => 'String',
				'units' => '(Format: YYYY:MM:DD HH:mm:SS)',
			],
			36880 => [
				'name' => 'OffsetTime',
				'type' => 'String',
				'units' => '(Format: +HH:MM or -HH:MM)',
			],
			36881 => [
				'name' => 'OffsetTimeOriginal',
				'type' => 'String',
				'units' => '(Format: +HH:MM or -HH:MM)',
			],
			36882 => [
				'name' => 'OffsetTimeDigitized',
				'type' => 'String',
				'units' => '(Format: +HH:MM or -HH:MM)',
			],
			37520 => [
				'name' => 'SubSecTime',
				'type' => 'String',
			],
			37521 => [
				'name' => 'SubSecTimeOriginal',
				'type' => 'String',
			],
			37522 => [
				'name' => 'SubSecTimeDigitized',
				'type' => 'String',
			],
			33434 => [
				'name' => 'ExposureTime',
				'type' => 'Numeric',
				'units' => 'seconds',
			],
			37377 => [
				'name' => 'ShutterSpeedValue',
				'type' => 'Numeric',
			],
			37378 => [
				'name' => 'ApertureValue',
				'type' => 'Numeric',
			],
			37379 => [
				'name' => 'BrightnessValue',
				'type' => 'Numeric',
			],
			37380 => [
				'name' => 'ExposureBiasValue',
				'type' => 'Numeric',
				'units' => 'EV',
			],
			42240 => [
				'name' => 'Gamma',
				'type' => 'Numeric',
			],
			37888 => [
				'name' => 'Temperature',
				'type' => 'Numeric',
				'units' => 'degrees Celsius',
			],
			37889 => [
				'name' => 'Humidity',
				'type' => 'Numeric',
				'units' => 'percent relative humidity',
			],
			37890 => [
				'name' => 'Pressure',
				'type' => 'Numeric',
				'units' => 'hPa',
			],
			37891 => [
				'name' => 'WaterDepth',
				'type' => 'Numeric',
				'units' => 'metres',
			],
			37892 => [
				'name' => 'Acceleration',
				'type' => 'Numeric',
				'units' => 'mGal',
			],
			37893 => [
				'name' => 'CameraElevationAngle',
				'type' => 'Numeric',
				'units' => 'degrees',
			],
			42032 => [
				'name' => 'CameraOwnerName',
				'type' => 'String',
			],
			42033 => [
				'name' => 'BodySerialNumber',
				'type' => 'String',
			],
			42034 => [
				'name' => 'LensSpecification',
				'type' => 'Numeric',
				'units' => '(min focal length, max focal length, min F-number at min focal length, min F-number at max focal length)',
			],
			42035 => [
				'name' => 'LensMake',
				'type' => 'String',
			],
			42036 => [
				'name' => 'LensModel',
				'type' => 'String',
			],
			42037 => [
				'name' => 'LensSerialNumber',
				'type' => 'String',
			],
			42080 => [
				'name' => 'CompositeImage',
				'type' => 'Lookup',
				'lookup' => [
					0 => 'Unknown',
					1 => 'Not a composite image',
					2 => 'General composite image',
					3 => 'Composite image captured while shooting',
				],
			],
			42081 => [
				'name' => 'SourceImageNumberOfCompositeImage',
				'type' => 'Numeric',
				'units' => '(images used, images actually used)',
			],
			42082 => [
				'name' => 'SourceExposureTimesOfCompositeImage',
				'type' => 'Unknown',
			],
			42038 => [
				'name' => 'Title',
				'type' => 'String',
			],
			42039 => [
				'name' => 'Photographer',
				'type' => 'String',
			],
			42040 => [
				'name' => 'ImageEditor',
				'type' => 'String',
			],
			42041 => [
				'name' => 'CameraFirmware',
				'type' => 'String',
			],
			42042 => [
				'name' => 'RAWDevelopingSoftware',
				'type' => 'String',
			],
			42043 => [
				'name' => 'ImageEditingSoftware',
				'type' => 'String',
			],
			42044 => [
				'name' => 'MetadataEditingSoftware',
				'type' => 'String',
			],
			37511 => [
				'name' => 'LearningOptOutIn',
				'type' => 'Special',
				'special' => 'LearningOptOutIn',
			],
			41997 => [
				'name' => 'DevelopmentType',
				'type' => 'Special',
				'special' => 'DevelopmentType',
			],
			41998 => [
				'name' => 'DevelopmentTypeDescription',
				'type' => 'String',
			],
			41999 => [
				'name' => 'DistortionCorrection',
				'type' => 'Lookup',
				'lookup' => [
					0 => 'Not applied',
					1 => 'Applied',
				],
			],
			42000 => [
				'name' => 'ChromaticAberrationCorrection',
				'type' => 'Lookup',
				'lookup' => [
					0 => 'Not applied',
					1 => 'Applied',
				],
			],
			42001 => [
				'name' => 'ShadingCorrection',
				'type' => 'Lookup',
				'lookup' => [
					0 => 'Not applied',
					1 => 'Applied',
				],
			],
			42002 => [
				'name' => 'NoiseReduction',
				'type' => 'Lookup',
				'lookup' => [
					0 => 'Not applied',
					1 => 'Low strength noise reduction',
					2 => 'Normal strength noise reduction',
					3 => 'High strength noise reduction',
				],
			],
			37381 => [
				'name' => 'MaxApertureValue',
				'type' => 'Numeric',
			],
			37382 => [
				'name' => 'SubjectDistance',
				'type' => 'Numeric',
				'units' => 'metres',
			],
			37383 => [
				'name' => 'MeteringMode',
				'type' => 'Lookup',
				'lookup' => [
					0 => 'Unknown',
					1 => 'Average',
					2 => 'Center Weighted Average',
					3 => 'Spot',
					4 => 'Multi Spot',
					5 => 'Pattern',
					6 => 'Partial',
					255 => 'Other',
				],
			],
			37384 => [
				'name' => 'LightSource',
				'type' => 'Lookup',
				'lookup' => [
					0 => 'Unknown',
					1 => 'Daylight',
					2 => 'Fluorescent',
					3 => 'Tungsten (incandescent light)',
					4 => 'Flash',
					9 => 'Fine weather',
					10 => 'Cloudy weather',
					11 => 'Shade',
					12 => 'Daylight fluorescent (D 5700 - 7100K)',
					13 => 'Day white fluorescent (N 4600 - 5400K)',
					14 => 'Cool white fluorescent (W 3900 - 4500K)',
					15 => 'White fluorescent (WW 3200 - 3700K)',
					17 => 'Standard light A',
					18 => 'Standard light B',
					19 => 'Standard light C',
					16 => 'Warm white fluorescent (L 2600 - 3250K)',
					20 => 'D55',
					21 => 'D65',
					22 => 'D75',
					23 => 'D50',
					24 => 'ISO studio tungsten',
					25 => 'Daylight light source (D 5700 - 7100K)',
					26 => 'Day white light source (N 4600 - 5500K)',
					27 => 'Cool white light source (W 3800 - 4500K)',
					28 => 'White light source (WW 3250 - 3800K)',
					29 => 'Warm white light source (L 2600 - 3250K)',
					30 => 'Daylight LED (D 5700 - 7100K)',
					31 => 'Day white LED (N 4600 - 5500K)',
					32 => 'Cool white LED (W 3800 - 4500K)',
					33 => 'White LED (WW 3250 - 3800K)',
					34 => 'Warm white LED (L 2600 - 3250K)',
					255 => 'Other',
				],
			],
			37385 => [
				'name' => 'Flash',
				'type' => 'Lookup',
				'lookup' => [
					0 => 'Flash did not fire',
					1 => 'Flash fired',
					5 => 'Strobe return light not detected',
					7 => 'Strobe return light detected',
					9 => 'Flash fired, compulsory flash mode',
					13 => 'Flash fired, compulsory flash mode, return light not detected',
					15 => 'Flash fired, compulsory flash mode, return light detected',
					16 => 'Flash did not fire, compulsory flash suppression mode',
					24 => 'Flash did not fire, auto mode',
					25 => 'Flash fired, auto mode',
					29 => 'Flash fired, auto mode, return light not detected',
					31 => 'Flash fired, auto mode, return light detected',
					32 => 'No flash function',
					65 => 'Flash fired, red-eye reduction mode',
					69 => 'Flash fired, red-eye reduction mode, return light not detected',
					71 => 'Flash fired, red-eye reduction mode, return light detected',
					73 => 'Flash fired, compulsory flash mode, red-eye reduction mode',
					77 => 'Flash fired, compulsory flash mode, red-eye reduction mode, return light not detected',
					79 => 'Flash fired, compulsory flash mode, red-eye reduction mode, return light detected',
					89 => 'Flash fired, auto mode, red-eye reduction mode',
					93 => 'Flash fired, auto mode, return light not detected, red-eye reduction mode',
					95 => 'Flash fired, auto mode, return light detected, red-eye reduction mode',
				],
			],
			37386 => [
				'name' => 'FocalLength',
				'type' => 'Numeric',
				'units' => 'mm',
			],
			37396 => [
				'name' => 'SubjectArea',
				'type' => 'Numeric',
				'units' => '( Two Values: x,y coordinates,  Three Values: x,y coordinates, diameter,  Four Values: center x,y coordinates, width, height)',
			],
			33437 => [
				'name' => 'FNumber',
				'type' => 'Numeric',
			],
			34850 => [
				'name' => 'ExposureProgram',
				'type' => 'Lookup',
				'lookup' => [
					0 => 'Not defined',
					1 => 'Manual',
					2 => 'Normal program',
					3 => 'Aperture priority',
					4 => 'Shutter priority',
					5 => 'Creative program (biased toward depth of field)',
					6 => 'Action program (biased toward fast shutter speed)',
					7 => 'Portrait mode (for closeup photos with the background out of focus)',
					8 => 'Landscape mode (for landscape photos with the background in focus)',
				],
			],
			34852 => [
				'name' => 'SpectralSensitivity',
				'type' => 'String',
			],
			34855 => [
				'name' => 'PhotographicSensitivity',
				'type' => 'Numeric',
				'aliases' => ['ISOSpeedRatings'],
			],
			34864 => [
				'name' => 'SensitivityType',
				'type' => 'Lookup',
				'lookup' => [
					0 => 'Unknown',
					1 => 'Standard output sensitivity (SOS)',
					2 => 'Recommended exposure index (REI)',
					3 => 'ISO speed',
					4 => 'Standard output sensitivity (SOS) and recommended exposure index (REI)',
					5 => 'Standard output sensitivity (SOS) and ISO speed',
					6 => 'Recommended exposure index (REI) and ISO speed',
					7 => 'Standard output sensitivity (SOS) and recommended exposure index (REI) and ISO speed',
				],
			],
			34865 => [
				'name' => 'StandardOutputSensitivity',
				'type' => 'Numeric',
			],
			34866 => [
				'name' => 'RecommendedExposureIndex',
				'type' => 'Numeric',
			],
			34867 => [
				'name' => 'ISOSpeed',
				'type' => 'Numeric',
			],
			34868 => [
				'name' => 'ISOSpeedLatitudeyyy',
				'type' => 'Numeric',
			],
			34869 => [
				'name' => 'ISOSpeedLatitudezzz',
				'type' => 'Numeric',
			],
			34856 => [
				'name' => 'OECF',
				'type' => 'Unknown',
			],
			41483 => [
				'name' => 'FlashEnergy',
				'type' => 'Numeric',
				'units' => 'Beam Candle Power Seconds (BCPS)',
			],
			41484 => [
				'name' => 'SpatialFrequencyResponse',
				'type' => 'Unknown',
			],
			41486 => [
				'name' => 'FocalPlaneXResolution',
				'type' => 'Numeric',
				'units' => 'pixels per \'Focal Plane Resolution Unit\'',
			],
			41487 => [
				'name' => 'FocalPlaneYResolution',
				'type' => 'Numeric',
				'units' => 'pixels per \'Focal Plane Resolution Unit\'',
			],
			41488 => [
				'name' => 'FocalPlaneResolutionUnit',
				'type' => 'Lookup',
				'lookup' => [
					2 => 'Inches',
					3 => 'Centimetres',
				],
			],
			41492 => [
				'name' => 'SubjectLocation',
				'type' => 'Numeric',
				'units' => '(x,y pixel coordinates of subject)',
			],
			41493 => [
				'name' => 'ExposureIndex',
				'type' => 'Numeric',
			],
			41495 => [
				'name' => 'SensingMethod',
				'type' => 'Lookup',
				'lookup' => [
					1 => 'Not defined',
					2 => 'One-chip colour area sensor',
					3 => 'Two-chip colour area sensor',
					4 => 'Three-chip colour area sensor',
					5 => 'Colour sequential area sensor',
					7 => 'Trilinear sensor',
					8 => 'Colour sequential linear sensor',
				],
			],
			41728 => [
				'name' => 'FileSource',
				'type' => 'Lookup',
				'lookup' => [
					3 => 'Digital Still Camera',
				],
			],
			41729 => [
				'name' => 'SceneType',
				'type' => 'Lookup',
				'lookup' => [
					1 => 'A directly photographed image',
				],
			],
			41730 => [
				'name' => 'CFAPattern',
				'type' => 'Special',
				'special' => 'CFAPattern',
			],
			41985 => [
				'name' => 'CustomRendered',
				'type' => 'Lookup',
				'lookup' => [
					0 => 'Normal process',
					1 => 'Custom process',
				],
			],
			41986 => [
				'name' => 'ExposureMode',
				'type' => 'Lookup',
				'lookup' => [
					0 => 'Auto exposure',
					1 => 'Manual exposure',
					2 => 'Auto bracket',
				],
			],
			41987 => [
				'name' => 'WhiteBalance',
				'type' => 'Lookup',
				'lookup' => [
					0 => 'Auto white balance',
					1 => 'Manual white balance',
				],
			],
			41988 => [
				'name' => 'DigitalZoomRatio',
				'type' => 'Numeric',
				'units' => '( Zero = Digital Zoom Not Used )',
			],
			41989 => [
				'name' => 'FocalLengthIn35mmFilm',
				'type' => 'Numeric',
				'units' => 'mm',
			],
			41990 => [
				'name' => 'SceneCaptureType',
				'type' => 'Lookup',
				'lookup' => [
					0 => 'Standard',
					1 => 'Landscape',
					2 => 'Portrait',
					3 => 'Night scene',
				],
			],
			41991 => [
				'name' => 'GainControl',
				'type' => 'Lookup',
				'lookup' => [
					0 => 'None',
					1 => 'Low gain up',
					2 => 'High gain up',
					3 => 'Low gain down',
					4 => 'High gain down',
				],
			],
			41992 => [
				'name' => 'Contrast',
				'type' => 'Lookup',
				'lookup' => [
					0 => 'Normal',
					1 => 'Soft',
					2 => 'Hard',
				],
			],
			41993 => [
				'name' => 'Saturation',
				'type' => 'Lookup',
				'lookup' => [
					0 => 'Normal',
					1 => 'Low saturation',
					2 => 'High saturation',
				],
			],
			41994 => [
				'name' => 'Sharpness',
				'type' => 'Lookup',
				'lookup' => [
					0 => 'Normal',
					1 => 'Soft',
					2 => 'Hard',
				],
			],
			41995 => [
				'name' => 'DeviceSettingDescription',
				'type' => 'Unknown',
			],
			41996 => [
				'name' => 'SubjectDistanceRange',
				'type' => 'Lookup',
				'lookup' => [
					0 => 'Unknown',
					1 => 'Macro',
					2 => 'Close view',
					3 => 'Distant view',
				],
			],
			42016 => [
				'name' => 'ImageUniqueID',
				'type' => 'String',
			],
		],
		'Interoperability' => [
			1 => [
				'name' => 'InteroperabilityIndex',
				'type' => 'String',
			],
			2 => [
				'name' => 'InteroperabilityVersion',
				'type' => 'String',
			],
			4096 => [
				'name' => 'RelatedImageFileFormat',
				'type' => 'String',
			],
			4097 => [
				'name' => 'RelatedImageWidth',
				'type' => 'Numeric',
				'units' => 'pixels',
			],
			4098 => [
				'name' => 'RelatedImageLength',
				'type' => 'Numeric',
				'units' => 'pixels',
			],
		],
		'GPS' => [
			0 => [
				'name' => 'GPSVersionID',
				'type' => 'Numeric',
				'units' => '(e.g.: 2.2.0.0 = Version 2.2 )',
			],
			1 => [
				'name' => 'GPSLatitudeRef',
				'type' => 'String',
			],
			2 => [
				'name' => 'GPSLatitude',
				'type' => 'Numeric',
				'units' => '(Degrees Minutes Seconds North or South)',
			],
			3 => [
				'name' => 'GPSLongitudeRef',
				'type' => 'String',
			],
			4 => [
				'name' => 'GPSLongitude',
				'type' => 'Numeric',
				'units' => '(Degrees Minutes Seconds East or West)',
			],
			5 => [
				'name' => 'GPSAltitudeRef',
				'type' => 'Lookup',
				'lookup' => [
					0 => 'Sea Level',
					1 => 'Sea level reference (negative value)',
				],
			],
			6 => [
				'name' => 'GPSAltitude',
				'type' => 'Numeric',
				'units' => 'Metres with respect to Altitude Reference',
			],
			7 => [
				'name' => 'GPSTimeStamp',
				'type' => 'Numeric',
				'units' => '(Hours Minutes Seconds)',
			],
			8 => [
				'name' => 'GPSSatellites',
				'type' => 'String',
			],
			9 => [
				'name' => 'GPSStatus',
				'type' => 'Lookup',
				'lookup' => [
					'A' => 'Measurement in progress',
					'V' => 'Measurement Interoperability',
				],
			],
			10 => [
				'name' => 'GPSMeasureMode',
				'type' => 'Lookup',
				'lookup' => [
					2 => '2-dimensional measurement',
					3 => '3-dimensional measurement',
				],
			],
			11 => [
				'name' => 'GPSDOP',
				'type' => 'Numeric',
				'units' => '(Data Degree of Precision, Horizontal for 2D, Position for 3D)',
			],
			12 => [
				'name' => 'GPSSpeedRef',
				'type' => 'Lookup',
				'lookup' => [
					'K' => 'Kilometers per Hour',
					'M' => 'Miles per Hour',
					'N' => 'Knots',
				],
			],
			13 => [
				'name' => 'GPSSpeed',
				'type' => 'Numeric',
				'units' => 'Speed Units',
			],
			14 => [
				'name' => 'GPSTrackRef',
				'type' => 'Lookup',
				'lookup' => [
					'T' => 'True North',
					'M' => 'Magnetic North',
				],
			],
			15 => [
				'name' => 'GPSTrack',
				'type' => 'Numeric',
				'units' => 'Degrees relative to Movement Direction Reference',
			],
			16 => [
				'name' => 'GPSImgDirectionRef',
				'type' => 'Lookup',
				'lookup' => [
					'T' => 'True North',
					'M' => 'Magnetic North',
				],
			],
			17 => [
				'name' => 'GPSImgDirection',
				'type' => 'Numeric',
				'units' => 'Degrees relative to Image Direction Reference',
			],
			18 => [
				'name' => 'GPSMapDatum',
				'type' => 'String',
			],
			19 => [
				'name' => 'GPSDestLatitudeRef',
				'type' => 'String',
			],
			20 => [
				'name' => 'GPSDestLatitude',
				'type' => 'Numeric',
				'units' => '(Degrees Minutes Seconds North or South)',
			],
			21 => [
				'name' => 'GPSDestLongitudeRef',
				'type' => 'String',
			],
			22 => [
				'name' => 'GPSDestLongitude',
				'type' => 'Numeric',
				'units' => '(Degrees Minutes Seconds East or West)',
			],
			23 => [
				'name' => 'GPSDestBearingRef',
				'type' => 'Lookup',
				'lookup' => [
					'T' => 'True North',
					'M' => 'Magnetic North',
				],
			],
			24 => [
				'name' => 'GPSDestBearing',
				'type' => 'Numeric',
				'units' => 'Degrees relative to Destination Bearing Reference',
			],
			25 => [
				'name' => 'GPSDestDistanceRef',
				'type' => 'Lookup',
				'lookup' => [
					'K' => 'Kilometres',
					'M' => 'Miles',
					'N' => 'Nautical Miles',
				],
			],
			26 => [
				'name' => 'GPSDestDistance',
				'type' => 'Numeric',
				'units' => 'Destination Distance Units',
			],
			27 => [
				'name' => 'GPSProcessingMethod',
				'type' => 'CodedString',
			],
			28 => [
				'name' => 'GPSAreaInformation',
				'type' => 'CodedString',
			],
			29 => [
				'name' => 'GPSDateStamp',
				'type' => 'Numeric',
				'units' => '(Format: YYYY:MM:DD HH:mm:SS)',
			],
			30 => [
				'name' => 'GPSDifferential',
				'type' => 'Lookup',
				'lookup' => [
					0 => 'Measurement without differential correction',
					1 => 'Differential correction applied',
				],
			],
			31 => [
				'name' => 'GPSHPositioningError',
				'type' => 'Numeric',
				'units' => 'metres',
			],
		],
		'Meta' => [
			50000 => [
				'name' => 'CaptureDeviceFilmProductCode',
				'type' => 'Unknown',
			],
			50001 => [
				'name' => 'DigitalProcessImageSourceEK',
				'type' => 'Unknown',
			],
			50002 => [
				'name' => 'CaptureConditionsPAR',
				'type' => 'Unknown',
			],
			50003 => [
				'name' => 'CaptureDeviceCameraOwnerEK',
				'type' => 'CodedString',
			],
			50004 => [
				'name' => 'CaptureDeviceSerialNumberCamera',
				'type' => 'Unknown',
			],
			50005 => [
				'name' => 'SceneContentGroupCaptionUserSelectGroupTitle',
				'type' => 'Unknown',
			],
			50006 => [
				'name' => 'OutputOrderInformationDealerIDNumber',
				'type' => 'Unknown',
			],
			50007 => [
				'name' => 'CaptureDeviceFID',
				'type' => 'Unknown',
			],
			50008 => [
				'name' => 'OutputOrderInformationEnvelopeNumber',
				'type' => 'Unknown',
			],
			50009 => [
				'name' => 'OutputOrderSimpleRenderInstFrameNumber',
				'type' => 'Unknown',
			],
			50010 => [
				'name' => 'CaptureDeviceFilmCategory',
				'type' => 'Unknown',
			],
			50011 => [
				'name' => 'CaptureDeviceFilmGencode',
				'type' => 'Unknown',
			],
			50012 => [
				'name' => 'CaptureDeviceScannerModelAndVersion',
				'type' => 'Unknown',
			],
			50013 => [
				'name' => 'CaptureDeviceFilmSize',
				'type' => 'Unknown',
			],
			50014 => [
				'name' => 'DigitalProcessHistorySBARGBShifts',
				'type' => 'Unknown',
			],
			50015 => [
				'name' => 'DigitalProcessHistorySBAInputImageColourspace',
				'type' => 'Unknown',
			],
			50016 => [
				'name' => 'DigitalProcessHistorySBAInputImageBitDepth',
				'type' => 'Unknown',
			],
			50017 => [
				'name' => 'DigitalProcessHistorySBAExposureRecord',
				'type' => 'Unknown',
			],
			50018 => [
				'name' => 'DigitalProcessHistoryUserAdjSBARGBShifts',
				'type' => 'Unknown',
			],
			50019 => [
				'name' => 'DigitalProcessImageRotationStatus',
				'type' => 'Unknown',
			],
			50020 => [
				'name' => 'DigitalProcessRollGuidElements',
				'type' => 'Unknown',
			],
			50021 => [
				'name' => 'ImageContainerMetadataNumber',
				'type' => 'String',
			],
			50022 => [
				'name' => 'DigitalProcessHistoryEditTagArray',
				'type' => 'Unknown',
			],
			50023 => [
				'name' => 'CaptureConditionsMagnification',
				'type' => 'Unknown',
			],
			50028 => [
				'name' => 'CaptureDeviceNativePhysicalXResolution',
				'type' => 'Unknown',
			],
			50029 => [
				'name' => 'CaptureDeviceNativePhysicalYResolution',
				'type' => 'Unknown',
			],
			50030 => [
				'name' => 'KodakSpecialEffectsIFD',
				'type' => 'SubIFD',
				'subIfd' => 'KodakSpecialEffects',
			],
			50031 => [
				'name' => 'KodakBordersIFD',
				'type' => 'SubIFD',
				'subIfd' => 'KodakBorders',
			],
			50042 => [
				'name' => 'CaptureDeviceNativePhysicalResolutionUnit',
				'type' => 'Unknown',
			],
			50200 => [
				'name' => 'ImageContainerSourceImageDirectory',
				'type' => 'Unknown',
			],
			50201 => [
				'name' => 'ImageContainerSourceImageFileName',
				'type' => 'Unknown',
			],
			50202 => [
				'name' => 'ImageContainerSourceImageVolumeName',
				'type' => 'Unknown',
			],
			50284 => [
				'name' => 'CaptureConditionsPrintQuantity',
				'type' => 'Unknown',
			],
			50286 => [
				'name' => 'DigitalProcessImagePrintStatus',
				'type' => 'Unknown',
			],
		],
		'KodakSpecialEffects' => [
			0 => [
				'name' => 'DigitalEffectsVersion',
				'type' => 'Numeric',
			],
			1 => [
				'name' => 'DigitalEffectsName',
				'type' => 'CodedString',
			],
			2 => [
				'name' => 'DigitalEffectsType',
				'type' => 'Lookup',
				'lookup' => [
					0 => 'None Applied',
				],
			],
		],
		'KodakBorders' => [
			0 => [
				'name' => 'BordersVersion',
				'type' => 'Numeric',
			],
			1 => [
				'name' => 'BorderName',
				'type' => 'CodedString',
			],
			2 => [
				'name' => 'BorderID',
				'type' => 'Numeric',
			],
			3 => [
				'name' => 'BorderLocation',
				'type' => 'Lookup',
				'lookup' => [
				],
			],
			4 => [
				'name' => 'BorderType',
				'type' => 'Lookup',
				'lookup' => [
					0 => 'None',
				],
			],
			8 => [
				'name' => 'WatermarkType',
				'type' => 'Lookup',
				'lookup' => [
					0 => 'None',
				],
			],
		],
	];

	/** @var array<string, array> The per-class lowercased name => [group, id] indexes. */
	private static array $_nameIndex = [];

	/**
	 * Returns a tag definition.
	 * @param string $group The tag group (a class constant).
	 * @param int $tag The tag id.
	 * @return ?array The definition, or null when unknown.
	 */
	public static function definition(string $group, int $tag): ?array
	{
		return static::Definitions[$group][$tag] ?? null;
	}

	/**
	 * Returns a tag's name.
	 * @param string $group The tag group.
	 * @param int $tag The tag id.
	 * @return ?string The name, or null when unknown.
	 */
	public static function nameOf(string $group, int $tag): ?string
	{
		return static::Definitions[$group][$tag]['name'] ?? null;
	}

	/**
	 * Resolves a tag name to its group and id, case-insensitively.
	 * @param string $name The tag name (e.g. 'Make', 'FNumber', 'GPSLatitude').
	 * @param ?string $group A group to search, or null for all groups in order.
	 * @return ?array The [group, id] pair, or null when not found.
	 */
	public static function findByName(string $name, ?string $group = null): ?array
	{
		if (!isset(self::$_nameIndex[static::class])) {
			self::$_nameIndex[static::class] = [];
			foreach (static::Definitions as $groupName => $tags) {
				foreach ($tags as $id => $def) {
					self::$_nameIndex[static::class][$groupName][strtolower($def['name'])] = [$groupName, $id];
					foreach ($def['aliases'] ?? [] as $alias) {
						self::$_nameIndex[static::class][$groupName][strtolower($alias)] ??= [$groupName, $id];
					}
				}
			}
		}
		$key = strtolower($name);
		if ($group !== null) {
			return self::$_nameIndex[static::class][$group][$key] ?? null;
		}
		foreach (self::$_nameIndex[static::class] as $tags) {
			if (isset($tags[$key])) {
				return $tags[$key];
			}
		}
		return null;
	}

	/**
	 * Renders a tag's value human-readably: enumerations through their lookup tables,
	 * numerics with units and simplified rationals, strings trimmed, and the special
	 * encodings through their decoders.
	 * @param TIFFTag $tag The tag.
	 * @param string $group The tag group.
	 * @param ?bool $bigEndian The byte order of the surrounding block, for the special
	 *   decoders that read packed fields; null assumes big-endian.
	 * @return ?string The text value, or null for pointer/opaque tags with no text form.
	 */
	public static function textValue(TIFFTag $tag, string $group, ?bool $bigEndian = null): ?string
	{
		$def = self::definition($group, $tag->getId());
		$type = $def['type'] ?? null;
		switch ($type) {
			case 'Lookup':
				$parts = [];
				foreach ((array) $tag->getValues() as $value) {
					$parts[] = $def['lookup'][$value] ?? "Unknown ($value)";
				}
				return implode(', ', $parts);
			case 'String':
				return trim(rtrim((string) (is_array($tag->getValues()) ? implode('', $tag->getValues()) : $tag->getValues()), "\0"));
			case 'CodedString':
				return self::decodeCodedString((string) $tag->getValues());
			case 'Special':
				return self::decodeSpecial($tag, $def['special'] ?? '', $bigEndian);
			case 'SubIFD':
			case 'MakerNote':
			case 'PIM':
			case 'IPTC':
			case 'XMP':
			case 'IRB':
				return null;
			case 'Numeric':
			default:
				if ($group === self::GPS) {
					$special = self::decodeGps($tag);
					if ($special !== null) {
						return $special;
					}
				}
				$text = self::numericText($tag);
				if ($text !== null && isset($def['units'])) {
					$text .= ' ' . $def['units'];
				}
				return $text;
		}
	}

	/**
	 * Renders a numeric value set: integers joined, rationals simplified to quotients.
	 * @param TIFFTag $tag The tag.
	 * @return ?string The text, or null for a non-numeric value set.
	 */
	protected static function numericText(TIFFTag $tag): ?string
	{
		$values = $tag->getValues();
		if (!is_array($values)) {
			return $values === '' ? '' : implode(' ', array_map('ord', str_split($values)));
		}
		$parts = [];
		foreach ($values as $i => $value) {
			if (is_array($value)) {
				$quotient = $tag->getRational($i);
				if ($quotient === null) {
					$parts[] = $value[0] . '/' . $value[1];
				} elseif ($value[1] !== 0 && abs($value[0]) === 1 && abs($value[1]) > 1) {
					$parts[] = $value[0] . '/' . $value[1];
				} else {
					$parts[] = rtrim(rtrim(number_format($quotient, 4, '.', ''), '0'), '.');
				}
			} else {
				$parts[] = (string) $value;
			}
		}
		return implode(', ', $parts);
	}

	/**
	 * Encodes text as a character-coded string: the 8-byte charset signature then the
	 * text, the form EXIF uses for UserComment and Exif audio for its user comment.
	 * @param string $text The text.
	 * @param ?string $charset The charset to force ('ASCII', 'UNICODE', 'JIS'), or null
	 *   to choose ASCII for plain text and UNICODE (UTF-16BE) otherwise.
	 * @return string The coded bytes.
	 */
	public static function encodeCodedString(string $text, ?string $charset = null): string
	{
		$charset ??= preg_match('/[\x80-\xFF]/', $text) ? 'UNICODE' : 'ASCII';
		$charset = strtoupper($charset);
		if ($charset === 'UNICODE') {
			$encoded = @iconv('UTF-8', 'UTF-16BE//IGNORE', $text);
			return "UNICODE\0" . ($encoded === false ? '' : $encoded);
		}
		if ($charset === 'JIS') {
			$encoded = @iconv('UTF-8', 'ISO-2022-JP//IGNORE', $text);
			return "JIS\0\0\0\0\0" . ($encoded === false ? '' : $encoded);
		}
		return "ASCII\0\0\0" . $text;
	}

	/**
	 * Decodes a character-coded string (an 8-byte charset signature then the text).
	 * @param string $data The coded bytes.
	 * @return string The decoded text.
	 */
	public static function decodeCodedString(string $data): string
	{
		$signature = substr($data, 0, 8);
		$text = substr($data, 8);
		if (str_starts_with($signature, 'ASCII')) {
			return trim(rtrim($text, "\0"));
		}
		if (str_starts_with($signature, 'UNICODE')) {
			// Exif gives the comment no charset of its own: it is UTF-16, and a bare
			// 'UTF-16' conversion guesses the byte order differently per platform
			// (libiconv reads big-endian, glibc little-endian), so the order is always
			// named here -- from the byte-order mark when a writer left one, and
			// otherwise big-endian: the Unicode default for an unmarked UTF-16 stream,
			// and what {@see encodeCodedString()} writes.
			$charset = 'UTF-16BE';
			if (str_starts_with($text, "\xFF\xFE")) {
				$charset = 'UTF-16LE';
				$text = substr($text, 2);
			} elseif (str_starts_with($text, "\xFE\xFF")) {
				$text = substr($text, 2);
			}
			// A payload truncated mid-character cannot be decoded at all: iconv answers
			// false and the cast leaves nothing, which is the documented behaviour.
			$decoded = @iconv($charset, 'UTF-8//IGNORE', $text);
			return trim(rtrim((string) $decoded, "\0"));
		}
		if (str_starts_with($signature, 'JIS')) {
			$decoded = @iconv('ISO-2022-JP', 'UTF-8//IGNORE', $text);
			return trim(rtrim($decoded === false ? $text : $decoded, "\0"));
		}
		return trim(rtrim($data, "\0"));
	}

	/**
	 * Decodes the special-encoding tags.
	 * @param TIFFTag $tag The tag.
	 * @param string $decoder The decoder id from the definition.
	 * @param ?bool $bigEndian
	 * @return ?string The text, or null when the decoder is unknown.
	 */
	protected static function decodeSpecial(TIFFTag $tag, string $decoder, ?bool $bigEndian = null): ?string
	{
		$values = $tag->getValues();
		switch ($decoder) {
			case 'ComponentsConfiguration':
				$names = [0 => '-', 1 => 'Y', 2 => 'Cb', 3 => 'Cr', 4 => 'R', 5 => 'G', 6 => 'B'];
				$bytes = is_array($values) ? $values : array_map('ord', str_split($values));
				return implode(' ', array_map(fn ($v) => $names[$v] ?? "?$v", $bytes));
			case 'YCbCrSubSampling':
				$pair = is_array($values) ? $values : [];
				$known = ['1,1' => 'YCbCr 4:4:4', '2,1' => 'YCbCr 4:2:2', '2,2' => 'YCbCr 4:2:0', '4,1' => 'YCbCr 4:1:1', '4,2' => 'YCbCr 4:1:0'];
				$key = implode(',', $pair);
				return $known[$key] ?? $key;
			case 'CFAPattern':
				$data = is_array($values) ? implode('', array_map('chr', $values)) : (string) $values;
				if (strlen($data) < 4) {
					return null;
				}
				$columns = (ord($data[0]) << 8) | ord($data[1]);
				$rows = (ord($data[2]) << 8) | ord($data[3]);
				if ($columns < 1 || $rows < 1 || strlen($data) < 4 + $columns * $rows) {
					return null;
				}
				$colors = [0 => 'R', 1 => 'G', 2 => 'B', 3 => 'C', 4 => 'M', 5 => 'Y', 6 => 'W'];
				$grid = [];
				for ($r = 0; $r < $rows; $r++) {
					$row = '';
					for ($c = 0; $c < $columns; $c++) {
						$row .= $colors[ord($data[4 + $r * $columns + $c])] ?? '?';
					}
					$grid[] = $row;
				}
				return implode(' / ', $grid);
			case 'JPEGInterchangeFormat':
				return 'JPEG thumbnail data';
			case 'LearningOptOutIn':
				$sets = self::decodeLearningOptOut(is_array($values) ? '' : (string) $values, $bigEndian ?? true);
				if ($sets === []) {
					return null;
				}
				$parts = [];
				foreach ($sets as $usage => $intention) {
					$parts[] = (self::LearningUsages[$usage] ?? "Usage $usage")
						. ': ' . (self::LearningIntentions[$intention] ?? "Unknown ($intention)");
				}
				return implode('; ', $parts);
			case 'DevelopmentType':
				// Exif 3.1: high byte = development characteristic, low byte = difference
				// from the capture device's factory-default development.
				$value = $tag->getValue();
				if (!is_int($value)) {
					return null;
				}
				$characteristics = [
					0x01 => 'Development for the sameness with the image at the time of capture',
					0x02 => 'Development not for the sameness, without extreme difference to the image at the time of capture',
					0x04 => 'Development making an extreme difference to the image at the time of capture',
				];
				$differences = [
					0x01 => 'factory default development',
					0x02 => 'different from factory default',
					0x04 => 'unknown difference from factory default',
				];
				$high = ($value >> 8) & 0xFF;
				$low = $value & 0xFF;
				return ($characteristics[$high] ?? sprintf('Reserved (0x%02X)', $high))
					. '; ' . ($differences[$low] ?? sprintf('reserved (0x%02X)', $low));
			default:
				return null;
		}
	}

	/**
	 * Decodes a `LearningOptOutIn` block: a count of sets, then that many
	 * usage/indication-of-intention pairs, each an unsigned short (Exif 3.1).
	 * @param string $data The tag bytes.
	 * @param bool $bigEndian The byte order of the surrounding block.
	 * @return array<int, int> The usage to intention map, in file order.
	 */
	public static function decodeLearningOptOut(string $data, bool $bigEndian = true): array
	{
		if (strlen($data) < 6) {
			return [];
		}
		$format = $bigEndian ? 'n' : 'v';
		$count = unpack($format, substr($data, 0, 2))[1];
		if ($count < 1 || strlen($data) < 2 + $count * 4) {
			return [];
		}
		$sets = [];
		for ($i = 0; $i < $count; $i++) {
			$usage = unpack($format, substr($data, 2 + $i * 4, 2))[1];
			$intention = unpack($format, substr($data, 4 + $i * 4, 2))[1];
			$sets[$usage] = $intention;
		}
		return $sets;
	}

	/**
	 * Encodes a `LearningOptOutIn` block from a usage to intention map, ordering the
	 * mandatory `All / Individual usage is not specified` (usage 0) set first.
	 * @param array<int, int> $sets The usage to intention map.
	 * @param bool $bigEndian The byte order of the surrounding block.
	 * @return string The tag bytes.
	 */
	public static function encodeLearningOptOut(array $sets, bool $bigEndian = true): string
	{
		if (isset($sets[0])) {
			$sets = [0 => $sets[0]] + $sets;
		}
		$format = $bigEndian ? 'n' : 'v';
		$out = pack($format, count($sets));
		foreach ($sets as $usage => $intention) {
			$out .= pack($format, (int) $usage) . pack($format, (int) $intention);
		}
		return $out;
	}

	/**
	 * Decodes the composite GPS values: coordinates to degrees-minutes-seconds and the
	 * timestamp to a clock time.
	 * @param TIFFTag $tag The GPS tag.
	 * @return ?string The text, or null when the tag has no composite form.
	 */
	protected static function decodeGps(TIFFTag $tag): ?string
	{
		if (($tag->getType() !== TIFFDataType::URational && $tag->getType() !== TIFFDataType::SRational) || $tag->getCount() !== 3) {
			return null;
		}
		$id = $tag->getId();
		if ($id === 2 || $id === 4) {   // GPSLatitude / GPSLongitude
			$degrees = $tag->getRational(0);
			$minutes = $tag->getRational(1);
			$seconds = $tag->getRational(2);
			if ($degrees === null || $minutes === null || $seconds === null) {
				return null;
			}
			return sprintf('%d° %d\' %s"', (int) $degrees, (int) $minutes, rtrim(rtrim(number_format($seconds, 2, '.', ''), '0'), '.'));
		}
		if ($id === 7) {   // GPSTimeStamp
			return sprintf('%02d:%02d:%02d', (int) $tag->getRational(0), (int) $tag->getRational(1), (int) $tag->getRational(2));
		}
		return null;
	}
}
