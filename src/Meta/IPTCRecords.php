<?php

/**
 * IPTCRecords class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\Meta;

/**
 * IPTCRecords class.
 *
 * Enumerates the IPTC IIM record numbers that group datasets, used as the left side of a
 * `record#dataset` tag identifier (see {@see IPTCTags}).
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class IPTCRecords
{
	public const Envelope = 1;
	public const Application = 2;
	public const NewsPhoto = 3;
	public const PreObjectData = 7;
	public const ObjectData = 8;
	public const PostObjectData = 9;
	public const FotoStation = 240;
}
