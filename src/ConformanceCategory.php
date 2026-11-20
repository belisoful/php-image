<?php

/**
 * ConformanceCategory class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image;

/**
 * ConformanceCategory class.
 *
 * The kinds of departure from a format's own specification that
 * {@see ConformableInterface::validate()} reports and
 * {@see ConformableInterface::conform()} corrects, one bit each so they combine:
 *
 * ```php
 * $problems = $image->validate();                                  // everything, changing nothing
 * $image->conform();                                               // what has an honest fix
 * $image->conform(ConformanceCategory::Type);                      // only the field types
 * $image->conform(ConformanceCategory::All);                       // invented values too
 * ```
 *
 * What each flag covers, by carrier:
 *
 * | Flag | EXIF / TIFF | IPTC | XMP |
 * |---|---|---|---|
 * | {@see Type} | the IPTC tag stored in a type other than LONG | – | a property stored in a form other than its schema's |
 * | {@see Required} | the fields Exif requires of a JPEG's or a TIFF's metadata, where the specification gives the value to write | the record versions and file format | – |
 * | {@see Invented} | – | the envelope's service identifier, date sent and envelope number | – |
 * | {@see Structure} | – | a record version with no dataset in its record | – |
 * | {@see Advisory} | – | – | an extended packet whose digest is not its name (JPEG) |
 *
 * {@see Invented} is apart from {@see Required} on purpose: both fill in something the
 * specification demands, but an invented value says something about the file that nobody
 * recorded -- today's date as the day it was sent -- so it is left out of {@see Safe}, the
 * default of `conform()`, and written only when asked for.  {@see Advisory} findings are
 * reported and never changed.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class ConformanceCategory
{
	/** A field stored in a type or form other than the one its specification gives it. */
	public const Type = 1 << 0;

	/** A required field that is missing and whose value the specification itself supplies. */
	public const Required = 1 << 1;

	/** A required field that is missing and whose value would have to be made up. */
	public const Invented = 1 << 2;

	/** A field that is present where the structure has no place for it. */
	public const Structure = 1 << 3;

	/** Something worth knowing that no change would put right. */
	public const Advisory = 1 << 4;

	/** What `conform()` corrects unless told otherwise: everything with an honest fix. */
	public const Safe = self::Type | self::Required | self::Structure;

	/** Every category. */
	public const All = -1;
}
