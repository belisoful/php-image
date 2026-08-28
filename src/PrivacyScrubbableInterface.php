<?php

/**
 * PrivacyScrubbableInterface interface file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image;

/**
 * PrivacyScrubbableInterface interface.
 *
 * The contract for removing identifying information from a metadata carrier or an image
 * container, by {@see PrivacyCategory} — the first-class privacy operation of the
 * library.  Every carrier that can hold identifying data implements it (EXIF, XMP, IPTC,
 * the Photoshop IRB), and every {@see ImageFile} container implements it by fanning the
 * request out to each carrier it holds plus its own format-level fields (comments, text
 * chunks, thumbnails), so one call scrubs a whole file.
 *
 * ```php
 * $jpeg = JPEGImage::fromFile('photo.jpg');
 * $jpeg->clearPrivateData();                            // every carrier, every category
 * $jpeg->save('photo-shareable.jpg');
 *
 * $jpeg->getXMP()?->clearPrivateData(PrivacyCategory::Location);   // one carrier, one category
 * ```
 *
 * Implementations remove only what identifies a person, place, time, or device; the fields
 * that describe the picture itself (exposure, colour, dimensions, rendering) are left, so
 * a scrubbed file stays well-formed and useful.  A category the carrier has no field for
 * is simply skipped, and the method never fails.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
interface PrivacyScrubbableInterface
{
	/**
	 * Removes identifying information by category.
	 * @param int $types The {@see PrivacyCategory} flags to remove. Default {@see PrivacyCategory::All}.
	 * @return int The number of fields, records, or directories removed.
	 */
	public function clearPrivateData(int $types = PrivacyCategory::All): int;
}
