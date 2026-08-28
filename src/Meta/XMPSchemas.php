<?php

/**
 * XMPSchemas class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\Meta;

/**
 * XMPSchemas class.
 *
 * The property-form knowledge of the standard XMP schemas: which properties are
 * language alternatives, which are unordered bags, which are ordered sequences, and
 * which are structures.  {@see XMP} consults it so a write lands in the form the
 * schema defines — `dc:subject` as a Bag, `dc:creator` as a Seq, `dc:title` as a
 * language alternative — without the caller having to know, and
 * {@see XMP::validate()} reports the properties whose stored form disagrees.
 *
 * The tables cover Dublin Core, XMP Basic, Rights Management, Media Management, Basic
 * Job Ticket, Paged-Text, Dynamic Media, Photoshop, the IPTC Core and Extension
 * schemas, PLUS, and the EXIF/TIFF mirrors — the schemas a photographic file carries.
 * A property no table names is written as the caller asked, so custom schemas are
 * unaffected.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @see https://www.iso.org/standard/75164.html ISO 16684-2 (XMP schemas)
 */
class XMPSchemas
{
	/** A language-alternative property (`rdf:Alt` of `xml:lang` items). */
	public const LangAlt = 'LangAlt';

	/** An unordered array property (`rdf:Bag`). */
	public const Bag = 'Bag';

	/** An ordered array property (`rdf:Seq`). */
	public const Seq = 'Seq';

	/** An alternatives array property (`rdf:Alt`). */
	public const Alt = 'Alt';

	/** A structure-valued property. */
	public const Struct = 'Struct';

	/** A simple (text, numeric, date, or boolean) property. */
	public const Simple = 'Simple';

