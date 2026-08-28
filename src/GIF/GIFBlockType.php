<?php

/**
 * GIFBlockType class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\GIF;

/**
 * GIFBlockType class.
 *
 * The single-byte introducers and extension labels of the GIF block stream, as one
 * vocabulary in place of scattered numeric literals: the image separator that opens an
 * image descriptor, the extension introducer, the trailer that ends the stream, and the
 * label byte that names each extension (graphic control, comment, plain text,
 * application).
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @see https://www.w3.org/Graphics/GIF/spec-gif89a.txt
 */
class GIFBlockType
{
	/** The image descriptor introducer, `0x2C`. */
	public const ImageSeparator = 0x2C;

	/** The extension block introducer, `0x21`. */
	public const ExtensionIntroducer = 0x21;

	/** The trailer that ends the block stream, `0x3B`. */
	public const Trailer = 0x3B;

	/** The Graphic Control extension label, `0xF9`. */
	public const GraphicControlLabel = 0xF9;

	/** The Comment extension label, `0xFE`. */
	public const CommentLabel = 0xFE;

	/** The Plain Text extension label, `0x01`. */
	public const PlainTextLabel = 0x01;

	/** The Application extension label, `0xFF`. */
	public const ApplicationLabel = 0xFF;
}
