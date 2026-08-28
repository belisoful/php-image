<?php

/**
 * PhotoshopFileInfo class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\Meta;

use Belisoful\Image\JPEGImage;
use Belisoful\Image\PhotoshopIRB;
use Belisoful\Image\PhotoshopResource;

/**
 * PhotoshopFileInfo class.
 *
 * The Photoshop "File Info" view: the twenty-two document-description fields that
 * Photoshop keeps synchronized across three stores — the XMP packet, the IPTC record
 * set inside the Photoshop IRB, and a handful of EXIF tags.  {@see fromJpeg()} gathers
 * a merged view (XMP first, then IPTC, then EXIF), and {@see applyTo()} writes every
 * field back to all three stores with the standard mapping — `title` to IPTC 2:05 and
 * `dc:title`, `author` to 2:80, `dc:creator`, and EXIF 315 Artist, `caption` to 2:120,
 * `dc:description`, and EXIF 270, the copyright status to the IRB copyright flag and
 * `xmpRights:Marked`, and so on — creating whichever stores are absent and truncating
 * to the IPTC dataset length limits.
 *
 * Fields are read and written by array access with the {@see Fields} names:
 *
 * ```php
 * $info = PhotoshopFileInfo::fromJpeg($jpeg);
 * $info['title'] = 'Sunset over Bergen';
 * $info['keywords'] = ['sunset', 'norway'];
 * $info['copyrightstatus'] = PhotoshopFileInfo::Copyrighted;
 * $info->applyTo($jpeg);                       // EXIF + XMP + IRB/IPTC all updated
 * $jpeg->save('out.jpg');
 * ```
 *
 * `keywords` and `supplementalcategories` are string lists; `urgency` is '' or 1-8;
 * `date` is `YYYY-MM-DD`; `copyrightstatus` is one of {@see CopyrightUnknown},
 * {@see Copyrighted}, or {@see PublicDomain}; every other field is a string.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class PhotoshopFileInfo implements \ArrayAccess
{
	/** The unknown copyright status. */
	public const CopyrightUnknown = 'Unknown';

	/** The copyrighted-work status. */
	public const Copyrighted = 'Copyrighted Work';

	/** The public-domain status. */
	public const PublicDomain = 'Public Domain';

	/** @var string[] The field names. */
	public const Fields = [
		'title', 'author', 'authorsposition', 'caption', 'captionwriter', 'jobname',
		'copyrightstatus', 'copyrightnotice', 'ownerurl', 'keywords', 'category',
		'supplementalcategories', 'date', 'city', 'state', 'country', 'credit',
		'source', 'headline', 'instructions', 'transmissionreference', 'urgency',
	];

	/** @var array<string, array{0: string, 1: int}> The field => [IPTC tag, length limit] map. */
	protected const IptcMap = [
		'title' => [IPTCTags::ObjectName, 64],
		'author' => [IPTCTags::ByLine, 32],
		'authorsposition' => [IPTCTags::ByLineTitle, 32],
		'caption' => [IPTCTags::CaptionAbstract, 2000],
		'captionwriter' => [IPTCTags::WriterEditor, 32],
		'copyrightnotice' => [IPTCTags::CopyrightNotice, 128],
		'keywords' => [IPTCTags::Keywords, 64],
		'category' => [IPTCTags::Category, 3],
		'supplementalcategories' => [IPTCTags::SupplementalCategories, 32],
		'city' => [IPTCTags::City, 32],
		'state' => [IPTCTags::ProvinceState, 32],
		'country' => [IPTCTags::CountryPrimaryLocationName, 64],
		'credit' => [IPTCTags::Credit, 32],
		'source' => [IPTCTags::Source, 32],
		'headline' => [IPTCTags::Headline, 256],
		'instructions' => [IPTCTags::SpecialInstructions, 256],
		'transmissionreference' => [IPTCTags::OriginalTransmissionReference, 32],
	];

	/** @var array<string, string> The field => photoshop-schema XMP property map. */
	protected const PhotoshopXmpMap = [
		'authorsposition' => 'AuthorsPosition',
		'captionwriter' => 'CaptionWriter',
		'category' => 'Category',
		'city' => 'City',
		'state' => 'State',
		'country' => 'Country',
		'credit' => 'Credit',
		'source' => 'Source',
		'headline' => 'Headline',
		'instructions' => 'Instructions',
		'transmissionreference' => 'TransmissionReference',
	];

	/** @var array<string, mixed> The field values. */
	private array $_fields = [];

	/**
	 * Constructs an empty File Info view.
	 */
	final public function __construct()
	{
	}

	/**
	 * Gathers the merged File Info of a JPEG: XMP first, then IPTC, then EXIF.
	 * @param JPEGImage $jpeg The image.
	 * @return static The merged view.
	 */
	public static function fromJpeg(JPEGImage $jpeg): static
	{
		$info = new static();
		$xmp = $jpeg->getXMP();
		if ($xmp !== null) {
			$info->mergeString('title', $xmp->getTitle());
			$info->mergeString('caption', $xmp->getDescription());
			$info->mergeString('copyrightnotice', $xmp->getRights());
			$info->mergeList('author', $xmp->getCreators());
			$info->mergeList('keywords', $xmp->getKeywords());
			foreach (self::PhotoshopXmpMap as $field => $property) {
				$value = $xmp->getProperty(XMP::NS_PHOTOSHOP, $property);
				$info->mergeString($field, is_array($value) ? ($value[0] ?? null) : $value);
			}
			$supplemental = $xmp->getProperty(XMP::NS_PHOTOSHOP, 'SupplementalCategories');
			$info->mergeList('supplementalcategories', is_array($supplemental) ? $supplemental : []);
			$info->mergeString('date', self::primaryString($xmp->getProperty(XMP::NS_PHOTOSHOP, 'DateCreated')));
			$info->mergeString('urgency', self::primaryString($xmp->getProperty(XMP::NS_PHOTOSHOP, 'Urgency')));
			$marked = self::primaryString($xmp->getProperty(XMP::NS_RIGHTS, 'Marked'));
			if ($marked !== null) {
				$info['copyrightstatus'] = strcasecmp($marked, 'True') === 0 ? self::Copyrighted : self::PublicDomain;
			}
			$info->mergeString('ownerurl', self::primaryString($xmp->getProperty(XMP::NS_RIGHTS, 'WebStatement')));
			$job = $xmp->getProperty(XMP::NS_BJ, 'JobRef');
			$info->mergeString('jobname', is_array($job) ? ($job['name'] ?? null) : $job);
		}
		$iptc = $jpeg->getIPTC();
		if ($iptc !== null) {
			foreach (self::IptcMap as $field => [$tag]) {
				$info->mergeString($field, $iptc[$tag]);
			}
			$date = $iptc[IPTCTags::DateCreated];
			if (is_string($date) && preg_match('/^(\d{4})(\d{2})(\d{2})$/', $date, $m)) {
				$info->mergeString('date', "$m[1]-$m[2]-$m[3]");
			}
			$urgency = $iptc[IPTCTags::Urgency];
			if ($urgency !== null && (int) $urgency >= 1 && (int) $urgency <= 8) {
				$info->mergeString('urgency', (string) (int) $urgency);
			}
		}
		$irb = $jpeg->getPhotoshopIRB();
		if ($irb !== null) {
			$flag = $irb->getResource(PhotoshopResource::CopyrightFlag)?->decodeBoolean();
			if ($flag !== null && ($info['copyrightstatus'] ?? '') === '') {
				$info['copyrightstatus'] = $flag ? self::Copyrighted : self::CopyrightUnknown;
			}
			$info->mergeString('ownerurl', $irb->getResource(PhotoshopResource::Url)?->decodeText());
		}
		$exif = $jpeg->getEXIF();
		if ($exif !== null) {
			$info->mergeString('author', $exif->getValueByName('Artist'));
			$info->mergeString('caption', $exif->getValueByName('ImageDescription'));
			$info->mergeString('copyrightnotice', $exif->getValueByName('Copyright'));
		}
		return $info;
	}

	/**
	 * Returns a value's primary string form.
	 * @param mixed $value A property value.
	 * @return ?string The string, or null.
	 */
	protected static function primaryString(mixed $value): ?string
	{
		if (is_array($value)) {
			$value = array_is_list($value) ? ($value[0] ?? null) : null;
		}
		return $value === null ? null : (string) $value;
	}

	/**
	 * Keeps the first non-empty string seen for a field.
	 * @param string $field The field name.
	 * @param mixed $value The candidate value.
	 */
	protected function mergeString(string $field, mixed $value): void
	{
		if ($value === null || $value === '' || $value === []) {
			return;
		}
		if (is_array($value)) {
			$this->mergeList($field, $value);
			return;
		}
		if (($this->_fields[$field] ?? '') === '') {
			$this->_fields[$field] = trim((string) $value);
		}
	}

	/**
	 * De-duplicating merge of list values: list fields append unseen items, scalar
	 * fields join the items and keep the first non-empty value.
	 * @param string $field The field name.
	 * @param array $values The candidate values.
	 */
	protected function mergeList(string $field, array $values): void
	{
		$values = array_values(array_filter(array_map(fn ($v) => trim((string) $v), $values), fn ($v) => $v !== ''));
		if ($values === []) {
			return;
		}
		if ($field === 'keywords' || $field === 'supplementalcategories') {
			$current = (array) ($this->_fields[$field] ?? []);
			foreach ($values as $value) {
				if (!in_array($value, $current, true)) {
					$current[] = $value;
				}
			}
			$this->_fields[$field] = $current;
			return;
		}
		$this->mergeString($field, implode(', ', $values));
	}

	/**
	 * Writes every field to the JPEG's EXIF, XMP, and IRB/IPTC stores, creating the
	 * stores that are absent and truncating to the IPTC limits.
	 * @param JPEGImage $jpeg The image to update.
	 */
	public function applyTo(JPEGImage $jpeg): void
	{
		$exif = $jpeg->getEXIF() ?? new EXIF();
		$xmp = $jpeg->getXMP() ?? XMP::blank();
		$iptc = $jpeg->getIPTC() ?? new IPTC();
		$irb = $jpeg->getPhotoshopIRB() ?? new PhotoshopIRB();

		// IPTC datasets (in the IRB) with their length limits.
		foreach (self::IptcMap as $field => [$tag, $limit]) {
			$value = $this->_fields[$field] ?? '';
			if ($value === '' || $value === []) {
				unset($iptc[$tag]);
			} elseif (is_array($value)) {
				$iptc[$tag] = array_map(fn ($v) => substr($v, 0, $limit), $value);
			} else {
				$iptc[$tag] = substr($value, 0, $limit);
			}
		}
		$date = (string) ($this->_fields['date'] ?? '');
		if ($date !== '' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
			$iptc[IPTCTags::DateCreated] = "$m[1]$m[2]$m[3]";
		} elseif ($date === '') {
			unset($iptc[IPTCTags::DateCreated]);
		} else {
			throw new \InvalidArgumentException(sprintf('File Info date \'%s\' must be YYYY-MM-DD.', $date));
		}
		$urgency = (string) ($this->_fields['urgency'] ?? '');
		if ($urgency !== '' && (int) $urgency >= 1 && (int) $urgency <= 8) {
			$iptc[IPTCTags::Urgency] = (int) $urgency;
		} else {
			unset($iptc[IPTCTags::Urgency]);
		}

		// XMP properties.
		$xmp->setTitle($this->stringOrNull('title'));
		$xmp->setDescription($this->stringOrNull('caption'));
		$xmp->setRights($this->stringOrNull('copyrightnotice'));
		$author = $this->stringOrNull('author');
		$xmp->setCreators($author === null ? [] : [$author]);
		$xmp->setKeywords((array) ($this->_fields['keywords'] ?? []));
		foreach (self::PhotoshopXmpMap as $field => $property) {
			$xmp->setProperty(XMP::NS_PHOTOSHOP, $property, $this->stringOrNull($field));
		}
		$supplemental = (array) ($this->_fields['supplementalcategories'] ?? []);
		$xmp->setProperty(XMP::NS_PHOTOSHOP, 'SupplementalCategories', $supplemental === [] ? null : $supplemental);
		$xmp->setProperty(XMP::NS_PHOTOSHOP, 'DateCreated', $this->stringOrNull('date'));
		$xmp->setProperty(XMP::NS_PHOTOSHOP, 'Urgency', $urgency !== '' && (int) $urgency >= 1 && (int) $urgency <= 8 ? $urgency : null);
		$status = (string) ($this->_fields['copyrightstatus'] ?? '');
		$xmp->setProperty(XMP::NS_RIGHTS, 'Marked', match ($status) {
			self::Copyrighted => 'True',
			self::PublicDomain => 'False',
			default => null,
		});
		$xmp->setProperty(XMP::NS_RIGHTS, 'WebStatement', $this->stringOrNull('ownerurl'));
		$job = $this->stringOrNull('jobname');
		$xmp->setProperty(XMP::NS_BJ, 'JobRef', $job === null ? null : ['name' => $job]);

		// EXIF tags.
		$exif->setValueByName('Artist', $author);
		$exif->setValueByName('ImageDescription', $this->stringOrNull('caption'));
		$exif->setValueByName('Copyright', $this->stringOrNull('copyrightnotice'));

		// IRB resources.
		if ($status === self::Copyrighted || $status === self::PublicDomain) {
			$irb->setResource(new PhotoshopResource(PhotoshopResource::CopyrightFlag, $status === self::Copyrighted ? "\x01" : "\x00"));
		} else {
			$irb->removeResource(PhotoshopResource::CopyrightFlag);
		}
		$url = $this->stringOrNull('ownerurl');
		if ($url === null) {
			$irb->removeResource(PhotoshopResource::Url);
		} else {
			$irb->setResource(new PhotoshopResource(PhotoshopResource::Url, $url));
		}
		$iptc[IPTCTags::ApplicationRecordVersion] ??= 4;

		$jpeg->setEXIF($exif);
		$jpeg->setXMP($xmp);
		$jpeg->setIPTC($iptc);
		$jpeg->setPhotoshopIRB($irb);
	}

	/**
	 * Returns a field as a non-empty string or null.
	 * @param string $field The field name.
	 * @return ?string The value, or null when empty.
	 */
	protected function stringOrNull(string $field): ?string
	{
		$value = $this->_fields[$field] ?? '';
		if (is_array($value)) {
			$value = implode(', ', $value);
		}
		return $value === '' ? null : (string) $value;
	}

	/**
	 * Returns every populated field.
	 * @return array<string, mixed> The field values.
	 */
	public function getFields(): array
	{
		return $this->_fields;
	}

	/**
	 * Validates a field name.
	 * @param mixed $offset The candidate name.
	 * @throws \InvalidArgumentException When the name is not a File Info field.
	 * @return string The field name.
	 */
	protected function fieldName(mixed $offset): string
	{
		$field = strtolower((string) $offset);
		if (!in_array($field, self::Fields, true)) {
			throw new \InvalidArgumentException(sprintf('\'%s\' is not a File Info field.', (string) $offset));
		}
		return $field;
	}

	/**
	 * Indicates whether a field is populated.
	 * @param mixed $offset The field name.
	 * @return bool Whether the field has a value.
	 */
	public function offsetExists(mixed $offset): bool
	{
		return ($this->_fields[$this->fieldName($offset)] ?? '') !== '';
	}

	/**
	 * Returns a field value.
	 * @param mixed $offset The field name.
	 * @return mixed The value, or null when unset.
	 */
	public function offsetGet(mixed $offset): mixed
	{
		return $this->_fields[$this->fieldName($offset)] ?? null;
	}

	/**
	 * Sets a field value.
	 * @param mixed $offset The field name.
	 * @param mixed $value The value (a string, or a string list for the list fields).
	 */
	public function offsetSet(mixed $offset, mixed $value): void
	{
		$this->_fields[$this->fieldName($offset)] = $value;
	}

	/**
	 * Clears a field.
	 * @param mixed $offset The field name.
	 */
	public function offsetUnset(mixed $offset): void
	{
		unset($this->_fields[$this->fieldName($offset)]);
	}
}
