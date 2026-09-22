<?php

/**
 * RIFFChunkType class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image;

/**
 * RIFFChunkType class.
 *
 * The four-character ids of the RIFF chunks this library knows by name — the generic
 * container ids, the WebP form's chunks, and the AVI form's structure and metadata — as a
 * single vocabulary in place of scattered string literals.  The values are the on-disk ids (with the trailing space a short id
 * carries, such as `VP8 ` and `XMP `), so a constant is interchangeable with the raw
 * string a {@see ImageChunk} carries.
 *
 * This is a **vocabulary of the known ids, not a closed type**: a RIFF container may hold
 * any chunk id, and {@see RIFFContainer}/{@see TWebP} preserve unknown ones byte-faithfully by
 * keeping the chunk id a raw string.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @see https://developers.google.com/speed/webp/docs/riff_container
 */
class RIFFChunkType
{
	/** The outer RIFF container id, whose sizes are little-endian. */
	public const Riff = 'RIFF';

	/** The big-endian variant of the container: the same grammar, every size byte-reversed. */
	public const Rifx = 'RIFX';

	/** The 64-bit variant (EBU Tech 3306), whose real sizes live in a leading `ds64` chunk. */
	public const Rf64 = 'RF64';

	/** The 64-bit variant under its later name (ITU-R BS.2088); structurally identical. */
	public const Bw64 = 'BW64';

	/** The chunk carrying the 64-bit sizes of an {@see Rf64} container. */
	public const DataSize64 = 'ds64';

	/** The 32-bit size a chunk writes when its real size is in {@see DataSize64}. */
	public const Size64Sentinel = 0xFFFFFFFF;

	/** The audio data chunk, whose 64-bit size `ds64` carries in a field of its own. */
	public const WaveData = 'data';

	/** A LIST sub-chunk. */
	public const RiffList = 'LIST';

	// WebP bitstream chunks.
	public const Vp8 = 'VP8 ';
	public const Vp8Lossless = 'VP8L';
	public const Vp8Extended = 'VP8X';
	public const Alpha = 'ALPH';

	// WebP animation chunks.
	public const Animation = 'ANIM';
	public const AnimationFrame = 'ANMF';

	// WebP metadata chunks.
	public const ICCProfile = 'ICCP';
	public const Exif = 'EXIF';
	public const Xmp = 'XMP ';

	// AVI structure chunks.
	public const AviHeader = 'avih';
	public const StreamHeader = 'strh';
	public const StreamFormat = 'strf';
	public const Index = 'idx1';
	public const OpenDmlIndex = 'indx';

	// Padding, which an AVI writer uses to hold a size steady without moving anything.
	public const Junk = 'JUNK';

	// AVI metadata chunks.
	public const DigitizationTime = 'IDIT';
	public const XmpRiff = '_PMX';

	/*
	 * List types, which are the first four bytes of a `LIST` payload rather than chunk ids
	 * in their own right; {@see RIFFContainer::getList()} and {@see RIFFList::getListType()} match
	 * against these.
	 */
	public const InfoList = 'INFO';
	public const ExifList = 'exif';
	public const HeaderList = 'hdrl';
	public const StreamList = 'strl';
	public const MovieList = 'movi';
}
