<?php

/**
 * MakernoteTables class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\Meta\Makernote;

/**
 * MakernoteTables class.
 *
 * The positional decode tables of the makers that pack camera settings into one
 * multi-element tag: the Canon Camera Settings blocks 1 and 2 (tags 0x0001/0x0004),
 * the Canon Custom Functions (tag 0x000F), and the Minolta camera-settings block.
 * Each table maps an element index to its setting name, its value lookup table, and
 * where applicable a conversion note ({@see \Belisoful\Image\Meta\Makernote\CanonMakernote}
 * and {@see \Belisoful\Image\Meta\Makernote\KonicaMinoltaMakernote} apply them).
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class MakernoteTables
{
	/** @var array The Canon Camera Settings 1 (tag 0x0001) positional definitions. */
	public const CanonCameraSettings1 = [
		0 => [
			'name' => "Number of Bytes in Tag",
			'special' => "Size field; not displayed",
		],
		1 => [
			'name' => "Macro Mode",
			'lookup' => [
				1 => "Macro",
				2 => "Normal ( Not Macro )",
			],
		],
		2 => [
			'name' => "Self Timer Length",
			'special' => "0 = self timer not used; otherwise length = value / 10 seconds",
		],
		3 => [
			'name' => "Quality",
			'lookup' => [
				2 => "Normal",
				3 => "Fine",
				5 => "Superfine",
			],
		],
		4 => [
			'name' => "Flash Mode",
			'lookup' => [
				0 => "Flash Not Fired",
				1 => "Auto",
				2 => "On",
				3 => "Red Eye Reduction",
				4 => "Slow Synchro",
				5 => "Auto + Red Eye Reduction",
				6 => "On + Red Eye Reduction",
				16 => "External Flash",
			],
		],
		5 => [
			'name' => "Continuous drive mode",
			'lookup' => [
				0 => "Single Frame or Timer Mode",
				1 => "Continuous",
			],
		],
		7 => [
			'name' => "Focus Mode",
			'lookup' => [
				0 => "One-Shot",
				1 => "AI Servo",
				2 => "AI Focus",
				3 => "Manual Focus",
				4 => "Single",
				5 => "Continuous",
				6 => "Manual Focus",
			],
		],
		10 => [
			'name' => "Image Size",
			'lookup' => [
				0 => "Large",
				1 => "Medium",
				2 => "Small",
			],
		],
		11 => [
			'name' => "Easy shooting Mode",
			'lookup' => [
				0 => "Full Auto",
				1 => "Manual",
				2 => "Landscape",
				3 => "Fast Shutter",
				4 => "Slow Shutter",
				5 => "Night",
				6 => "Black & White",
				7 => "Sepia",
				8 => "Portrait",
				9 => "Sports",
				10 => "Macro / Close-Up",
				11 => "Pan Focus",
			],
		],
		12 => [
			'name' => "Digital Zoom",
			'lookup' => [
				0 => "No Digital Zoom",
				1 => "2x",
				2 => "4x",
			],
		],
		13 => [
			'name' => "Contrast",
			'lookup' => [
				0 => "Normal",
				1 => "High",
				65535 => "Low",
			],
		],
		14 => [
			'name' => "Saturation",
			'lookup' => [
				0 => "Normal",
				1 => "High",
				65535 => "Low",
			],
		],
		15 => [
			'name' => "Sharpness",
			'lookup' => [
				0 => "Normal",
				1 => "High",
				65535 => "Low",
			],
		],
		16 => [
			'name' => "ISO Speed",
			'lookup' => [
				0 => "Check ISOSpeedRatings EXIF tag for ISO Speed",
				15 => "Auto ISO",
				16 => "ISO 50",
				17 => "ISO 100",
				18 => "ISO 200",
				19 => "ISO 400",
			],
		],
		17 => [
			'name' => "Metering Mode",
			'lookup' => [
				3 => "Evaluative",
				4 => "Partial",
				5 => "Centre Weighted",
			],
		],
		18 => [
			'name' => "Focus Type",
			'lookup' => [
				0 => "Manual",
				1 => "Auto",
				3 => "Close-up (Macro)",
				8 => "Locked (Pan Mode)",
			],
		],
		19 => [
			'name' => "Auto Focus Point Selected",
			'lookup' => [
				12288 => "None (Manual Focus)",
				12289 => "Auto Selected",
				12290 => "Right",
				12291 => "Centre",
				12292 => "Left",
			],
		],
		20 => [
			'name' => "Exposure Mode",
			'lookup' => [
				0 => "Easy Shooting (See Easy Shooting Mode)",
				1 => "Program",
				2 => "Tv-Priority",
				3 => "Av-Priority",
				4 => "Manual",
				5 => "A-DEP",
			],
		],
		23 => [
			'name' => "Maximum Focal Length of Lens",
			'special' => "Focal length in mm = value / value at offset 25 (focal length units per mm)",
		],
		24 => [
			'name' => "Minimum Focal Length of Lens",
			'special' => "Focal length in mm = value / value at offset 25 (focal length units per mm)",
		],
		25 => [
			'name' => "Focal Length Units per mm",
			'special' => "Divisor for offsets 23 and 24; not displayed",
		],
		28 => [
			'name' => "Flash Activity",
			'lookup' => [
				0 => "Flash Did Not Fire",
				1 => "Flash Fired",
			],
		],
		29 => [
			'name' => "Flash Details",
			'special' => "Bitmask",
			'bits' => [
				16384 => "External E-TTL Flash",
				8192 => "Internal Flash",
				2048 => "Flash FP sync used",
				128 => "Second (Rear) curtain flash sync used",
				8 => "Flash FP sync enabled",
			],
		],
		32 => [
			'name' => "Focus Mode",
			'lookup' => [
				0 => "Focus Mode: Single",
				1 => "Focus Mode: Continuous",
			],
		],
	];

	/** @var array The Canon Camera Settings 2 (tag 0x0004) positional definitions. */
	public const CanonCameraSettings2 = [
		0 => [
			'name' => "Number of Bytes in Tag",
			'special' => "Size field; not displayed",
		],
		7 => [
			'name' => "White Balance",
			'lookup' => [
				0 => "Auto",
				1 => "Sunny",
				2 => "Cloudy",
				3 => "Tungsten",
				4 => "Flourescent",
				5 => "Flash",
				6 => "Custom",
			],
		],
		9 => [
			'name' => "Sequence Number in a continuous burst",
			'special' => "Plain numeric value",
		],
		14 => [
			'name' => "Focus Points",
			'special' => "Number of focus points available = (value & 0xF000) >> 12",
			'bits' => [
				4 => "Left Focus Point Used",
				2 => "Centre Focus Point Used",
				1 => "Right Focus Point Used",
			],
		],
		15 => [
			'name' => "Flash Bias",
			'lookup' => [
				65472 => "-2 EV",
				65484 => "-1.67 EV",
				65488 => "-1.5 EV",
				65492 => "-1.33 EV",
				65504 => "-1 EV",
				65516 => "-0.67 EV",
				65520 => "-0.5 EV",
				65524 => "-0.33 EV",
				0 => "0 EV",
				12 => "0.33 EV",
				16 => "0.5 EV",
				20 => "0.67 EV",
				32 => "1 EV",
				44 => "1.33 EV",
				48 => "1.5 EV",
				52 => "1.67 EV",
				64 => "2 EV",
			],
		],
		19 => [
			'name' => "Subject Distance",
			'special' => "Units either mm or cm",
		],
	];

	/** @var array The Canon Custom Functions (tag 0x000F) definitions. */
	public const CanonCustomFunctions = [
		1 => [
			'name' => "Long Exposure Noise Reduction",
			'lookup' => [
				0 => "Off",
				1 => "On",
			],
		],
		2 => [
			'name' => "Shutter/Auto Exposure-lock buttons",
			'lookup' => [
				0 => "AF/AE lock",
				1 => "AE lock/AF",
				2 => "AF/AF lock",
				3 => "AE+release/AE+AF",
			],
		],
		3 => [
			'name' => "Mirror lockup",
			'lookup' => [
				0 => "Disable",
				1 => "Enable",
			],
		],
		4 => [
			'name' => "Tv/Av and exposure level",
			'lookup' => [
				0 => "1/2 stop",
				1 => "1/3 stop",
			],
		],
		5 => [
			'name' => "AF-assist light",
			'lookup' => [
				0 => "On (Auto)",
				1 => "Off",
			],
		],
		6 => [
			'name' => "Shutter speed in Av mode",
			'lookup' => [
				0 => "Automatic",
				1 => "1/200 (fixed)",
			],
		],
		7 => [
			'name' => "Auto-Exposure Bracketting sequence/auto cancellation",
			'lookup' => [
				0 => "0,-,+ / Enabled",
				1 => "0,-,+ / Disabled",
				2 => "-,0,+ / Enabled",
				3 => "-,0,+ / Disabled",
			],
		],
		8 => [
			'name' => "Shutter Curtain Sync",
			'lookup' => [
				0 => "1st Curtain Sync",
				1 => "2nd Curtain Sync",
			],
		],
		9 => [
			'name' => "Lens Auto-Focus stop button Function Switch",
			'lookup' => [
				0 => "AF stop",
				1 => "Operate AF",
				2 => "Lock AE and start timer",
			],
		],
		10 => [
			'name' => "Auto reduction of fill flash",
			'lookup' => [
				0 => "Enable",
				1 => "Disable",
			],
		],
		11 => [
			'name' => "Menu button return position",
			'lookup' => [
				0 => "Top",
				1 => "Previous (volatile)",
				2 => "Previous",
			],
		],
		12 => [
			'name' => "SET button function when shooting",
			'lookup' => [
				0 => "Not Assigned",
				1 => "Change Quality",
				2 => "Change ISO Speed",
				3 => "Select Parameters",
			],
		],
		13 => [
			'name' => "Sensor cleaning",
			'lookup' => [
				0 => "Disable",
				1 => "Enable",
			],
		],
	];

	/** @var array The Minolta camera-settings block positional definitions. */
	public const MinoltaCameraSettings = [
		2 => [
			'name' => "Exposure Mode",
			'type' => "Lookup",
			'lookup' => [
				0 => "P",
				1 => "A",
				2 => "S",
				3 => "M",
			],
		],
		3 => [
			'name' => "Flash Mode",
			'type' => "Lookup",
			'lookup' => [
				0 => "Normal",
				1 => "Red-eye reduction",
				2 => "Rear flash sync",
				3 => "Wireless",
			],
		],
		4 => [
			'name' => "White Balance",
			'type' => "Lookup",
			'lookup' => [
				0 => "Auto",
				1 => "Daylight",
				2 => "Cloudy",
				3 => "Tungsten",
				5 => "Custom",
				7 => "Fluorescent",
				8 => "Fluorescent 2",
				11 => "Custom 2",
				12 => "Custom 3",
			],
		],
		5 => [
			'name' => "Image Size",
			'type' => "Lookup",
			'lookup' => [
				0 => "2560 x 1920 (2048x1536 - DiMAGE 5 only)",
				1 => "1600 x 1200",
				2 => "1280 x 960",
				3 => "640 x 480",
			],
		],
		6 => [
			'name' => "Image Quality",
			'type' => "Lookup",
			'lookup' => [
				0 => "Raw",
				1 => "Super Fine",
				2 => "Fine",
				3 => "Standard",
				4 => "Economy",
				5 => "Extra Fine",
			],
		],
		7 => [
			'name' => "Shooting Mode",
			'type' => "Lookup",
			'lookup' => [
				0 => "Single",
				1 => "Continuous",
				2 => "Self-timer",
				4 => "Bracketing",
				5 => "Interval",
				6 => "UHS Continuous",
				7 => "HS Continuous",
			],
		],
		8 => [
			'name' => "Metering Mode",
			'type' => "Lookup",
			'lookup' => [
				0 => "Multi-Segment",
				1 => "Centre Weighted",
				2 => "Spot",
			],
		],
		9 => [
			'name' => "Apex Film Speed Value",
			'type' => "Special",
			'special' => "Speed value = x/8 - 1; ISO = (2^(x/8 - 1)) * 3.125",
		],
		10 => [
			'name' => "Apex Shutter Speed Time Value",
			'type' => "Special",
			'units' => "Seconds?",
			'special' => "Time value = x/8 - 6; shutter speed = 2^((48 - x)/8) seconds; due to rounding, x = 8 should display as 30 seconds",
		],
		11 => [
			'name' => "Apex Aperture Value",
			'type' => "Special",
			'special' => "Aperture value = x/8 - 1; F stop = 2^(x/16 - 0.5)",
		],
		12 => [
			'name' => "Macro Mode",
			'type' => "Lookup",
			'lookup' => [
				0 => "Off",
				1 => "On",
			],
		],
		13 => [
			'name' => "Digital Zoom",
			'type' => "Lookup",
			'lookup' => [
				0 => "Off",
				1 => "Electronic magnification was used",
				2 => "Digital zoom 2x",
			],
		],
		14 => [
			'name' => "Exposure Compensation",
			'type' => "Special",
			'units' => "EV",
			'special' => "EV = x/3 - 2",
		],
		15 => [
			'name' => "Bracket Step",
			'type' => "Lookup",
			'lookup' => [
				0 => "1/3 EV",
				1 => "2/3 EV",
				2 => "1 EV",
			],
		],
		17 => [
			'name' => "Interval Length",
			'type' => "Special",
			'units' => "Min",
			'special' => "Interval = x + 1 minutes (used with interval mode)",
		],
		18 => [
			'name' => "Interval Number",
			'type' => "Numeric",
			'units' => "frames",
		],
		19 => [
			'name' => "Focal Length",
			'type' => "Special",
			'units' => "mm",
			'special' => "x / 256 is real focal length in mm; x / 256 * 3.9333 is 35-mm equivalent",
		],
		20 => [
			'name' => "Focus Distance",
			'type' => "Numeric",
			'units' => "mm  ( 0 = Infinity)",
		],
		21 => [
			'name' => "Flash Fired",
			'type' => "Lookup",
			'lookup' => [
				0 => "No",
				1 => "Yes",
			],
		],
		22 => [
			'name' => "Date",
			'type' => "Special",
			'special' => "yyyymmdd: year = x/65536, month = x/256 - (x/65536)*256, day = x % 256",
		],
		23 => [
			'name' => "Time",
			'type' => "Special",
			'special' => "hhmmss: hour = x/65536, minute = x/256 - (x/65536)*256, second = x % 256",
		],
		24 => [
			'name' => "Max Aperture at this focal length",
			'type' => "Special",
			'special' => "F number = 2^(x/16 - 0.5)",
		],
		27 => [
			'name' => "File Number Memory",
			'type' => "Lookup",
			'lookup' => [
				0 => "Off",
				1 => "On",
			],
		],
		28 => [
			'name' => "Last File Number",
			'type' => "Numeric",
			'units' => "( 0 = File Number Memory is Off)",
		],
		29 => [
			'name' => "White Balance Red",
			'type' => "Special",
			'special' => "x/256 = red white balance coefficient used for this picture",
		],
		30 => [
			'name' => "White Balance Green",
			'type' => "Special",
			'special' => "x/256 = green white balance coefficient used for this picture",
		],
		31 => [
			'name' => "White Balance Blue",
			'type' => "Special",
			'special' => "x/256 = blue white balance coefficient used for this picture",
		],
		32 => [
			'name' => "Saturation",
			'type' => "Special",
			'special' => "Saturation = x - 3",
		],
		33 => [
			'name' => "Contrast",
			'type' => "Special",
			'special' => "Contrast = x - 3",
		],
		34 => [
			'name' => "Sharpness",
			'type' => "Lookup",
			'lookup' => [
				0 => "Hard",
				1 => "Normal",
				2 => "Soft",
			],
		],
		35 => [
			'name' => "Subject Program",
			'type' => "Lookup",
			'lookup' => [
				0 => "none",
				1 => "portrait",
				2 => "text",
				3 => "night portrait",
				4 => "sunset",
				5 => "sports action",
			],
		],
		36 => [
			'name' => "Flash Compensation",
			'type' => "Special",
			'units' => "EV",
			'special' => "Flash compensation in EV = (x - 6) / 3",
		],
		37 => [
			'name' => "ISO Setting",
			'type' => "Lookup",
			'lookup' => [
				0 => "100",
				1 => "200",
				2 => "400",
				3 => "800",
				4 => "auto",
				5 => "64",
			],
		],
		38 => [
			'name' => "Camera Model",
			'type' => "Lookup",
			'lookup' => [
				0 => "DiMAGE 7",
				1 => "DiMAGE 5",
				2 => "DiMAGE S304",
				3 => "DiMAGE S404",
				4 => "DiMAGE 7i",
				5 => "DiMAGE 7Hi",
				6 => "DiMAGE A1",
				7 => "DiMAGE S414",
			],
		],
		39 => [
			'name' => "Interval Mode",
			'type' => "Lookup",
			'lookup' => [
				0 => "Still Image",
				1 => "Time-lapse Movie",
			],
		],
		40 => [
			'name' => "Folder Name",
			'type' => "Lookup",
			'lookup' => [
				0 => "Standard Form",
				1 => "Data Form",
			],
		],
		41 => [
			'name' => "Color Mode",
			'type' => "Lookup",
			'lookup' => [
				0 => "Natural Color",
				1 => "Black & White",
				2 => "Vivid Color",
				3 => "Solarization",
				4 => "Adobe RGB",
			],
		],
		42 => [
			'name' => "Color Filter",
			'type' => "Special",
			'special' => "Color filter = x - 3",
		],
		43 => [
			'name' => "Black & White Filter",
			'type' => "Numeric",
		],
		44 => [
			'name' => "Internal Flash",
			'type' => "Lookup",
			'lookup' => [
				0 => "Not Fired",
				1 => "Fired",
			],
		],
		45 => [
			'name' => "Apex Brightness Value",
			'type' => "Special",
			'special' => "Brightness value = x/8 - 6",
		],
		46 => [
			'name' => "Spot Focus Point X Coordinate",
			'type' => "Numeric",
		],
		47 => [
			'name' => "Spot Focus Point Y Coordinate",
			'type' => "Numeric",
		],
		48 => [
			'name' => "Wide Focus Zone",
			'type' => "Lookup",
			'lookup' => [
				0 => "No Zone or AF Failed",
				1 => "Center Zone (Horizontal Orientation)",
				2 => "Center Zone (Vertical Orientation)",
				3 => "Left Zone",
				4 => "Right Zone",
			],
		],
		49 => [
			'name' => "Focus Mode",
			'type' => "Lookup",
			'lookup' => [
				0 => "Auto Focus",
				1 => "Manual Focus",
			],
		],
		50 => [
			'name' => "Focus Area",
			'type' => "Lookup",
			'lookup' => [
				0 => "Wide Focus (normal)",
				1 => "Spot Focus",
			],
		],
		51 => [
			'name' => "DEC Switch Position",
			'type' => "Lookup",
			'lookup' => [
				0 => "Exposure",
				1 => "Contrast",
				2 => "Saturation",
				3 => "Filter",
			],
		],
	];
}
