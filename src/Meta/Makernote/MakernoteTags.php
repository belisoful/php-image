<?php

/**
 * MakernoteTags class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\Meta\Makernote;

use Belisoful\Image\Meta\EXIFTags;

/**
 * MakernoteTags class.
 *
 * The tag knowledge base for the camera-maker makernote IFDs, spanning thirteen makers
 * (Agfa, Canon, Casio, Epson, Fujifilm, Konica/Minolta, Kyocera/Contax, Nikon, Olympus,
 * Panasonic, Pentax/Asahi, Ricoh, and Sony) across their format variants.  It extends
 * {@see EXIFTags}, so {@see definition()}, {@see nameOf()}, {@see findByName()}, and
 * {@see textValue()} interpret makernote tags exactly as the EXIF groups are
 * interpreted — names, enumeration lookups, units, and special decoders.
 *
 * {@see Headers} additionally records each maker's container facts: the header byte
 * signatures of every variant, the IFD start offset (or Fujifilm's little-endian
 * offset pointer), forced byte orders, makernote-relative offset addressing, missing
 * next-IFD pointers, Nikon Type 3's embedded TIFF header, the nested Ricoh camera-info
 * sub-IFD, the undecodable Minolta signatures, and the thumbnail tag ids of the makers
 * that embed one.  {@see \Belisoful\Image\Meta\Makernote\Makernote} drives its parser
 * from these facts.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class MakernoteTags extends EXIFTags
{
	/**
	 * @var array The makernote tag definitions, keyed by tag group (e.g. 'Canon',
	 *   'Casio Type 2', 'Nikon Type 3', 'Olympus'); same shape as {@see EXIFTags::Definitions}.
	 */
	public const Definitions = [
		'Canon' => [
			1 => [
				'name' => "Camera Settings 1",
				'type' => "Special",
				'special' => "Array of 16-bit values decoded via the CanonCameraSettings1 table (offset 0 is the byte count of the tag)",
			],
			4 => [
				'name' => "Camera Settings 2",
				'type' => "Special",
				'special' => "Array of 16-bit values decoded via the CanonCameraSettings2 table (offset 0 is the byte count of the tag)",
			],
			6 => [
				'name' => "Image Type",
				'type' => "String",
			],
			7 => [
				'name' => "Firmware Version",
				'type' => "String",
			],
			8 => [
				'name' => "Image Number",
				'type' => "Numeric",
			],
			9 => [
				'name' => "Owner Name",
				'type' => "String",
			],
			12 => [
				'name' => "Camera Serial Number",
				'type' => "Special",
				'special' => "Serial = sprintf(\"%04X%05d\", (value & 0xFF00) >> 8, value & 0x00FF)",
			],
			15 => [
				'name' => "Custom Functions",
				'type' => "Special",
				'special' => "Array of 16-bit values (first is the byte count); function number = high byte, function value = low byte, decoded via the CanonCustomFunctions table",
			],
		],
		'Casio Type 1' => [
			1 => [
				'name' => "Recording Mode",
				'type' => "Lookup",
				'lookup' => [
					1 => "Single Shutter",
					2 => "Panorama",
					3 => "Night Scene",
					4 => "Portrait",
					5 => "Landscape",
				],
			],
			2 => [
				'name' => "Quality",
				'type' => "Lookup",
				'lookup' => [
					1 => "Economy",
					2 => "Normal",
					3 => "Fine",
				],
			],
			3 => [
				'name' => "Focusing Mode",
				'type' => "Lookup",
				'lookup' => [
					2 => "Macro",
					3 => "Auto Focus",
					4 => "Manual Focus",
					5 => "Infinity",
				],
			],
			4 => [
				'name' => "Flash Mode",
				'type' => "Lookup",
				'lookup' => [
					1 => "Auto",
					2 => "On",
					3 => "Off",
					4 => "Off",
				],
			],
			5 => [
				'name' => "Flash Intensity",
				'type' => "Lookup",
				'lookup' => [
					11 => "Weak",
					13 => "Normal",
					15 => "Strong",
				],
			],
			6 => [
				'name' => "Object Distance",
				'type' => "Numeric",
				'units' => "mm",
			],
			7 => [
				'name' => "White Balance",
				'type' => "Lookup",
				'lookup' => [
					1 => "Auto",
					2 => "Tungsten",
					3 => "Daylight",
					4 => "Flourescent",
					5 => "Shade",
					129 => "Manual",
				],
			],
			10 => [
				'name' => "Digital Zoom",
				'type' => "Lookup",
				'lookup' => [
					65536 => "Off",
					65537 => "2x Digital Zoom",
					131072 => "2x Digital Zoom",
					262144 => "4x Digital Zoom",
				],
			],
			11 => [
				'name' => "Sharpness",
				'type' => "Lookup",
				'lookup' => [
					0 => "Normal",
					1 => "Soft",
					2 => "Hard",
				],
			],
			12 => [
				'name' => "Contrast",
				'type' => "Lookup",
				'lookup' => [
					0 => "Normal",
					1 => "Low",
					2 => "High",
				],
			],
			13 => [
				'name' => "Saturation",
				'type' => "Lookup",
				'lookup' => [
					0 => "Normal",
					1 => "Low",
					2 => "High",
				],
			],
			20 => [
				'name' => "CCD Sensitivity",
				'type' => "Lookup",
				'lookup' => [
					64 => "Normal",
					125 => "+1.0",
					250 => "+2.0",
					244 => "+3.0",
					80 => "Normal (ISO 80 equivalent)",
					100 => "High",
				],
			],
		],
		'Casio Type 2' => [
			2 => [
				'name' => "Preview Thumbnail Dimensions",
				'type' => "Numeric",
				'units' => "(x,y pixels)",
			],
			3 => [
				'name' => "Preview Thumbnail Size",
				'type' => "Numeric",
				'units' => "bytes",
			],
			4 => [
				'name' => "Preview Thumbnail",
				'type' => "Numeric",
				'special' => "Thumbnail offset",
			],
			8 => [
				'name' => "Quality Mode",
				'type' => "Lookup",
				'lookup' => [
					1 => "Fine",
					2 => "Super Fine",
				],
			],
			9 => [
				'name' => "Image Size",
				'type' => "Lookup",
				'lookup' => [
					20 => "2288 x 1712 pixels",
					36 => "3008 x 2008 pixels",
					5 => "2048 x 1536 pixels",
					4 => "1600 x 1200 pixels",
					21 => "2592 x 1944 pixels",
					0 => "640 x 480 pixels",
					22 => "2304 x 1728 pixels",
				],
			],
			13 => [
				'name' => "Focus Mode",
				'type' => "Lookup",
				'lookup' => [
					0 => "Normal",
					1 => "Macro",
				],
			],
			20 => [
				'name' => "Iso Sensitivity",
				'type' => "Lookup",
				'lookup' => [
					3 => "50",
					4 => "64",
					6 => "100",
					9 => "200",
				],
			],
			25 => [
				'name' => "White Balance",
				'type' => "Lookup",
				'lookup' => [
					0 => "Auto",
					1 => "Daylight",
					2 => "Shade",
					3 => "Tungsten",
					4 => "Fluorescent",
					5 => "Manual",
				],
			],
			29 => [
				'name' => "Focal Length",
				'type' => "Special",
				'units' => "mm",
				'special' => "Focal length = value / 10 mm",
			],
			31 => [
				'name' => "Saturation",
				'type' => "Lookup",
				'lookup' => [
					0 => "-1",
					1 => "Normal",
					2 => "+1",
				],
			],
			32 => [
				'name' => "Contrast",
				'type' => "Lookup",
				'lookup' => [
					0 => "-1",
					1 => "Normal",
					2 => "+1",
				],
			],
			33 => [
				'name' => "Sharpness",
				'type' => "Lookup",
				'lookup' => [
					0 => "-1",
					1 => "Normal",
					2 => "+1",
				],
			],
			3584 => [
				'name' => "Print Image Matching Info",
				'type' => "PIM",
			],
			8192 => [
				'name' => "Casio Preview Thumbnail",
				'type' => "String",
				'special' => "Thumbnail offset",
			],
			8209 => [
				'name' => "White Balance Bias",
				'type' => "Numeric",
			],
			8210 => [
				'name' => "White Balance",
				'type' => "Lookup",
				'lookup' => [
					12 => "Flash",
					0 => "Manual",
					1 => "Auto?",
					4 => "Flash?",
				],
			],
			8226 => [
				'name' => "Object Distance",
				'type' => "Numeric",
				'units' => "mm",
			],
			8244 => [
				'name' => "Flash Distance",
				'type' => "Numeric",
				'units' => "(0=Off)",
			],
			12288 => [
				'name' => "Record Mode",
				'type' => "Lookup",
				'lookup' => [
					2 => "Normal Mode",
				],
			],
			12289 => [
				'name' => "Self Timer?",
				'type' => "Lookup",
				'lookup' => [
					1 => "Off?",
				],
			],
			12290 => [
				'name' => "Quality",
				'type' => "Lookup",
				'lookup' => [
					3 => "Fine",
				],
			],
			12291 => [
				'name' => "Focus Mode",
				'type' => "Lookup",
				'lookup' => [
					6 => "Multi-Area Auto Focus",
					1 => "Fixation",
				],
			],
			12294 => [
				'name' => "Time Zone",
				'type' => "String",
			],
			12295 => [
				'name' => "Bestshot Mode",
				'type' => "Lookup",
				'lookup' => [
					0 => "Off",
					1 => "On?",
				],
			],
			12308 => [
				'name' => "CCD ISO Sensitivity",
				'type' => "Numeric",
			],
			12309 => [
				'name' => "Colour Mode",
				'type' => "Lookup",
				'lookup' => [
					0 => "Off",
				],
			],
			12310 => [
				'name' => "Enhancement",
				'type' => "Lookup",
				'lookup' => [
					0 => "Off",
				],
			],
			12311 => [
				'name' => "Filter",
				'type' => "Lookup",
				'lookup' => [
					0 => "Off",
				],
			],
		],
		'Fujifilm' => [
			0 => [
				'name' => "Version",
				'type' => "String",
			],
			4096 => [
				'name' => "Quality",
				'type' => "String",
			],
			4097 => [
				'name' => "Sharpness",
				'type' => "Lookup",
				'lookup' => [
					1 => "Softest",
					2 => "Soft",
					3 => "Normal",
					4 => "Hard",
					5 => "Hardest",
				],
			],
			4098 => [
				'name' => "White Balance",
				'type' => "Lookup",
				'lookup' => [
					0 => "Auto",
					256 => "Daylight",
					512 => "Cloudy",
					768 => "DaylightColour-fluorescence",
					769 => "DaywhiteColour-fluorescence",
					770 => "White-fluorescence",
					1024 => "Incandenscense",
					3840 => "Custom white balance",
				],
			],
			4099 => [
				'name' => "Colour Saturation",
				'type' => "Lookup",
				'lookup' => [
					0 => "Normal",
					256 => "High",
					512 => "Low",
				],
			],
			4100 => [
				'name' => "Tone (Contrast)",
				'type' => "Lookup",
				'lookup' => [
					0 => "Normal",
					256 => "High",
					512 => "Low",
				],
			],
			4112 => [
				'name' => "Flash Mode",
				'type' => "Lookup",
				'lookup' => [
					0 => "Auto",
					1 => "On",
					2 => "Off",
					3 => "Red-eye Reduction",
				],
			],
			4113 => [
				'name' => "Flash Strength",
				'type' => "Numeric",
				'units' => "EV",
			],
			4128 => [
				'name' => "Macro",
				'type' => "Lookup",
				'lookup' => [
					0 => "Off",
					1 => "On",
				],
			],
			4129 => [
				'name' => "Focus Mode",
				'type' => "Lookup",
				'lookup' => [
					0 => "Auto Focus",
					1 => "Manual Focus",
				],
			],
			4144 => [
				'name' => "Slow Sync",
				'type' => "Lookup",
				'lookup' => [
					0 => "Off",
					1 => "On",
				],
			],
			4145 => [
				'name' => "Picture Mode",
				'type' => "Lookup",
				'lookup' => [
					0 => "Auto",
					1 => "Portrait Scene",
					2 => "Landscape Scene",
					4 => "Sports Scene",
					5 => "Night Scene",
					6 => "Program AE",
					256 => "Aperture priority AE",
					512 => "Shutter priority AE",
					768 => "Manual Exposure",
				],
			],
			4352 => [
				'name' => "Continuous taking or auto bracketing mode",
				'type' => "Lookup",
				'lookup' => [
					0 => "Off",
					1 => "On",
				],
			],
			4864 => [
				'name' => "Blur Warning",
				'type' => "Lookup",
				'lookup' => [
					0 => "No Blur Warning",
					1 => "Blur Warning",
				],
			],
			4865 => [
				'name' => "Focus warning",
				'type' => "Lookup",
				'lookup' => [
					0 => "Auto Focus Good",
					1 => "Out of Focus",
				],
			],
			4866 => [
				'name' => "Auto Exposure Warning",
				'type' => "Lookup",
				'lookup' => [
					0 => "Auto Exposure Good",
					1 => "Over exposure (>1/1000s,F11)",
				],
			],
		],
		'Kyocera' => [
			1 => [
				'name' => "Kyocera Proprietory Format Thumbnail",
				'type' => "Unknown",
			],
			3584 => [
				'name' => "Print Image Matching Info",
				'type' => "PIM",
			],
		],
		'Nikon Type 1' => [
			3 => [
				'name' => "Quality",
				'type' => "Lookup",
				'lookup' => [
					1 => "VGA (640x480) Basic",
					2 => "VGA (640x480) Normal",
					3 => "VGA (640x480) Fine",
					4 => "SXGA (1280x960) Basic",
					5 => "SXGA (1280x960) Normal",
					6 => "SXGA (1280x960) Fine",
					7 => "Unknown, Possibly XGA (1024x768) Basic",
					8 => "Unknown, Possibly XGA (1024x768) Basic",
					9 => "Unknown, Possibly XGA (1024x768) Basic",
					10 => "UXGA (1600x1200) Basic",
					11 => "UXGA (1600x1200) Normal",
					12 => "UXGA (1600x1200) Fine",
				],
			],
			4 => [
				'name' => "Colour Mode",
				'type' => "Lookup",
				'lookup' => [
					1 => "Colour",
					2 => "Monochrome",
				],
			],
			5 => [
				'name' => "Image Adjustment",
				'type' => "Lookup",
				'lookup' => [
					0 => "Normal",
					1 => "Bright+",
					2 => "Bright-",
					3 => "Contrast+",
					4 => "Contrast-",
				],
			],
			6 => [
				'name' => "CCD Sensitivity",
				'type' => "Lookup",
				'lookup' => [
					0 => "ISO 80",
					2 => "ISO 160",
					4 => "ISO 320",
					5 => "ISO 100",
				],
			],
			7 => [
				'name' => "White Balance",
				'type' => "Lookup",
				'lookup' => [
					0 => "Auto",
					1 => "Preset",
					2 => "Daylight",
					3 => "Incandescense",
					4 => "Flourescence",
					5 => "Cloudy",
					6 => "Speedlight",
				],
			],
			8 => [
				'name' => "Focus",
				'type' => "Numeric",
				'special' => "If infinite focus, value is '1/0'",
			],
			10 => [
				'name' => "Digital Zoom",
				'type' => "Numeric",
				'special' => "'160/100' means 1.6x digital zoom, '0/100' means no digital zoom (optical zoom only)",
			],
			11 => [
				'name' => "Converter",
				'type' => "Lookup",
				'lookup' => [
					0 => "No Converter Used",
					1 => "Fish-eye Converter Used",
				],
			],
		],
		'Nikon Type 3' => [
			1 => [
				'name' => "Nikon Makernote Version",
				'type' => "Special",
				'special' => "Version field; some cameras store it as binary, some as text",
			],
			2 => [
				'name' => "ISO Speed Used",
				'type' => "Special",
				'special' => "Pair of values; the second value is the ISO speed",
			],
			3 => [
				'name' => "Colour Mode",
				'type' => "String",
			],
			4 => [
				'name' => "Quality",
				'type' => "String",
			],
			5 => [
				'name' => "White Balance",
				'type' => "String",
			],
			6 => [
				'name' => "Sharpening",
				'type' => "String",
			],
			7 => [
				'name' => "Focus Mode",
				'type' => "String",
			],
			8 => [
				'name' => "Flash Setting",
				'type' => "String",
			],
			9 => [
				'name' => "Auto Flash Mode",
				'type' => "String",
			],
			11 => [
				'name' => "White Balance Bias Value",
				'type' => "Numeric",
				'units' => "(Units Approx: 100 Mired per increment)",
			],
			12 => [
				'name' => "White Balance Red, Blue Coefficients?",
				'type' => "Numeric",
			],
			15 => [
				'name' => "ISO Selection?",
				'type' => "String",
			],
			18 => [
				'name' => "Flash Compensation",
				'type' => "Lookup",
				'lookup' => [
					6 => "+1.0 EV",
					4 => "+0.7 EV",
					3 => "+0.5 EV",
					2 => "+0.3 EV",
					0 => "0.0 EV",
					254 => "-0.3 EV",
					253 => "-0.5 EV",
					252 => "-0.7 EV",
					250 => "-1.0 EV",
					248 => "-1.3 EV",
					247 => "-1.5 EV",
					246 => "-1.7 EV",
					244 => "-2.0 EV",
					242 => "-2.3 EV",
					241 => "-2.5 EV",
					240 => "-2.7 EV",
					238 => "-3.0 EV",
				],
			],
			19 => [
				'name' => "ISO Speed Requested",
				'type' => "Special",
				'units' => "(May be different to Speed Used when Auto ISO is on)",
				'special' => "Pair of values; the second value is the ISO speed",
			],
			22 => [
				'name' => "Photo corner coordinates",
				'type' => "Numeric",
				'units' => "Pixels",
			],
			24 => [
				'name' => "Flash Bracket Compensation Applied",
				'type' => "Lookup",
				'lookup' => [
					6 => "+1.0 EV",
					4 => "+0.7 EV",
					3 => "+0.5 EV",
					2 => "+0.3 EV",
					0 => "0.0 EV",
					254 => "-0.3 EV",
					253 => "-0.5 EV",
					252 => "-0.7 EV",
					250 => "-1.0 EV",
					248 => "-1.3 EV",
					247 => "-1.5 EV",
					246 => "-1.7 EV",
					244 => "-2.0 EV",
					242 => "-2.3 EV",
					241 => "-2.5 EV",
					240 => "-2.7 EV",
					238 => "-3.0 EV",
				],
			],
			25 => [
				'name' => "AE Bracket Compensation Applied",
				'type' => "Numeric",
				'units' => "EV",
			],
			128 => [
				'name' => "Image Adjustment?",
				'type' => "String",
			],
			129 => [
				'name' => "Tone Compensation (Contrast)",
				'type' => "String",
			],
			130 => [
				'name' => "Auxiliary Lens (Adapter)",
				'type' => "String",
			],
			131 => [
				'name' => "Lens Type?",
				'type' => "Lookup",
				'lookup' => [
					6 => "Nikon D series Lens",
					14 => "Nikon G series Lens",
				],
			],
			132 => [
				'name' => "Lens Min/Max Focal Length, Min/Max Aperture",
				'type' => "Numeric",
				'units' => "mm, mm, F#, F#",
			],
			133 => [
				'name' => "Manual Focus Distance?",
				'type' => "Numeric",
			],
			134 => [
				'name' => "Digital Zoom Factor?",
				'type' => "Numeric",
			],
			135 => [
				'name' => "Flash Used",
				'type' => "Lookup",
				'lookup' => [
					0 => "Flash Not Used",
					9 => "Flash Fired",
				],
			],
			136 => [
				'name' => "Auto Focus Area",
				'type' => "Special",
				'special' => "byte 1: AF Mode (00 = Single Area, 01 = Dynamic Area, 02 = Closest Subject); byte 2: AF Area Selected (00 = Centre, 01 = Top, 02 = Bottom, 03 = Left, 04 = Right); byte 3: unknown, always zero; byte 4: properly focused area bits (bit 0 = Centre, bit 1 = Top, bit 2 = Bottom, bit 3 = Left, bit 4 = Right); all zeros may mean manual focus",
			],
			137 => [
				'name' => "Bracketing & Shooting Mode",
				'type' => "Special",
				'special' => "bits 0-1: shooting mode (0 = Single Frame, 1 = Continuous, 2 = Self Timer, 3 = Remote?); bit 4: AE/Flash bracketing on; bit 6: White Balance bracketing on",
			],
			141 => [
				'name' => "Colour Mode",
				'type' => "String",
				'special' => "1a = Portrait sRGB, 2 = Adobe RGB, 3a = Landscape sRGB",
			],
			143 => [
				'name' => "Scene Mode?",
				'type' => "Numeric",
			],
			144 => [
				'name' => "Lighting Type",
				'type' => "String",
			],
			146 => [
				'name' => "Hue Adjustment",
				'type' => "Numeric",
				'units' => "Degrees",
			],
			148 => [
				'name' => "Saturation?",
				'type' => "Lookup",
				'lookup' => [
					-3 => "Black and White",
					-2 => "-2",
					-1 => "-1",
					0 => "Normal",
					1 => "+1",
					2 => "+2",
				],
			],
			149 => [
				'name' => "Noise Reduction",
				'type' => "String",
			],
			167 => [
				'name' => "Total Number of Shutter Releases for Camera",
				'type' => "Numeric",
				'units' => "Shutter Releases",
			],
			169 => [
				'name' => "Image optimisation",
				'type' => "String",
			],
			170 => [
				'name' => "Saturation",
				'type' => "String",
			],
			171 => [
				'name' => "Digital Vari-Program",
				'type' => "String",
			],
		],
		'Olympus' => [
			0 => [
				'name' => "Makernote Version",
				'type' => "String",
			],
			1 => [
				'name' => "Camera Settings",
				'type' => "Special",
				'special' => "Minolta camera settings: sequence of 4-byte big-endian values decoded via the MinoltaCameraSettings table (1-based index)",
			],
			3 => [
				'name' => "Camera Settings",
				'type' => "Special",
				'special' => "Minolta camera settings: sequence of 4-byte big-endian values decoded via the MinoltaCameraSettings table (1-based index)",
			],
			64 => [
				'name' => "Compressed Image Size",
				'type' => "Numeric",
				'units' => "Bytes",
			],
			129 => [
				'name' => "Minolta Thumbnail",
				'type' => "Special",
				'special' => "Minolta embedded thumbnail offset",
			],
			136 => [
				'name' => "Minolta Thumbnail",
				'type' => "Special",
				'special' => "Minolta embedded thumbnail offset",
			],
			137 => [
				'name' => "Minolta Thumbnail Length",
				'type' => "Numeric",
				'units' => "bytes",
			],
			257 => [
				'name' => "Colour Mode",
				'type' => "Lookup",
				'lookup' => [
					0 => "Natural Colour",
					1 => "Black & White",
					2 => "Vivid colour",
					3 => "Solarization",
					4 => "AdobeRGB",
				],
			],
			258 => [
				'name' => "Image Quality",
				'type' => "Lookup",
				'lookup' => [
					0 => "Raw",
					1 => "Super Fine",
					2 => "Fine",
					3 => "Standard",
					4 => "Extra Fine",
				],
			],
			259 => [
				'name' => "Image Quality?",
				'type' => "Lookup",
				'lookup' => [
					0 => "Raw",
					1 => "Super Fine",
					2 => "Fine",
					3 => "Standard",
					4 => "Extra Fine",
				],
			],
			512 => [
				'name' => "Special Mode",
				'type' => "Special",
				'special' => "Three longs: mode (0 = Normal, 2 = Fast, 3 = Panorama); sequence number; panorama direction (1 = Left to Right, 2 = Right to Left, 3 = Bottom to Top, 4 = Top to Bottom)",
			],
			513 => [
				'name' => "JPEG Quality",
				'type' => "Lookup",
				'lookup' => [
					1 => "Standard Quality",
					2 => "High Quality",
					3 => "Super High Quality",
				],
			],
			514 => [
				'name' => "Macro",
				'type' => "Lookup",
				'lookup' => [
					0 => "Normal (Not Macro)",
					1 => "Macro",
				],
			],
			516 => [
				'name' => "Digital Zoom",
				'type' => "Numeric",
				'units' => "x Digital Zoom, (0 or 1 = normal)",
			],
			519 => [
				'name' => "Firmware Version",
				'type' => "String",
			],
			520 => [
				'name' => "Picture Info Data",
				'type' => "String",
			],
			521 => [
				'name' => "Camera ID",
				'type' => "String",
			],
			523 => [
				'name' => "Image Width",
				'type' => "Numeric",
				'units' => "pixels",
			],
			524 => [
				'name' => "Image Height",
				'type' => "Numeric",
				'units' => "pixels",
			],
			525 => [
				'name' => "Original Manufacturer Model?",
				'type' => "String",
			],
			3584 => [
				'name' => "Print Image Matching Info",
				'type' => "PIM",
			],
			4100 => [
				'name' => "Flash Mode",
				'type' => "Numeric",
			],
			4102 => [
				'name' => "Bracket",
				'type' => "Numeric",
			],
			4107 => [
				'name' => "Focus Mode",
				'type' => "Numeric",
			],
			4108 => [
				'name' => "Focus Distance",
				'type' => "Numeric",
			],
			4109 => [
				'name' => "Zoom",
				'type' => "Numeric",
			],
			4110 => [
				'name' => "Macro Focus",
				'type' => "Numeric",
			],
			4111 => [
				'name' => "Sharpness",
				'type' => "Numeric",
			],
			4113 => [
				'name' => "Colour Matrix",
				'type' => "Numeric",
			],
			4114 => [
				'name' => "Black Level",
				'type' => "Numeric",
			],
			4117 => [
				'name' => "White Balance",
				'type' => "Numeric",
			],
			4119 => [
				'name' => "Red Bias",
				'type' => "Numeric",
			],
			4120 => [
				'name' => "Blue Bias",
				'type' => "Numeric",
			],
			4122 => [
				'name' => "Serial Number",
				'type' => "Numeric",
			],
			4131 => [
				'name' => "Flash Bias",
				'type' => "Numeric",
			],
			4137 => [
				'name' => "Contrast",
				'type' => "Numeric",
			],
			4138 => [
				'name' => "Sharpness Factor",
				'type' => "Numeric",
			],
			4139 => [
				'name' => "Colour Control",
				'type' => "Numeric",
			],
			4140 => [
				'name' => "Valid Bits",
				'type' => "Numeric",
			],
			4141 => [
				'name' => "Coring Filter",
				'type' => "Numeric",
			],
			4142 => [
				'name' => "Final Width",
				'type' => "Numeric",
			],
			4143 => [
				'name' => "Final Height",
				'type' => "Numeric",
			],
			4148 => [
				'name' => "Compression Ratio",
				'type' => "Numeric",
			],
		],
		'Panasonic' => [
			1 => [
				'name' => "Quality Mode",
				'type' => "Numeric",
			],
			2 => [
				'name' => "Version",
				'type' => "String",
			],
			28 => [
				'name' => "Macro Mode",
				'type' => "Lookup",
				'lookup' => [
					1 => "On",
					2 => "Off",
				],
			],
			31 => [
				'name' => "Record Mode",
				'type' => "Lookup",
				'lookup' => [
					1 => "Normal",
					2 => "Portrait",
					9 => "Macro",
				],
			],
			3584 => [
				'name' => "Print Image Matching Info",
				'type' => "PIM",
			],
		],
		'Pentax' => [
			1 => [
				'name' => "Capture Mode",
				'type' => "Lookup",
				'lookup' => [
					0 => "Auto",
					1 => "Night-scene",
					2 => "Manual",
					4 => "Multiple",
				],
			],
			2 => [
				'name' => "Quality Level",
				'type' => "Lookup",
				'lookup' => [
					0 => "Good",
					1 => "Better",
					2 => "Best",
				],
			],
			3 => [
				'name' => "Focus Mode",
				'type' => "Lookup",
				'lookup' => [
					2 => "Custom",
					3 => "Auto",
				],
			],
			4 => [
				'name' => "Flash Mode",
				'type' => "Lookup",
				'lookup' => [
					1 => "Auto",
					2 => "Flash on",
					4 => "Flash off",
					6 => "Red-eye Reduction",
				],
			],
			7 => [
				'name' => "White Balance",
				'type' => "Lookup",
				'lookup' => [
					0 => "Auto",
					1 => "Daylight",
					2 => "Shade",
					3 => "Tungsten",
					4 => "Fluorescent",
					5 => "Manual",
				],
			],
			10 => [
				'name' => "Digital Zoom",
				'type' => "Numeric",
				'units' => "(0 = Off)",
			],
			11 => [
				'name' => "Sharpness",
				'type' => "Lookup",
				'lookup' => [
					0 => "Normal",
					1 => "Soft",
					2 => "Hard",
				],
			],
			12 => [
				'name' => "Contrast",
				'type' => "Lookup",
				'lookup' => [
					0 => "Normal",
					1 => "Low",
					2 => "High",
				],
			],
			13 => [
				'name' => "Saturation",
				'type' => "Lookup",
				'lookup' => [
					0 => "Normal",
					1 => "Low",
					2 => "High",
				],
			],
			20 => [
				'name' => "ISO Speed",
				'type' => "Lookup",
				'lookup' => [
					10 => "100",
					16 => "200",
					100 => "100",
					200 => "200",
				],
			],
			23 => [
				'name' => "Colour",
				'type' => "Lookup",
				'lookup' => [
					1 => "Normal",
					2 => "Black & White",
					3 => "Sepia",
				],
			],
			3584 => [
				'name' => "Print Image Matching Info",
				'type' => "PIM",
			],
			4096 => [
				'name' => "Time Zone",
				'type' => "String",
			],
			4097 => [
				'name' => "Daylight Savings",
				'type' => "String",
			],
		],
		'Ricoh' => [
			1 => [
				'name' => "Makernote Data Type",
				'type' => "String",
			],
			2 => [
				'name' => "Version",
				'type' => "Special",
				'special' => "Version bytes; displayed as text plus hex",
			],
			3584 => [
				'name' => "Print Image Matching Info",
				'type' => "PIM",
			],
			8193 => [
				'name' => "Ricoh Camera Info Makernote Sub-IFD",
				'type' => "SubIFD",
				'special' => "Sub-IFD with 19-byte header \"[Ricoh Camera Info]\" plus 1 unknown byte; big-endian, no next-IFD pointer; uses the RicohSubIFD tag group",
			],
		],
		'RicohSubIFD' => [
		],
		'Sony' => [
			3584 => [
				'name' => "Print Image Matching Info",
				'type' => "PIM",
			],
		],
	];

	/**
	 * @var array The maker container facts: maker => makeMatch substrings, variants
	 *   (signatures, signatureLength, ifdOffset/ifdOffsetPointer, byteOrder,
	 *   localOffsets/offsetsRelativeToMakernote, hasNextIfdPointer, embeddedTiffHeader,
	 *   tagGroup, thumbnailTags, subIfd), and undecodableSignatures.
	 */
	public const Headers = [
		'Agfa' => [
			'makeMatch' => [
				0 => "Agfa",
			],
			'variants' => [
				'Agfa' => [
					'signatures' => [
						0 => "AGFA \x00\x01",
					],
					'signatureLength' => 7,
					'ifdOffset' => 8,
					'byteOrder' => null,
					'localOffsets' => false,
					'hasNextIfdPointer' => true,
					'embeddedTiffHeader' => false,
					'tagGroup' => "Olympus",
				],
			],
		],
		'Canon' => [
			'makeMatch' => [
				0 => "Canon",
			],
			'variants' => [
				'Canon' => [
					'signatures' => [
					],
					'signatureLength' => 0,
					'ifdOffset' => 0,
					'byteOrder' => null,
					'localOffsets' => false,
					'hasNextIfdPointer' => true,
					'embeddedTiffHeader' => false,
					'tagGroup' => "Canon",
				],
			],
		],
		'Casio' => [
			'makeMatch' => [
				0 => "Casio",
			],
			'variants' => [
				'Casio Type 2' => [
					'signatures' => [
						0 => "QVC\x00\x00\x00",
					],
					'signatureLength' => 6,
					'ifdOffset' => 6,
					'byteOrder' => "MM",
					'localOffsets' => false,
					'hasNextIfdPointer' => true,
					'embeddedTiffHeader' => false,
					'tagGroup' => "Casio Type 2",
					'thumbnailTags' => [
						0 => 4,
						1 => 8192,
					],
				],
				'Casio Type 1' => [
					'signatures' => [
					],
					'signatureLength' => 0,
					'ifdOffset' => 0,
					'byteOrder' => "MM",
					'localOffsets' => false,
					'hasNextIfdPointer' => true,
					'embeddedTiffHeader' => false,
					'tagGroup' => "Casio Type 1",
				],
			],
		],
		'Epson' => [
			'makeMatch' => [
				0 => "Epson",
			],
			'variants' => [
				'Epson' => [
					'signatures' => [
						0 => "EPSON\x00\x01\x00",
					],
					'signatureLength' => 8,
					'ifdOffset' => 8,
					'byteOrder' => null,
					'localOffsets' => false,
					'hasNextIfdPointer' => true,
					'embeddedTiffHeader' => false,
					'tagGroup' => "Olympus",
				],
			],
		],
		'Fujifilm' => [
			'makeMatch' => [
				0 => "Fuji",
				1 => "Nikon",
			],
			'variants' => [
				'Fujifilm' => [
					'signatures' => [
						0 => "FUJIFILM",
					],
					'signatureLength' => 8,
					'ifdOffset' => null,
					'ifdOffsetPointer' => [
						'offset' => 8,
						'length' => 4,
						'byteOrder' => "II",
					],
					'byteOrder' => "II",
					'localOffsets' => false,
					'offsetsRelativeToMakernote' => true,
					'hasNextIfdPointer' => true,
					'embeddedTiffHeader' => false,
					'tagGroup' => "Fujifilm",
					'note' => "Also used by the Nikon Coolpix 775",
				],
			],
		],
		'Konica Minolta' => [
			'makeMatch' => [
				0 => "Konica",
				1 => "Minolta",
			],
			'undecodableSignatures' => [
				0 => "MLY",
				1 => "KC",
				2 => "+M+M+M+M",
				3 => "MINOL",
			],
			'variants' => [
				'Minolta' => [
					'signatures' => [
					],
					'signatureLength' => 0,
					'ifdOffset' => 0,
					'byteOrder' => null,
					'localOffsets' => false,
					'hasNextIfdPointer' => true,
					'embeddedTiffHeader' => false,
					'tagGroup' => "Olympus",
					'thumbnailTags' => [
						0 => 136,
						1 => 129,
					],
				],
			],
		],
		'Kyocera' => [
			'makeMatch' => [
				0 => "Contax",
				1 => "Kyocera",
			],
			'variants' => [
				'Kyocera' => [
					'signatures' => [
						0 => "KYOCERA            \x00\x00\x00",
					],
					'signatureLength' => 22,
					'ifdOffset' => 22,
					'byteOrder' => null,
					'localOffsets' => true,
					'hasNextIfdPointer' => false,
					'embeddedTiffHeader' => false,
					'tagGroup' => "Kyocera",
				],
			],
		],
		'Nikon' => [
			'makeMatch' => [
				0 => "Nikon",
			],
			'variants' => [
				'Nikon Type 1' => [
					'signatures' => [
						0 => "Nikon\x00\x01\x00",
					],
					'signatureLength' => 8,
					'ifdOffset' => 8,
					'byteOrder' => null,
					'localOffsets' => false,
					'hasNextIfdPointer' => true,
					'embeddedTiffHeader' => false,
					'tagGroup' => "Nikon Type 1",
				],
				'Nikon Type 3' => [
					'signatures' => [
						0 => "Nikon\x00\x02\x10\x00\x00",
						1 => "Nikon\x00\x02\x00\x00\x00",
					],
					'signatureLength' => 10,
					'ifdOffset' => 10,
					'byteOrder' => null,
					'localOffsets' => false,
					'hasNextIfdPointer' => true,
					'embeddedTiffHeader' => true,
					'tagGroup' => "Nikon Type 3",
				],
				'Nikon Type 2' => [
					'signatures' => [
					],
					'signatureLength' => 0,
					'ifdOffset' => 0,
					'byteOrder' => null,
					'localOffsets' => false,
					'hasNextIfdPointer' => true,
					'embeddedTiffHeader' => false,
					'tagGroup' => "Nikon Type 3",
				],
			],
			'note' => "A makernote starting with \"FUJIFILM\" is handled as a Fujifilm makernote (Nikon Coolpix 775)",
		],
		'Olympus' => [
			'makeMatch' => [
				0 => "Olympus",
			],
			'variants' => [
				'Olympus' => [
					'signatures' => [
						0 => "OLYMP\x00\x01",
						1 => "OLYMP\x00\x02",
					],
					'signatureLength' => 7,
					'ifdOffset' => 8,
					'byteOrder' => null,
					'localOffsets' => false,
					'hasNextIfdPointer' => true,
					'embeddedTiffHeader' => false,
					'tagGroup' => "Olympus",
					'thumbnailTags' => [
						0 => 136,
						1 => 129,
					],
				],
			],
		],
		'Panasonic' => [
			'makeMatch' => [
				0 => "Panasonic",
			],
			'variants' => [
				'Panasonic' => [
					'signatures' => [
						0 => "Panasonic\x00\x00\x00",
					],
					'signatureLength' => 12,
					'ifdOffset' => 12,
					'byteOrder' => null,
					'localOffsets' => false,
					'hasNextIfdPointer' => false,
					'embeddedTiffHeader' => false,
					'tagGroup' => "Panasonic",
				],
				'Panasonic Empty Makernote' => [
					'signatures' => [
						0 => "MKED",
					],
					'signatureLength' => 4,
					'ifdOffset' => null,
					'byteOrder' => null,
					'tagGroup' => null,
				],
			],
		],
		'Pentax' => [
			'makeMatch' => [
				0 => "Pentax",
				1 => "Asahi",
			],
			'variants' => [
				'Pentax Type 2' => [
					'signatures' => [
						0 => "AOC\x00",
					],
					'signatureLength' => 4,
					'ifdOffset' => 6,
					'byteOrder' => null,
					'localOffsets' => false,
					'hasNextIfdPointer' => true,
					'embeddedTiffHeader' => false,
					'tagGroup' => "Casio Type 2",
					'note' => "Format documented as having no next-IFD pointer and offsets relative to the current IFD tag; the source parser nonetheless uses standard offsets",
				],
				'Pentax Type 1' => [
					'signatures' => [
					],
					'signatureLength' => 0,
					'ifdOffset' => 0,
					'byteOrder' => null,
					'localOffsets' => false,
					'hasNextIfdPointer' => true,
					'embeddedTiffHeader' => false,
					'tagGroup' => "Pentax",
					'note' => "Format documented as having no next-IFD pointer and offsets relative to the current IFD tag; the source parser nonetheless uses standard offsets",
				],
			],
		],
		'Ricoh' => [
			'makeMatch' => [
				0 => "Ricoh",
			],
			'variants' => [
				'Ricoh Text' => [
					'signatures' => [
						0 => "Rv",
						1 => "Rev",
					],
					'signatureLength' => null,
					'ifdOffset' => null,
					'byteOrder' => null,
					'tagGroup' => null,
					'note' => "Plain text makernote; fields separated by semicolons",
				],
				'Ricoh Empty Makernote' => [
					'signatures' => [
					],
					'signatureLength' => null,
					'ifdOffset' => null,
					'byteOrder' => null,
					'tagGroup' => null,
					'note' => "Entire makernote data filled with 0x00 bytes",
				],
				'Ricoh' => [
					'signatures' => [
						0 => "Ricoh",
						1 => "RICOH",
					],
					'signatureLength' => 5,
					'ifdOffset' => 8,
					'byteOrder' => "MM",
					'localOffsets' => false,
					'hasNextIfdPointer' => true,
					'embeddedTiffHeader' => false,
					'tagGroup' => "Ricoh",
					'subIfd' => [
						'tag' => 8193,
						'signatures' => [
							0 => "[Ricoh Camera Info]",
						],
						'signatureLength' => 19,
						'ifdOffset' => 20,
						'byteOrder' => "MM",
						'localOffsets' => false,
						'hasNextIfdPointer' => false,
						'tagGroup' => "RicohSubIFD",
					],
				],
			],
		],
		'Sony' => [
			'makeMatch' => [
				0 => "Sony",
			],
			'variants' => [
				'Sony' => [
					'signatures' => [
						0 => "SONY CAM \x00\x00\x00",
						1 => "SONY DSC \x00\x00\x00",
					],
					'signatureLength' => 12,
					'ifdOffset' => 12,
					'byteOrder' => null,
					'localOffsets' => false,
					'hasNextIfdPointer' => false,
					'embeddedTiffHeader' => false,
					'tagGroup' => "Sony",
				],
			],
		],
	];
}