	/**
	 * @var array<string, array<string, string>> The namespace URI => property name =>
	 *   form map.  Only the properties whose form is not simple need an entry, plus the
	 *   dated and structured ones callers most often set.
	 */
	public const Forms = [
		XMP::NS_DC => [
			'contributor' => self::Bag,
			'coverage' => self::Simple,
			'creator' => self::Seq,
			'date' => self::Seq,
			'description' => self::LangAlt,
			'format' => self::Simple,
			'identifier' => self::Simple,
			'language' => self::Bag,
			'publisher' => self::Bag,
			'relation' => self::Bag,
			'rights' => self::LangAlt,
			'source' => self::Simple,
			'subject' => self::Bag,
			'title' => self::LangAlt,
			'type' => self::Bag,
		],
		XMP::NS_XMP => [
			'Advisory' => self::Bag,
			'BaseURL' => self::Simple,
			'CreateDate' => self::Simple,
			'CreatorTool' => self::Simple,
			'Identifier' => self::Bag,
			'Label' => self::Simple,
			'MetadataDate' => self::Simple,
			'ModifyDate' => self::Simple,
			'Nickname' => self::Simple,
			'Rating' => self::Simple,
			'Thumbnails' => self::Alt,
		],
		XMP::NS_RIGHTS => [
			'Certificate' => self::Simple,
			'Marked' => self::Simple,
			'Owner' => self::Bag,
			'UsageTerms' => self::LangAlt,
			'WebStatement' => self::Simple,
		],
		XMP::NS_MM => [
			'DerivedFrom' => self::Struct,
			'DocumentID' => self::Simple,
			'History' => self::Seq,
			'Ingredients' => self::Bag,
			'InstanceID' => self::Simple,
			'ManagedFrom' => self::Struct,
			'Manager' => self::Simple,
			'ManageTo' => self::Simple,
			'ManageUI' => self::Simple,
			'OriginalDocumentID' => self::Simple,
			'Pantry' => self::Bag,
			'RenditionClass' => self::Simple,
			'RenditionParams' => self::Simple,
			'VersionID' => self::Simple,
			'Versions' => self::Seq,
		],
		XMP::NS_BJ => [
			'JobRef' => self::Bag,
		],
		XMP::NS_TPG => [
			'Colorants' => self::Seq,
			'Fonts' => self::Bag,
			'MaxPageSize' => self::Struct,
			'NPages' => self::Simple,
			'PlateNames' => self::Seq,
		],
		XMP::NS_DM => [
			'artist' => self::Simple,
			'album' => self::Simple,
			'genre' => self::Simple,
			'logComment' => self::Simple,
			'projectRef' => self::Struct,
			'Tracks' => self::Bag,
			'videoFrameSize' => self::Struct,
		],
		XMP::NS_PHOTOSHOP => [
			'AuthorsPosition' => self::Simple,
			'CaptionWriter' => self::Simple,
			'Category' => self::Simple,
			'City' => self::Simple,
			'Country' => self::Simple,
			'Credit' => self::Simple,
			'DateCreated' => self::Simple,
			'DocumentAncestors' => self::Bag,
			'Headline' => self::Simple,
			'History' => self::Simple,
			'Instructions' => self::Simple,
			'Source' => self::Simple,
			'State' => self::Simple,
			'SupplementalCategories' => self::Bag,
			'TextLayers' => self::Seq,
			'TransmissionReference' => self::Simple,
			'Urgency' => self::Simple,
		],
		XMP::NS_IPTC_CORE => [
			'CountryCode' => self::Simple,
			'CreatorContactInfo' => self::Struct,
			'IntellectualGenre' => self::Simple,
			'Location' => self::Simple,
			'Scene' => self::Bag,
			'SubjectCode' => self::Bag,
		],
		XMP::NS_IPTC_EXT => [
			'AddlModelInfo' => self::Simple,
			'ArtworkOrObject' => self::Bag,
			'CVterm' => self::Bag,
			'Event' => self::LangAlt,
			'LocationCreated' => self::Bag,
			'LocationShown' => self::Bag,
			'ModelAge' => self::Bag,
			'OrganisationInImageCode' => self::Bag,
			'OrganisationInImageName' => self::Bag,
			'PersonInImage' => self::Bag,
			'RegistryId' => self::Bag,
		],
		XMP::NS_PLUS => [
			'ImageSupplier' => self::Seq,
			'ImageSupplierImageID' => self::Simple,
			'Licensee' => self::Seq,
			'LicensorNotes' => self::LangAlt,
			'ModelReleaseID' => self::Bag,
			'ModelReleaseStatus' => self::Simple,
			'PropertyReleaseID' => self::Bag,
			'PropertyReleaseStatus' => self::Simple,
		],
		XMP::NS_TIFF => [
			'Artist' => self::Simple,
			'BitsPerSample' => self::Seq,
			'Copyright' => self::LangAlt,
			'ImageDescription' => self::LangAlt,
			'Make' => self::Simple,
			'Model' => self::Simple,
			'Software' => self::Simple,
			'TransferFunction' => self::Seq,
			'WhitePoint' => self::Seq,
			'YCbCrCoefficients' => self::Seq,
		],
		XMP::NS_EXIF => [
			'CFAPattern' => self::Struct,
			'ComponentsConfiguration' => self::Seq,
			'Flash' => self::Struct,
			'ISOSpeedRatings' => self::Seq,
			'OECF' => self::Struct,
			'SpatialFrequencyResponse' => self::Struct,
			'SubjectArea' => self::Seq,
			'UserComment' => self::LangAlt,
		],
	];

	/**
	 * Returns the form a schema defines for a property.
	 * @param string $namespace The property namespace URI.
	 * @param string $name The property local name.
	 * @return ?string The form constant, or null when no schema names the property.
	 */
	public static function formOf(string $namespace, string $name): ?string
	{
		return self::Forms[$namespace][$name] ?? null;
	}

	/**
	 * Indicates whether a property is a language alternative.
	 * @param string $namespace The property namespace URI.
	 * @param string $name The property local name.
	 * @return bool Whether the schema defines it as a language alternative.
	 */
	public static function isLangAlt(string $namespace, string $name): bool
	{
		return self::formOf($namespace, $name) === self::LangAlt;
	}

	/**
	 * Returns the rdf collection a property serializes as.
	 * @param string $namespace The property namespace URI.
	 * @param string $name The property local name.
	 * @return ?string 'Alt', 'Bag', or 'Seq', or null when the property is not an array.
	 */
	public static function arrayFormOf(string $namespace, string $name): ?string
	{
		$form = self::formOf($namespace, $name);
		return match ($form) {
			self::LangAlt, self::Alt => 'Alt',
			self::Bag => 'Bag',
			self::Seq => 'Seq',
			default => null,
		};
	}

	/**
	 * Returns every property a schema names.
	 * @param string $namespace The schema namespace URI.
	 * @return array<string, string> The property name => form map (empty when unknown).
	 */
	public static function schema(string $namespace): array
	{
		return self::Forms[$namespace] ?? [];
	}
}
