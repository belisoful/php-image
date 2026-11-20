<?php

/**
 * ConformableInterface interface file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image;

/**
 * ConformableInterface interface.
 *
 * Implemented by every metadata carrier and by {@see ImageFile}: {@see validate()} reports
 * where the metadata departs from its format's specification, and {@see conform()} corrects
 * it.  The two are apart because a rewrite adds nothing a file did not hold -- a file that
 * is read and saved comes back as it was, departures included -- so correcting them is
 * something a caller asks for, by {@see ConformanceCategory}.
 *
 * ```php
 * $jpeg = JPEGImage::fromFile('photo.jpg');
 * foreach ($jpeg->validate() as $what => $problem) {
 *     echo "$what: $problem\n";              // "EXIF ExifIFD:ColorSpace: required and missing"
 * }
 * $fixed = $jpeg->conform();                 // the number of corrections made
 * ```
 *
 * The rules are the specifications' own -- TIFF 6.0, Exif, IIM, the XMP schemas -- and not
 * any one tool's reading of them.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
interface ConformableInterface
{
	/**
	 * Reports where the metadata departs from its specification, changing nothing.
	 * @param int $types The {@see ConformanceCategory} flags to report. Default {@see ConformanceCategory::All}.
	 * @return array<string, string> What is wrong, by the field it is wrong with; empty
	 *   when nothing of those categories is.
	 */
	public function validate(int $types = ConformanceCategory::All): array;

	/**
	 * Corrects what {@see validate()} reports, where it has a correction.
	 * @param int $types The {@see ConformanceCategory} flags to correct. Default
	 *   {@see ConformanceCategory::Safe}, which leaves out values that would be invented.
	 * @return int The number of corrections made.
	 */
	public function conform(int $types = ConformanceCategory::Safe): int;
}
