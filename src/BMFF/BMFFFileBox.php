<?php

/**
 * BMFFFileBox class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\BMFF;

/**
 * BMFFFileBox class.
 *
 * A box of an ISO base media *file* — an MP4, a QuickTime movie, a HEIF or an AVIF — which
 * is {@see BMFFBox}'s grammar plus the one thing the grammar cannot know: which types hold
 * children.  The lists here are what the format defines, not a guess; they were checked
 * against files written by ffmpeg and libheif.
 *
 * `meta` is the trap the whole format hinges on.  It is a **FullBox**, so four bytes of
 * version and flags sit between its header and its first child; a walker that treats it as
 * a plain container reads those bytes as a box length and desynchronizes everything after
 * it.  {@see FullBoxContainers} is what stops that.
 *
 * `ilst` is listed but the metadata keys inside it (`\xA9nam`, `covr`, `trkn`, …) are not:
 * their four-character codes are open-ended, so a key box stays opaque and its `data` boxes
 * are read from its payload on demand.
 *
 * `iinf` is deliberately **not** listed.  It is a FullBox container too, but the count
 * between its flags and its children is two bytes in version 0 and four in version 1, so
 * its prefix is not a constant — {@see \Belisoful\Image\BMFFFile} reads its entries itself.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class BMFFFileBox extends BMFFBox
{
	/** The box types whose payload is a sequence of child boxes. */
	public const ContainerTypes = [
		'moov', 'trak', 'edts', 'mdia', 'minf', 'dinf', 'stbl', 'udta',
		'mvex', 'moof', 'traf', 'mfra', 'meta', 'iprp', 'ipco', 'grpl',
		'sinf', 'schi', 'ipro', 'paen', 'strk', 'ilst', 'iref',
	];

	/** The container types that are FullBoxes, and the bytes of version and flags they carry. */
	public const FullBoxContainers = ['meta' => 4, 'iref' => 4];
}
