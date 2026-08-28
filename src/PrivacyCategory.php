<?php

/**
 * PrivacyCategory class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image;

/**
 * PrivacyCategory class.
 *
 * The categories of identifying information an image's metadata can carry, as bit flags
 * for {@see PrivacyScrubbableInterface::clearPrivateData()}.  Each category is one bit, so they
 * combine with `|`; {@see All} (`-1`, every bit set) is the default and the right choice
 * for a photo leaving a user's control.
 *
 * The categories are **format-neutral**: the same flag names the same kind of fact in
 * every carrier, so `Location` clears the EXIF GPS directory, the XMP `exif:GPS*` and
 * IPTC `Iptc4xmpCore:Location` properties, and the IPTC IIM City/Province/Country
 * datasets alike, and a container's {@see ImageFile::clearPrivateData()} fans one flag
 * set out to every carrier it holds.
 *
 * ```php
 * $jpeg->clearPrivateData();                                       // every carrier, every category
 * $jpeg->clearPrivateData(PrivacyCategory::Location);              // just where it was taken
 * $exif->clearPrivateData(PrivacyCategory::All & ~PrivacyCategory::CameraModel);
 * $xmp->clearPrivateData(PrivacyCategory::Identity | PrivacyCategory::Location);
 * ```
 *
 * | Flag | Identifies | Removes (by carrier) |
 * |---|---|---|
 * | {@see Location} | where — the most sensitive fact a camera writes | EXIF: the GPS IFD · XMP: `exif:GPS*`, IPTC Core/Ext location structures, `photoshop:City/State/Country`, `Iptc4xmpCore:CountryCode` · IPTC: City, Sub-location, Province/State, Country code/name, Content Location |
 * | {@see Author} | who took, edited, or owns it | EXIF: Artist, Copyright, Photographer, ImageEditor, CameraOwnerName, XPAuthor · XMP: `dc:creator/rights/publisher`, `xmpRights:*`, `photoshop:Credit/Source/AuthorsPosition/CaptionWriter`, `Iptc4xmpCore:CreatorContactInfo`, `plus:*` licensing parties · IPTC: By-line, By-line Title, Credit, Source, Copyright, Contact, Writer/Editor · IRB: Copyright flag, URL |
 * | {@see Description} | free text that names people and places | EXIF: ImageDescription, UserComment, Title, the XP text fields, DocumentName · XMP: `dc:title/description/subject`, `photoshop:Headline/Instructions/Category/SupplementalCategories`, `Iptc4xmpCore:SubjectCode`, `xmp:Label/Rating` · IPTC: Object Name, Headline, Caption, Keywords, Special Instructions, Subject Reference, Category, Supplemental Categories, Local Caption · IRB: Caption · JPEG/GIF/PNG comments |
 * | {@see CameraModel} | the equipment — narrows a photographer down | EXIF: Make, Model, Lens make/model/spec · XMP: `tiff:Make/Model`, `exif:LensMake/Model`, `aux:Lens*` · IPTC: Exif Camera Info |
 * | {@see SerialNumber} | a unique device or document fingerprint | EXIF: Body/Lens serial, ImageUniqueID · XMP: `aux:SerialNumber/LensSerialNumber/ImageNumber`, `xmpMM:DocumentID/InstanceID/OriginalDocumentID/DerivedFrom/History` · IPTC: Job/Master/Short/Unique Document ID, Owner ID, Unique Object Name, Original Transmission Reference |
 * | {@see Timestamp} | when — with location it places a person | EXIF: every DateTime/OffsetTime/SubSecTime · XMP: `xmp:CreateDate/ModifyDate/MetadataDate`, `exif:DateTime*`, `photoshop:DateCreated` · IPTC: Date/Time Created, Digital Creation, Date/Time Sent, Release, Reference Date |
 * | {@see Software} | the toolchain and machine — a device fingerprint | EXIF: Software, CameraFirmware, HostComputer, the editing-software set · XMP: `xmp:CreatorTool`, `xmpMM:History` agents · IPTC: Originating Program, Program Version · IRB: Version Info |
 * | {@see MakerNote} | a proprietary blob that embeds serials and owner names | EXIF: the maker note · IPTC: none · IRB: none |
 * | {@see Thumbnail} | a copy of the image that survives cropping — a redaction leak | EXIF: IFD1 · XMP: `xmp:Thumbnails` · IPTC: Object Preview data, Rasterized Caption · IRB: both Thumbnail resources · JFIF/JFXX thumbnails |
 * | {@see Interoperability} | little alone; part of a complete scrub | EXIF: the Interoperability IFD |
 *
 * {@see Identity} groups the person-linked flags (Author, SerialNumber, MakerNote) and
 * {@see Provenance} the where/when/how (Location, Timestamp, Software).  What is
 * deliberately **not** covered anywhere: exposure, colour, dimensions, and rendering
 * settings — the fields that describe the picture rather than a person — so a scrubbed
 * file stays a well-formed, useful image.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class PrivacyCategory
{
	/** Where the image was captured. */
	public const Location = 1 << 0;

	/** Who took, edited, credited, or owns it. */
	public const Author = 1 << 1;

	/** Free-text fields — descriptions, comments, titles, keywords, captions — that often name people and places. */
	public const Description = 1 << 2;

	/** The equipment: camera and lens make, model, and specification. */
	public const CameraModel = 1 << 3;

	/** Unique fingerprints: device serial numbers and document/instance identifiers. */
	public const SerialNumber = 1 << 4;

	/** When it was captured, digitized, edited, or sent. */
	public const Timestamp = 1 << 5;

	/** The toolchain and machine: software names, versions, firmware, host computer. */
	public const Software = 1 << 6;

	/** The proprietary maker note. */
	public const MakerNote = 1 << 7;

	/** Embedded previews: thumbnails and rasterized captions, copies of the image that survive cropping. */
	public const Thumbnail = 1 << 8;

	/** The EXIF Interoperability IFD. */
	public const Interoperability = 1 << 9;

	/** The person-linked flags: {@see Author} | {@see SerialNumber} | {@see MakerNote}. */
	public const Identity = self::Author | self::SerialNumber | self::MakerNote;

	/** The where/when/how flags: {@see Location} | {@see Timestamp} | {@see Software}. */
	public const Provenance = self::Location | self::Timestamp | self::Software;

	/** Every category (all bits set): the default, and the right choice for an image leaving a user's control. */
	public const All = -1;
}
