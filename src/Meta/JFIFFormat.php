<?php

/**
 * JFIFFormat class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\Meta;

/**
 * JFIFFormat class.
 *
 * Enumerates the JFIF / JFXX embedding modes for a JPEG's APP0 segment.
 *
 * | Mode            | Meaning                                                       |
 * |-----------------|---------------------------------------------------------------|
 * | None            | No JFIF information.                                          |
 * | JFIF            | JFIF without a thumbnail.                                     |
 * | Thumbnail       | JFIF with an uncompressed RGB thumbnail.                      |
 * | JFXXJPEG        | JFXX with a JPEG-compressed thumbnail.                        |
 * | JFXXPalette     | JFXX with a palette thumbnail.                                |
 * | JFXXColor       | JFXX with an uncompressed RGB thumbnail.                      |
 * | JFXXEfficiency  | JFXX with the most compact of the thumbnail encodings.       |
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class JFIFFormat
{
	public const None = 0x0000;
	public const JFIF = 0x1000;
	public const Thumbnail = 0x3000;
	public const JFXXJPEG = 0x10;
	public const JFXXPalette = 0x11;
	public const JFXXColor = 0x13;
	public const JFXXEfficiency = 0x0110;
}
