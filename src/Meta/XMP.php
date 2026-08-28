<?php

/**
 * XMP class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\Meta;

use Belisoful\Image\PrivacyScrubbableInterface;
use Belisoful\Image\PrivacyCategory;

/**
 * XMP class.
 *
 * An XMP metadata packet (ISO 16684-1) as a live DOM: {@see parse()} loads the RDF/XML
 * of a JPEG APP1 XMP segment, a PNG `iTXt` chunk, a WebP `XMP ` chunk, or the TIFF tag
 * 700 form, and {@see toPacketText()} serializes it back inside the `xpacket` wrapper
 * with the whitespace padding in-place editors rely on.
 *
 * Properties are addressed by namespace URI and local name, and the reader models the
 * whole XMP value grammar:
 *
 * | Form | {@see getProperty()} answers |
 * |------|------------------------------|
 * | Simple value (element or attribute shorthand) | the string |
 * | `rdf:Alt` / `rdf:Bag` / `rdf:Seq` | a list array, recursively read |
 * | Structure (`rdf:parseType="Resource"`, a nested `rdf:Description`, or attribute shorthand) | a field-name to value array |
 * | Arrays of structures (`xmpMM:History`, `Iptc4xmpExt` blocks) | a list of field arrays |
 * | A qualified value (`rdf:value` plus qualifiers) | the value, with {@see getQualifiers()} for the rest |
 *
 * Language alternatives get first-class treatment — {@see getLangAlt()} answers the
 * whole language map, {@see getLangAltValue()} selects one with the specification's
 * `x-default` fallback, and {@see setLangAlt()} writes them — and
 * {@see getByPath() path expressions} (`xmpMM:History[1]/stEvt:action`) reach into
 * nested structures.  {@see getProperties()} enumerates everything the packet holds.
 *
 * ```php
 * $xmp = $jpeg->getXMP() ?? XMP::blank();
 * $xmp->getLangAltValue(XMP::NS_DC, 'title', 'de');       // 'Sonnenuntergang'
 * $xmp->setKeywords(['sunset', 'norway']);
 * $xmp->getByPath('xmpMM:History[1]/stEvt:action');        // 'edited'
 * $jpeg->setXMP($xmp);                                     // split across APP1 if large
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @see https://www.iso.org/standard/75163.html ISO 16684-1 (XMP)
 */
class XMP implements PrivacyScrubbableInterface
{
	use \Belisoful\Image\StreamIOTrait;

	/** The RDF syntax namespace. */
	public const NS_RDF = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';

	/** The x:xmpmeta wrapper namespace. */
	public const NS_X = 'adobe:ns:meta/';

	/** The XML namespace (xml:lang and friends). */
	public const NS_XML = 'http://www.w3.org/XML/1998/namespace';

	/** The Dublin Core namespace. */
	public const NS_DC = 'http://purl.org/dc/elements/1.1/';

	/** The XMP Basic namespace. */
	public const NS_XMP = 'http://ns.adobe.com/xap/1.0/';

	/** The XMP Rights Management namespace. */
	public const NS_RIGHTS = 'http://ns.adobe.com/xap/1.0/rights/';

	/** The XMP Media Management namespace. */
	public const NS_MM = 'http://ns.adobe.com/xap/1.0/mm/';

	/** The XMP Basic Job Ticket namespace. */
	public const NS_BJ = 'http://ns.adobe.com/xap/1.0/bj/';

	/** The XMP Paged-Text namespace. */
	public const NS_TPG = 'http://ns.adobe.com/xap/1.0/t/pg/';

	/** The XMP Dynamic Media namespace. */
	public const NS_DM = 'http://ns.adobe.com/xmp/1.0/DynamicMedia/';

	/** The XMP note namespace (carrying the extended-XMP digest). */
	public const NS_NOTE = 'http://ns.adobe.com/xmp/note/';

	/** The Adobe PDF namespace. */
	public const NS_PDF = 'http://ns.adobe.com/pdf/1.3/';

	/** The Photoshop namespace. */
	public const NS_PHOTOSHOP = 'http://ns.adobe.com/photoshop/1.0/';

	/** The Camera Raw settings namespace. */
	public const NS_CRS = 'http://ns.adobe.com/camera-raw-settings/1.0/';

	/** The additional EXIF properties namespace (exifEX). */
	public const NS_EXIF_AUX = 'http://ns.adobe.com/exif/1.0/aux/';

	/** The embedded TIFF properties namespace. */
	public const NS_TIFF = 'http://ns.adobe.com/tiff/1.0/';

	/** The embedded EXIF properties namespace. */
	public const NS_EXIF = 'http://ns.adobe.com/exif/1.0/';

	/** The EXIF 2.3 extension properties namespace. */
	public const NS_EXIFEX = 'http://cipa.jp/exif/1.0/';

	/** The IPTC Core namespace. */
	public const NS_IPTC_CORE = 'http://iptc.org/std/Iptc4xmpCore/1.0/xmlns/';

	/** The IPTC Extension namespace. */
	public const NS_IPTC_EXT = 'http://iptc.org/std/Iptc4xmpExt/2008-02-29/';

	/** The PLUS licensing namespace. */
	public const NS_PLUS = 'http://ns.useplus.org/ldf/xmp/1.0/';

	/** The Google photo-sphere (panorama) namespace. */
	public const NS_GPANO = 'http://ns.google.com/photos/1.0/panorama/';

	/** The Job (stJob) structure namespace. */
	public const NS_STJOB = 'http://ns.adobe.com/xap/1.0/sType/Job#';

	/** The resource-event (stEvt) structure namespace. */
	public const NS_STEVT = 'http://ns.adobe.com/xap/1.0/sType/ResourceEvent#';

	/** The resource-reference (stRef) structure namespace. */
	public const NS_STREF = 'http://ns.adobe.com/xap/1.0/sType/ResourceRef#';

	/** The dimensions (stDim) structure namespace. */
	public const NS_STDIM = 'http://ns.adobe.com/xap/1.0/sType/Dimensions#';

	/** The version (stVer) structure namespace. */
	public const NS_STVER = 'http://ns.adobe.com/xap/1.0/sType/Version#';

	/** The xpacket id of a writable packet. */
	public const XPACKET_ID = 'W5M0MpCehiHzreSzNTczkc9d';

	/** The language of the default alternative. */
	public const DefaultLanguage = 'x-default';

	/** @var array<string, string> The canonical prefix of each known namespace. */
	public const Prefixes = [
		self::NS_DC => 'dc',
		self::NS_XMP => 'xmp',
		self::NS_RIGHTS => 'xmpRights',
		self::NS_MM => 'xmpMM',
		self::NS_BJ => 'xmpBJ',
		self::NS_TPG => 'xmpTPg',
		self::NS_DM => 'xmpDM',
		self::NS_NOTE => 'xmpNote',
		self::NS_PDF => 'pdf',
		self::NS_PHOTOSHOP => 'photoshop',
		self::NS_CRS => 'crs',
		self::NS_EXIF_AUX => 'aux',
		self::NS_TIFF => 'tiff',
		self::NS_EXIF => 'exif',
		self::NS_EXIFEX => 'exifEX',
		self::NS_IPTC_CORE => 'Iptc4xmpCore',
		self::NS_IPTC_EXT => 'Iptc4xmpExt',
		self::NS_PLUS => 'plus',
		self::NS_GPANO => 'GPano',
		self::NS_STJOB => 'stJob',
		self::NS_STEVT => 'stEvt',
		self::NS_STREF => 'stRef',
		self::NS_STDIM => 'stDim',
		self::NS_STVER => 'stVer',
	];

	/** @var int The default whitespace padding bytes appended for in-place editing. */
	protected const PACKET_PADDING = 2048;

	/** @var \DOMDocument The packet DOM. */
	private \DOMDocument $_dom;

	/** @var int The whitespace padding bytes {@see toPacketText()} appends. */
	private int $_packetPadding = self::PACKET_PADDING;

	/** @var bool Whether the packet is marked writable (`end="w"`). */
	private bool $_writable = true;

	/** @var array<string, string> The extra namespace => prefix registrations. */
	private array $_prefixes = [];

	/**
	 * Constructs an XMP over a DOM (a blank skeleton by default).
	 * @param ?\DOMDocument $dom The packet DOM, or null for a blank packet.
	 */
	final public function __construct(?\DOMDocument $dom = null)
	{
		$this->_dom = $dom ?? static::blankDom();
	}

	/**
	 * Builds the minimal x:xmpmeta / rdf:RDF skeleton.
	 * @return \DOMDocument The blank packet DOM.
	 */
	protected static function blankDom(): \DOMDocument
	{
		$dom = new \DOMDocument('1.0', 'UTF-8');
		$meta = $dom->createElementNS(self::NS_X, 'x:xmpmeta');
		$dom->appendChild($meta);
		$meta->appendChild($dom->createElementNS(self::NS_RDF, 'rdf:RDF'));
		return $dom;
	}

	/**
	 * Returns a blank packet.
	 * @return static The empty XMP.
	 */
	public static function blank(): static
	{
		return new static();
	}

	/**
	 * Parses an XMP packet (raw RDF/XML, with or without the xpacket wrapper).
	 * @param string $xml The packet text.
	 * @return false|static The parsed XMP, or false when the XML does not parse or
	 *   carries no rdf:RDF element.
	 */
	public static function parse(string $xml): false|static
	{
		$dom = new \DOMDocument('1.0', 'UTF-8');
		$useErrors = libxml_use_internal_errors(true);
		$loaded = $dom->loadXML($xml);
		libxml_clear_errors();
		libxml_use_internal_errors($useErrors);
		if (!$loaded || $dom->getElementsByTagNameNS(self::NS_RDF, 'RDF')->length === 0) {
			return false;
		}
		return new static($dom);
	}

	/**
	 * Parses an XMP packet from a PSR-7 stream or stream resource, reading from the
	 * current position to the end.
	 * @param mixed $stream The {@see \Psr\Http\Message\StreamInterface} or PHP stream resource.
	 * @return false|static The parsed XMP, or false when the XML does not parse.
	 */
	public static function fromStream(mixed $stream): false|static
	{
		return static::parse(static::sourceBytes($stream));
	}

	/**
	 * Returns the packet DOM for direct manipulation.
	 * @return \DOMDocument The DOM.
	 */
	public function getDom(): \DOMDocument
	{
		return $this->_dom;
	}

	/**
	 * Returns the whitespace padding {@see toPacketText()} appends.
	 * @return int The padding in bytes.
	 */
	public function getPacketPadding(): int
	{
		return $this->_packetPadding;
	}

	/**
	 * Sets the whitespace padding {@see toPacketText()} appends, which lets an editor
	 * grow the packet in place.
	 * @param int $value The padding in bytes.
	 */
	public function setPacketPadding(int $value): void
	{
		$this->_packetPadding = max(0, $value);
	}

	/**
	 * Indicates whether the packet is marked writable.
	 * @return bool Whether the trailer is `end="w"` rather than `end="r"`.
	 */
	public function getIsWritable(): bool
	{
		return $this->_writable;
	}

	/**
	 * Sets whether the packet is marked writable (`end="w"`) or read-only (`end="r"`).
	 * @param bool $value Whether the packet is writable.
	 */
	public function setIsWritable(bool $value): void
	{
		$this->_writable = $value;
	}

	/**
	 * Registers the prefix a namespace serializes under, for schemas beyond
	 * {@see Prefixes}.
	 * @param string $namespace The namespace URI.
	 * @param string $prefix The prefix.
	 */
	public function registerNamespace(string $namespace, string $prefix): void
	{
		$this->_prefixes[$namespace] = $prefix;
	}

	/**
	 * Returns the prefix a namespace serializes under.
	 * @param string $namespace The namespace URI.
	 * @return string The registered, canonical, declared, or generated prefix.
	 */
	public function prefixFor(string $namespace): string
	{
		if (isset($this->_prefixes[$namespace])) {
			return $this->_prefixes[$namespace];
		}
		if (isset(self::Prefixes[$namespace])) {
			return self::Prefixes[$namespace];
		}
		$declared = array_search($namespace, $this->getNamespaces(), true);
		return $declared === false || $declared === '' ? 'ns' . (count($this->_prefixes) + 1) : $declared;
	}

	/**
	 * Resolves a prefix to its namespace URI, across the canonical table, the
	 * registrations, and the packet's own declarations.
	 * @param string $prefix The prefix.
	 * @return ?string The namespace URI, or null when unknown.
	 */
	public function namespaceFor(string $prefix): ?string
	{
		$found = array_search($prefix, $this->_prefixes, true);
		if ($found !== false) {
			return $found;
		}
		$found = array_search($prefix, self::Prefixes, true);
		if ($found !== false) {
			return $found;
		}
		return $this->getNamespaces()[$prefix] ?? null;
	}

	/**
	 * Returns the rdf:RDF element.
	 * @return ?\DOMElement The RDF root, or null on a malformed DOM.
	 */
	protected function getRdf(): ?\DOMElement
	{
		$list = $this->_dom->getElementsByTagNameNS(self::NS_RDF, 'RDF');
		return $list->length > 0 ? $list->item(0) : null;
	}

	/**
	 * Returns the rdf:Description elements.
	 * @return \DOMElement[] The descriptions.
	 */
	protected function getDescriptions(): array
	{
		$rdf = $this->getRdf();
		if ($rdf === null) {
			return [];
		}
		$descriptions = [];
		foreach ($rdf->childNodes as $node) {
			if ($node instanceof \DOMElement && $node->namespaceURI === self::NS_RDF && $node->localName === 'Description') {
				$descriptions[] = $node;
			}
		}
		return $descriptions;
	}

	/**
	 * Returns the namespaces declared across the descriptions.
	 * @return array<string, string> The prefix => namespace URI map.
	 */
	public function getNamespaces(): array
	{
		$namespaces = [];
		$xpath = new \DOMXPath($this->_dom);
		foreach ($this->getDescriptions() as $description) {
			foreach ($xpath->query('namespace::*', $description) as $node) {
				if ($node->localName !== 'xml' && $node->nodeValue !== self::NS_RDF && $node->nodeValue !== self::NS_X) {
					$namespaces[$node->localName === 'xmlns' ? '' : $node->localName] = $node->nodeValue;
				}
			}
		}
		return $namespaces;
	}

	/**
	 * Finds a property node: an attribute or child element of any rdf:Description.
	 * @param string $namespace The property namespace URI.
	 * @param string $name The property local name.
	 * @return null|\DOMAttr|\DOMElement The property node, or null.
	 */
	protected function findProperty(string $namespace, string $name): null|\DOMElement|\DOMAttr
	{
		foreach ($this->getDescriptions() as $description) {
			if ($description->hasAttributeNS($namespace, $name)) {
				return $description->getAttributeNodeNS($namespace, $name);
			}
			foreach ($description->childNodes as $node) {
				if ($node instanceof \DOMElement && $node->namespaceURI === $namespace && $node->localName === $name) {
					return $node;
				}
			}
		}
		return null;
	}

	/**
	 * Indicates whether an XMP property exists.
	 * @param string $namespace The property namespace URI.
	 * @param string $name The property local name.
	 * @return bool Whether the property is present.
	 */
	public function containsProperty(string $namespace, string $name): bool
	{
		return $this->findProperty($namespace, $name) !== null;
	}

	/**
	 * Reads a property, recursively: a string for a simple value, a list array for an
	 * rdf:Alt/Bag/Seq collection (whose items may themselves be structures), and a
	 * field-name to value array for a structure.
	 * @param string $namespace The property namespace URI.
	 * @param string $name The property local name.
	 * @return null|array|string The value, or null when absent.
	 */
	public function getProperty(string $namespace, string $name): null|string|array
	{
		$node = $this->findProperty($namespace, $name);
		if ($node === null) {
			return null;
		}
		if ($node instanceof \DOMAttr) {
			return $node->value;
		}
		return $this->readNode($node);
	}

	/**
	 * Reads a property node's value by the XMP value grammar.
	 * @param \DOMElement $node The property (or item) element.
	 * @return array|string The value.
	 */
	protected function readNode(\DOMElement $node): string|array
	{
		$collection = $this->findCollection($node);
		if ($collection !== null) {
			$items = [];
			foreach ($collection->childNodes as $child) {
				if ($child instanceof \DOMElement && $child->namespaceURI === self::NS_RDF && $child->localName === 'li') {
					$items[] = $this->readNode($child);
				}
			}
			return $items;
		}

		// A nested rdf:Description is the longhand structure (and qualifier) form.
		$description = null;
		foreach ($node->childNodes as $child) {
			if ($child instanceof \DOMElement && $child->namespaceURI === self::NS_RDF && $child->localName === 'Description') {
				$description = $child;
				break;
			}
		}
		$source = $description ?? $node;

		// A qualified value reads as its rdf:value; the qualifiers come from
		// getQualifiers().
		foreach ($source->childNodes as $child) {
			if ($child instanceof \DOMElement && $child->namespaceURI === self::NS_RDF && $child->localName === 'value') {
				return $this->readNode($child);
			}
		}

		$structure = $this->readStructureFields($source);
		if ($structure !== []) {
			return $structure;
		}
		if ($source->getAttributeNS(self::NS_RDF, 'parseType') === 'Resource' || $description !== null) {
			return [];   // an empty structure, not a text value
		}
		return $node->textContent;
	}

	/**
	 * Reads the fields of a structure: the namespaced attribute shorthand and the
	 * child elements.
	 * @param \DOMElement $node The structure element.
	 * @return array The field-name to value map.
	 */
	protected function readStructureFields(\DOMElement $node): array
	{
		$structure = [];
		foreach ($node->attributes as $attribute) {
			if ($attribute->namespaceURI !== null
				&& $attribute->namespaceURI !== self::NS_RDF
				&& $attribute->namespaceURI !== self::NS_XML
				&& $attribute->prefix !== 'xmlns') {
				$structure[$attribute->localName] = $attribute->value;
			}
		}
		foreach ($node->childNodes as $child) {
			if ($child instanceof \DOMElement && !($child->namespaceURI === self::NS_RDF && $child->localName === 'Description')) {
				$structure[$child->localName] = $this->readNode($child);
			}
		}
		return $structure;
	}

	/**
	 * Returns the rdf:Alt, rdf:Bag, or rdf:Seq child of a property node.
	 * @param \DOMElement $node The property element.
	 * @return ?\DOMElement The collection element, or null when the value is not an array.
	 */
	protected function findCollection(\DOMElement $node): ?\DOMElement
	{
		foreach ($node->childNodes as $child) {
			if ($child instanceof \DOMElement && $child->namespaceURI === self::NS_RDF
				&& in_array($child->localName, ['Alt', 'Bag', 'Seq'], true)) {
				return $child;
			}
		}
		return null;
	}

	/**
	 * Returns the array form of a property.
	 * @param string $namespace The property namespace URI.
	 * @param string $name The property local name.
	 * @return ?string 'Alt', 'Bag', or 'Seq', or null when the property is absent or
	 *   not an array.
	 */
	public function getArrayType(string $namespace, string $name): ?string
	{
		$node = $this->findProperty($namespace, $name);
		if (!$node instanceof \DOMElement) {
			return null;
		}
		return $this->findCollection($node)?->localName;
	}

	/**
	 * Returns a property's items as a list, whatever its array form (a simple value
	 * answers as a one-item list).
	 * @param string $namespace The property namespace URI.
	 * @param string $name The property local name.
	 * @return array The items.
	 */
	public function getArrayItems(string $namespace, string $name): array
	{
		$value = $this->getProperty($namespace, $name);
		if ($value === null) {
			return [];
		}
		return is_array($value) && array_is_list($value) ? $value : [$value];
	}

	/**
	 * Appends an item to an array property, creating the array when absent.
	 * @param string $namespace The property namespace URI.
	 * @param string $name The property local name.
	 * @param mixed $value The item value (a string, a structure array, or a nested array).
	 * @param string $arrayType The array form to create: 'Bag', 'Seq', or 'Alt'. Default 'Bag'.
	 */
	public function addArrayItem(string $namespace, string $name, mixed $value, ?string $arrayType = null): void
	{
		$existing = $this->getArrayType($namespace, $name)
			?? $arrayType
			?? XMPSchemas::arrayFormOf($namespace, $name)
			?? 'Bag';
		$items = $this->getArrayItems($namespace, $name);
		$items[] = $value;
		$this->setProperty($namespace, $name, $items, $existing);
	}

	/**
	 * Reads a language-alternative property as a language map.
	 * @param string $namespace The property namespace URI.
	 * @param string $name The property local name.
	 * @return array<string, string> The language to text map ('x-default' included),
	 *   empty when the property is absent.
	 */
	public function getLangAlt(string $namespace, string $name): array
	{
		$node = $this->findProperty($namespace, $name);
		if ($node instanceof \DOMAttr) {
			return [self::DefaultLanguage => $node->value];
		}
		if (!$node instanceof \DOMElement) {
			return [];
		}
		$collection = $this->findCollection($node);
		if ($collection === null) {
			return [self::DefaultLanguage => $node->textContent];
		}
		$values = [];
		$index = 0;
		foreach ($collection->childNodes as $child) {
			if ($child instanceof \DOMElement && $child->namespaceURI === self::NS_RDF && $child->localName === 'li') {
				$lang = $child->getAttributeNS(self::NS_XML, 'lang');
				$values[$lang !== '' ? $lang : ($index === 0 ? self::DefaultLanguage : (string) $index)] = $child->textContent;
				$index++;
			}
		}
		return $values;
	}

	/**
	 * Reads one language of a language-alternative property, falling back the way the
	 * specification prescribes: the exact language, then the same primary language
	 * (`de` for `de-CH`), then `x-default`, then the first alternative.
	 * @param string $namespace The property namespace URI.
	 * @param string $name The property local name.
	 * @param string $language The requested language. Default 'x-default'.
	 * @return ?string The text, or null when the property is absent.
	 */
	public function getLangAltValue(string $namespace, string $name, string $language = self::DefaultLanguage): ?string
	{
		$values = $this->getLangAlt($namespace, $name);
		if ($values === []) {
			return null;
		}
		if (isset($values[$language])) {
			return $values[$language];
		}
		$primary = strtolower(explode('-', $language)[0]);
		foreach ($values as $lang => $text) {
			if (strtolower(explode('-', $lang)[0]) === $primary) {
				return $text;
			}
		}
		return $values[self::DefaultLanguage] ?? reset($values);
	}

	/**
	 * Writes (or removes, when empty) a language-alternative property, ordering
	 * `x-default` first as the specification requires.
	 * @param string $namespace The property namespace URI.
	 * @param string $name The property local name.
	 * @param array<string, string> $values The language to text map.
	 */
	public function setLangAlt(string $namespace, string $name, array $values): void
	{
		$this->removeProperty($namespace, $name);
		if ($values === []) {
			return;
		}
		if (isset($values[self::DefaultLanguage])) {
			$values = [self::DefaultLanguage => $values[self::DefaultLanguage]] + $values;
		}
		$prefix = $this->prefixFor($namespace);
		$description = $this->descriptionFor($namespace, $prefix);
		$property = $this->_dom->createElementNS($namespace, "$prefix:$name");
		$description->appendChild($property);
		$collection = $this->_dom->createElementNS(self::NS_RDF, 'rdf:Alt');
		$property->appendChild($collection);
		foreach ($values as $language => $text) {
			$li = $this->_dom->createElementNS(self::NS_RDF, 'rdf:li');
			$li->setAttributeNS(self::NS_XML, 'xml:lang', (string) $language);
			$li->appendChild($this->_dom->createTextNode((string) $text));
			$collection->appendChild($li);
		}
	}

	/**
	 * Returns the qualifiers of a qualified property (the fields beside its
	 * `rdf:value`).
	 * @param string $namespace The property namespace URI.
	 * @param string $name The property local name.
	 * @return array The qualifier name to value map (empty when unqualified).
	 */
	public function getQualifiers(string $namespace, string $name): array
	{
		$node = $this->findProperty($namespace, $name);
		if (!$node instanceof \DOMElement) {
			return [];
		}
		$source = $node;
		foreach ($node->childNodes as $child) {
			if ($child instanceof \DOMElement && $child->namespaceURI === self::NS_RDF && $child->localName === 'Description') {
				$source = $child;
				break;
			}
		}
		$hasValue = false;
		foreach ($source->childNodes as $child) {
			if ($child instanceof \DOMElement && $child->namespaceURI === self::NS_RDF && $child->localName === 'value') {
				$hasValue = true;
				break;
			}
		}
		if (!$hasValue) {
			return [];
		}
		$qualifiers = $this->readStructureFields($source);
		unset($qualifiers['value']);
		return $qualifiers;
	}

	/**
	 * Reads a property as a date.
	 * @param string $namespace The property namespace URI.
	 * @param string $name The property local name.
	 * @return ?\DateTimeImmutable The instant, or null when absent or unparsable.
	 */
	public function getDateProperty(string $namespace, string $name): ?\DateTimeImmutable
	{
		$value = $this->getProperty($namespace, $name);
		if (!is_string($value) || $value === '') {
			return null;
		}
		try {
			return new \DateTimeImmutable($value);
		} catch (\Exception $e) {
			return null;
		}
	}

	/**
	 * Writes (or removes, when null) a property as an ISO 8601 date.
	 * @param string $namespace The property namespace URI.
	 * @param string $name The property local name.
	 * @param ?\DateTimeInterface $value The instant, or null to remove the property.
	 */
	public function setDateProperty(string $namespace, string $name, ?\DateTimeInterface $value): void
	{
		$this->setProperty($namespace, $name, $value?->format('Y-m-d\TH:i:sP'));
	}

	/**
	 * Reads a value through an XMP path expression: `prefix:name`, array indexes
	 * (`dc:creator[1]`, one-based), and structure fields joined by `/`
	 * (`xmpMM:History[1]/stEvt:action`).
	 * @param string $path The path expression.
	 * @return mixed The value, or null when any step is absent.
	 */
	public function getByPath(string $path): mixed
	{
		$steps = explode('/', trim($path, '/'));
		$value = null;
		foreach ($steps as $index => $step) {
			if (!preg_match('/^(?:([^:\[\]]+):)?([^\[\]]+)((?:\[\d+\])*)$/', trim($step), $match)) {
				return null;
			}
			[, $prefix, $name, $indexes] = $match;
			if ($index === 0) {
				$namespace = $prefix === '' ? null : $this->namespaceFor($prefix);
				if ($namespace === null) {
					return null;
				}
				$value = $this->getProperty($namespace, $name);
			} else {
				if (!is_array($value) || !array_key_exists($name, $value)) {
					return null;
				}
				$value = $value[$name];
			}
			if ($indexes !== '' && preg_match_all('/\[(\d+)\]/', $indexes, $all)) {
				foreach ($all[1] as $position) {
					if (!is_array($value) || !array_key_exists((int) $position - 1, $value)) {
						return null;
					}
					$value = $value[(int) $position - 1];
				}
			}
		}
		return $value;
	}

	/**
	 * Enumerates every top-level property in the packet.
	 * @return array<string, mixed> The `prefix:name` to value map, in document order.
	 */
	public function getProperties(): array
	{
		$properties = [];
		foreach ($this->getPropertyNames() as [$namespace, $name]) {
			$properties[$this->prefixFor($namespace) . ':' . $name] = $this->getProperty($namespace, $name);
		}
		return $properties;
	}

	/**
	 * Enumerates the namespace and name of every top-level property.
	 * @return array<int, array{0: string, 1: string}> The [namespace, name] pairs.
	 */
	public function getPropertyNames(): array
	{
		$names = [];
		$seen = [];
		foreach ($this->getDescriptions() as $description) {
			foreach ($description->attributes as $attribute) {
				if ($attribute->namespaceURI !== null
					&& $attribute->namespaceURI !== self::NS_RDF
					&& $attribute->namespaceURI !== self::NS_XML
					&& $attribute->prefix !== 'xmlns') {
					$key = $attribute->namespaceURI . ' ' . $attribute->localName;
					if (!isset($seen[$key])) {
						$seen[$key] = true;
						$names[] = [$attribute->namespaceURI, $attribute->localName];
					}
				}
			}
			foreach ($description->childNodes as $child) {
				if ($child instanceof \DOMElement && $child->namespaceURI !== null) {
					$key = $child->namespaceURI . ' ' . $child->localName;
					if (!isset($seen[$key])) {
						$seen[$key] = true;
						$names[] = [$child->namespaceURI, $child->localName];
					}
				}
			}
		}
		return $names;
	}

	/**
	 * Removes a property.
	 * @param string $namespace The property namespace URI.
	 * @param string $name The property local name.
	 * @return bool Whether a property was removed.
	 */
	public function removeProperty(string $namespace, string $name): bool
	{
		$removed = false;
		while (($existing = $this->findProperty($namespace, $name)) !== null) {
			if ($existing instanceof \DOMAttr) {
				$existing->ownerElement->removeAttributeNode($existing);
			} else {
				$existing->parentNode->removeChild($existing);
			}
			$removed = true;
		}
		return $removed;
	}

	//
	// ─── Privacy ─────────────────────────────────────────────────────────────
	//

	/**
	 * @var array<int, array<int, array{0: string, 1: string}>> The XMP properties each
	 *   {@see PrivacyCategory} flag removes, as [namespace, property] pairs — the same
	 *   facts the EXIF and IPTC scrubs remove, in their XMP schema homes.
	 */
	protected const PrivacyProperties = [
		PrivacyCategory::Location => [
			[self::NS_EXIF, 'GPSLatitude'], [self::NS_EXIF, 'GPSLongitude'], [self::NS_EXIF, 'GPSAltitude'],
			[self::NS_EXIF, 'GPSAltitudeRef'], [self::NS_EXIF, 'GPSTimeStamp'], [self::NS_EXIF, 'GPSVersionID'],
			[self::NS_EXIF, 'GPSMapDatum'], [self::NS_EXIF, 'GPSImgDirection'], [self::NS_EXIF, 'GPSImgDirectionRef'],
			[self::NS_EXIF, 'GPSDestLatitude'], [self::NS_EXIF, 'GPSDestLongitude'], [self::NS_EXIF, 'GPSAreaInformation'],
			[self::NS_EXIF, 'GPSProcessingMethod'], [self::NS_EXIF, 'GPSSpeed'], [self::NS_EXIF, 'GPSSpeedRef'],
			[self::NS_EXIF, 'GPSTrack'], [self::NS_EXIF, 'GPSTrackRef'], [self::NS_EXIF, 'GPSDifferential'],
			[self::NS_EXIF, 'GPSHPositioningError'],
			[self::NS_PHOTOSHOP, 'City'], [self::NS_PHOTOSHOP, 'State'], [self::NS_PHOTOSHOP, 'Country'],
			[self::NS_IPTC_CORE, 'Location'], [self::NS_IPTC_CORE, 'CountryCode'],
			[self::NS_IPTC_EXT, 'LocationCreated'], [self::NS_IPTC_EXT, 'LocationShown'],
		],
		PrivacyCategory::Author => [
			[self::NS_DC, 'creator'], [self::NS_DC, 'rights'], [self::NS_DC, 'publisher'], [self::NS_DC, 'contributor'],
			[self::NS_RIGHTS, 'Owner'], [self::NS_RIGHTS, 'UsageTerms'], [self::NS_RIGHTS, 'WebStatement'],
			[self::NS_RIGHTS, 'Marked'], [self::NS_RIGHTS, 'Certificate'],
			[self::NS_PHOTOSHOP, 'Credit'], [self::NS_PHOTOSHOP, 'Source'], [self::NS_PHOTOSHOP, 'AuthorsPosition'],
			[self::NS_PHOTOSHOP, 'CaptionWriter'],
			[self::NS_IPTC_CORE, 'CreatorContactInfo'],
			[self::NS_IPTC_EXT, 'PersonInImage'], [self::NS_IPTC_EXT, 'PersonInImageWDetails'],
			[self::NS_PLUS, 'ImageCreator'], [self::NS_PLUS, 'ImageSupplier'], [self::NS_PLUS, 'CopyrightOwner'],
			[self::NS_PLUS, 'Licensor'], [self::NS_PLUS, 'ImageSupplierImageID'],
		],
		PrivacyCategory::Description => [
			[self::NS_DC, 'title'], [self::NS_DC, 'description'], [self::NS_DC, 'subject'],
			[self::NS_PHOTOSHOP, 'Headline'], [self::NS_PHOTOSHOP, 'Instructions'], [self::NS_PHOTOSHOP, 'Category'],
			[self::NS_PHOTOSHOP, 'SupplementalCategories'], [self::NS_PHOTOSHOP, 'TransmissionReference'],
			[self::NS_IPTC_CORE, 'SubjectCode'], [self::NS_IPTC_CORE, 'IntellectualGenre'], [self::NS_IPTC_CORE, 'Scene'],
			[self::NS_IPTC_EXT, 'Event'], [self::NS_IPTC_EXT, 'AdditionalModelInformation'],
			[self::NS_XMP, 'Label'], [self::NS_XMP, 'Rating'], [self::NS_XMP, 'Nickname'],
			[self::NS_EXIF, 'UserComment'], [self::NS_TIFF, 'ImageDescription'],
		],
		PrivacyCategory::CameraModel => [
			[self::NS_TIFF, 'Make'], [self::NS_TIFF, 'Model'],
			[self::NS_EXIF, 'LensMake'], [self::NS_EXIF, 'LensModel'], [self::NS_EXIF, 'LensSpecification'],
			[self::NS_EXIFEX, 'LensMake'], [self::NS_EXIFEX, 'LensModel'], [self::NS_EXIFEX, 'LensSpecification'],
			[self::NS_EXIF_AUX, 'Lens'], [self::NS_EXIF_AUX, 'LensInfo'], [self::NS_EXIF_AUX, 'LensID'],
		],
		PrivacyCategory::SerialNumber => [
			[self::NS_EXIF, 'BodySerialNumber'], [self::NS_EXIF, 'LensSerialNumber'], [self::NS_EXIF, 'ImageUniqueID'],
			[self::NS_EXIFEX, 'BodySerialNumber'], [self::NS_EXIFEX, 'LensSerialNumber'],
			[self::NS_EXIF_AUX, 'SerialNumber'], [self::NS_EXIF_AUX, 'LensSerialNumber'], [self::NS_EXIF_AUX, 'ImageNumber'],
			[self::NS_MM, 'DocumentID'], [self::NS_MM, 'InstanceID'], [self::NS_MM, 'OriginalDocumentID'],
			[self::NS_MM, 'DerivedFrom'], [self::NS_MM, 'History'], [self::NS_MM, 'Ingredients'],
			[self::NS_MM, 'Pantry'], [self::NS_MM, 'ManageTo'], [self::NS_MM, 'ManageUI'], [self::NS_MM, 'Manager'],
			[self::NS_MM, 'ManagedFrom'], [self::NS_MM, 'RenditionOf'], [self::NS_MM, 'VersionID'], [self::NS_MM, 'Versions'],
			[self::NS_IPTC_EXT, 'DigitalImageGUID'], [self::NS_IPTC_EXT, 'RegistryId'],
		],
		PrivacyCategory::Timestamp => [
			[self::NS_XMP, 'CreateDate'], [self::NS_XMP, 'ModifyDate'], [self::NS_XMP, 'MetadataDate'],
			[self::NS_EXIF, 'DateTimeOriginal'], [self::NS_EXIF, 'DateTimeDigitized'],
			[self::NS_EXIF, 'OffsetTime'], [self::NS_EXIF, 'OffsetTimeOriginal'], [self::NS_EXIF, 'OffsetTimeDigitized'],
			[self::NS_EXIFEX, 'OffsetTime'], [self::NS_EXIFEX, 'OffsetTimeOriginal'], [self::NS_EXIFEX, 'OffsetTimeDigitized'],
			[self::NS_TIFF, 'DateTime'], [self::NS_PHOTOSHOP, 'DateCreated'],
		],
		PrivacyCategory::Software => [
			[self::NS_XMP, 'CreatorTool'], [self::NS_TIFF, 'Software'],
			[self::NS_EXIF, 'CameraFirmware'], [self::NS_EXIFEX, 'CameraFirmware'],
			[self::NS_EXIF_AUX, 'Firmware'],
			[self::NS_PDF, 'Producer'], [self::NS_PDF, 'Creator'],
		],
		PrivacyCategory::Thumbnail => [
			[self::NS_XMP, 'Thumbnails'],
		],
	];

	/**
	 * Removes identifying information from the packet by category: the XMP homes of the
	 * same facts the EXIF and IPTC scrubs remove — location, authorship and rights,
	 * descriptive text, camera and lens identity, serial and document identifiers,
	 * timestamps, software, and embedded thumbnails.  Every namespaced instance of a
	 * listed property is removed (element or attribute form, in any `rdf:Description`).
	 * Properties that describe the picture — exposure, colour, dimensions, rendering
	 * settings — are left, so the packet remains a useful description of the image.
	 * @param int $types The {@see PrivacyCategory} flags to remove. Default {@see PrivacyCategory::All}.
	 * @return int The number of properties removed.
	 */
	public function clearPrivateData(int $types = PrivacyCategory::All): int
	{
		$removed = 0;
		foreach (self::PrivacyProperties as $flag => $properties) {
			if (($types & $flag) === 0) {
				continue;
			}
			foreach ($properties as [$namespace, $name]) {
				if ($this->removeProperty($namespace, $name)) {
					$removed++;
				}
			}
		}
		return $removed;
	}

	/**
	 * Writes (or removes, when null) a property.  A string becomes a simple element; a
	 * list array becomes an rdf collection of `$arrayType` whose items may themselves
	 * be structures or arrays; a string-keyed array becomes an
	 * `rdf:parseType="Resource"` structure, nested to any depth.
	 * @param string $namespace The property namespace URI.
	 * @param string $name The property local name.
	 * When {@see XMPSchemas} names the property, its schema-defined form wins unless
	 * the caller passes an explicit `$arrayType`: `dc:subject` becomes a Bag,
	 * `dc:creator` a Seq, and a language-alternative property such as `dc:title`
	 * becomes an `rdf:Alt` of `xml:lang` items even when given a bare string.
	 * @param null|array|bool|float|int|string $value The value, or null to remove.
	 * @param ?string $arrayType The collection form for list arrays: 'Bag', 'Seq', or
	 *   'Alt'; null takes the schema's form, falling back to 'Bag'.
	 */
	public function setProperty(string $namespace, string $name, null|string|int|float|bool|array $value, ?string $arrayType = null): void
	{
		if ($value !== null && $arrayType === null && XMPSchemas::isLangAlt($namespace, $name)) {
			$this->setLangAlt($namespace, $name, $this->langMap(is_array($value) ? $value : (string) $value));
			return;
		}
		$arrayType ??= XMPSchemas::arrayFormOf($namespace, $name) ?? 'Bag';
		$this->removeProperty($namespace, $name);
		if ($value === null) {
			return;
		}
		$prefix = $this->prefixFor($namespace);
		$description = $this->descriptionFor($namespace, $prefix);
		$property = $this->_dom->createElementNS($namespace, "$prefix:$name");
		$description->appendChild($property);
		$this->writeValue($property, $namespace, $prefix, $value, $arrayType);
	}

	/**
	 * Writes a value into a property (or item) element, recursively.
	 * @param \DOMElement $element The target element.
	 * @param string $namespace The namespace of the value's fields.
	 * @param string $prefix The prefix of that namespace.
	 * @param mixed $value The value.
	 * @param string $arrayType The collection form for a list value.
	 */
	protected function writeValue(\DOMElement $element, string $namespace, string $prefix, mixed $value, string $arrayType = 'Seq'): void
	{
		if (is_bool($value)) {
			$value = $value ? 'True' : 'False';
		}
		if (!is_array($value)) {
			$element->appendChild($this->_dom->createTextNode((string) $value));
			return;
		}
		if ($value !== [] && !array_is_list($value)) {
			$element->setAttributeNS(self::NS_RDF, 'rdf:parseType', 'Resource');
			foreach ($value as $fieldName => $fieldValue) {
				[$fieldNamespace, $fieldPrefix, $localName] = $this->resolveFieldName((string) $fieldName, $namespace, $prefix);
				$field = $this->_dom->createElementNS($fieldNamespace, "$fieldPrefix:$localName");
				$element->appendChild($field);
				$this->writeValue($field, $fieldNamespace, $fieldPrefix, $fieldValue);
			}
			return;
		}
		$form = in_array($arrayType, ['Alt', 'Bag', 'Seq'], true) ? $arrayType : 'Bag';
		$collection = $this->_dom->createElementNS(self::NS_RDF, 'rdf:' . $form);
		$element->appendChild($collection);
		foreach (array_values($value) as $index => $item) {
			$li = $this->_dom->createElementNS(self::NS_RDF, 'rdf:li');
			if ($form === 'Alt' && $index === 0 && !is_array($item)) {
				$li->setAttributeNS(self::NS_XML, 'xml:lang', self::DefaultLanguage);
			}
			$collection->appendChild($li);
			$this->writeValue($li, $namespace, $prefix, $item);
		}
	}

	/**
	 * Resolves a structure field name, which may carry its own `prefix:` (as the
	 * `stEvt:`/`stRef:` structures do).
	 * @param string $fieldName The field name, with or without a prefix.
	 * @param string $namespace The enclosing namespace.
	 * @param string $prefix The enclosing prefix.
	 * @return array{0: string, 1: string, 2: string} The [namespace, prefix, localName] triple.
	 */
	protected function resolveFieldName(string $fieldName, string $namespace, string $prefix): array
	{
		if (str_contains($fieldName, ':')) {
			[$fieldPrefix, $localName] = explode(':', $fieldName, 2);
			$fieldNamespace = $this->namespaceFor($fieldPrefix);
			if ($fieldNamespace !== null) {
				return [$fieldNamespace, $fieldPrefix, $localName];
			}
			return [$namespace, $prefix, $localName];
		}
		return [$namespace, $prefix, $fieldName];
	}

	/**
	 * Returns (creating on demand) an rdf:Description declaring a namespace.
	 * @param string $namespace The namespace URI.
	 * @param string $prefix The prefix to declare it under when creating.
	 * @return \DOMElement The description element.
	 */
	protected function descriptionFor(string $namespace, string $prefix): \DOMElement
	{
		$xpath = new \DOMXPath($this->_dom);
		foreach ($this->getDescriptions() as $description) {
			foreach ($xpath->query('namespace::*', $description) as $node) {
				if ($node->nodeValue === $namespace) {
					return $description;
				}
			}
		}
		$rdf = $this->getRdf();
		$description = $this->_dom->createElementNS(self::NS_RDF, 'rdf:Description');
		$description->setAttributeNS(self::NS_RDF, 'rdf:about', '');
		$description->setAttributeNS('http://www.w3.org/2000/xmlns/', "xmlns:$prefix", $namespace);
		$rdf->appendChild($description);
		return $description;
	}

	/**
	 * Returns the document title (dc:title), in the default language.
	 * @param string $language The language to select. Default 'x-default'.
	 * @return ?string The title, or null.
	 */
	public function getTitle(string $language = self::DefaultLanguage): ?string
	{
		return $this->getLangAltValue(self::NS_DC, 'title', $language);
	}

	/**
	 * Sets (or removes, when null) the document title (dc:title, a language alternative).
	 * @param null|array|string $value The title text, a language map, or null.
	 */
	public function setTitle(null|string|array $value): void
	{
		$this->setLangAlt(self::NS_DC, 'title', $this->langMap($value));
	}

	/**
	 * Returns the description/caption (dc:description), in the default language.
	 * @param string $language The language to select. Default 'x-default'.
	 * @return ?string The description, or null.
	 */
	public function getDescription(string $language = self::DefaultLanguage): ?string
	{
		return $this->getLangAltValue(self::NS_DC, 'description', $language);
	}

	/**
	 * Sets (or removes, when null) the description/caption (dc:description, a language
	 * alternative).
	 * @param null|array|string $value The description text, a language map, or null.
	 */
	public function setDescription(null|string|array $value): void
	{
		$this->setLangAlt(self::NS_DC, 'description', $this->langMap($value));
	}

	/**
	 * Returns the creators (dc:creator).
	 * @return string[] The creators, in order.
	 */
	public function getCreators(): array
	{
		$value = $this->getProperty(self::NS_DC, 'creator');
		return is_array($value) ? array_values(array_filter($value, 'is_string')) : ($value === null ? [] : [$value]);
	}

	/**
	 * Sets (or removes, when empty) the creators (dc:creator, a Seq).
	 * @param string[] $value The creators.
	 */
	public function setCreators(array $value): void
	{
		$this->setProperty(self::NS_DC, 'creator', $value === [] ? null : $value, 'Seq');
	}

	/**
	 * Returns the keywords (dc:subject).
	 * @return string[] The keywords.
	 */
	public function getKeywords(): array
	{
		$value = $this->getProperty(self::NS_DC, 'subject');
		return is_array($value) ? array_values(array_filter($value, 'is_string')) : ($value === null ? [] : [$value]);
	}

	/**
	 * Sets (or removes, when empty) the keywords (dc:subject, a Bag).
	 * @param string[] $value The keywords.
	 */
	public function setKeywords(array $value): void
	{
		$this->setProperty(self::NS_DC, 'subject', $value === [] ? null : $value, 'Bag');
	}

	/**
	 * Returns the rights/copyright notice (dc:rights), in the default language.
	 * @param string $language The language to select. Default 'x-default'.
	 * @return ?string The rights text, or null.
	 */
	public function getRights(string $language = self::DefaultLanguage): ?string
	{
		return $this->getLangAltValue(self::NS_DC, 'rights', $language);
	}

	/**
	 * Sets (or removes, when null) the rights/copyright notice (dc:rights, a language
	 * alternative).
	 * @param null|array|string $value The rights text, a language map, or null.
	 */
	public function setRights(null|string|array $value): void
	{
		$this->setLangAlt(self::NS_DC, 'rights', $this->langMap($value));
	}

	/**
	 * Normalizes a language-alternative argument to a language map.
	 * @param null|array|string $value The text, a language map, or null.
	 * @return array<string, string> The language map (empty for null).
	 */
	protected function langMap(null|string|array $value): array
	{
		if ($value === null) {
			return [];
		}
		if (is_string($value)) {
			return [self::DefaultLanguage => $value];
		}
		return array_is_list($value)
			? ($value === [] ? [] : [self::DefaultLanguage => (string) $value[0]])
			: $value;
	}

	/**
	 * Serializes the packet body (the x:xmpmeta element, no xpacket wrapper).
	 * @return string The RDF/XML.
	 */
	public function toXml(): string
	{
		return (string) $this->_dom->saveXML($this->_dom->documentElement);
	}

	/**
	 * Serializes the full xpacket: the begin processing instruction (the UTF-8
	 * signature character and the writable-packet id), the packet body, whitespace
	 * padding, and the end instruction — `end="w"` for a writable packet, `end="r"`
	 * for a read-only one.
	 * @param bool $pad Whether to append the {@see getPacketPadding() padding}. Default true.
	 * @return string The packet text.
	 */
	public function toPacketText(bool $pad = true): string
	{
		$padding = '';
		if ($pad && $this->_packetPadding > 0) {
			$padding = str_repeat(str_repeat(' ', 63) . "\n", max(1, intdiv($this->_packetPadding, 64)));
		}
		return "<?xpacket begin=\"\u{FEFF}\" id=\"" . self::XPACKET_ID . "\"?>\n"
			. $this->toXml() . "\n"
			. $padding
			. '<?xpacket end="' . ($this->_writable ? 'w' : 'r') . '"?>';
	}

	/**
	 * Serializes the full xpacket.
	 * @return string The packet text.
	 */
	public function toBinary(): string
	{
		return $this->toPacketText();
	}

	/**
	 * Returns the schema-defined form of a property ({@see XMPSchemas}).
	 * @param string $namespace The property namespace URI.
	 * @param string $name The property local name.
	 * @return ?string The form constant, or null when no schema names the property.
	 */
	public function schemaFormOf(string $namespace, string $name): ?string
	{
		return XMPSchemas::formOf($namespace, $name);
	}

	/**
	 * Checks every property against its schema's defined form.
	 * @return array<string, string> The `prefix:name` to complaint map, empty when the
	 *   packet agrees with the schemas it uses.
	 */
	public function validate(): array
	{
		$problems = [];
		foreach ($this->getPropertyNames() as [$namespace, $name]) {
			$form = XMPSchemas::formOf($namespace, $name);
			if ($form === null) {
				continue;
			}
			$label = $this->prefixFor($namespace) . ":$name";
			$value = $this->getProperty($namespace, $name);
			$actualArray = $this->getArrayType($namespace, $name);
			$expectedArray = XMPSchemas::arrayFormOf($namespace, $name);

			if ($expectedArray !== null) {
				if ($actualArray === null) {
					$problems[$label] = "expected an rdf:$expectedArray array";
				} elseif ($actualArray !== $expectedArray) {
					$problems[$label] = "expected an rdf:$expectedArray array, found rdf:$actualArray";
				} elseif ($form === XMPSchemas::LangAlt && $this->getLangAlt($namespace, $name) === []) {
					$problems[$label] = 'expected language alternatives';
				}
				continue;
			}
			if ($form === XMPSchemas::Struct && !(is_array($value) && !array_is_list($value))) {
				$problems[$label] = 'expected a structure';
			} elseif ($form === XMPSchemas::Simple && is_array($value)) {
				$problems[$label] = 'expected a simple value';
			}
		}
		return $problems;
	}

	/**
	 * Merges another packet's properties into this one, property by property, adding
	 * the namespaces they need — the operation that rejoins an extended XMP packet
	 * with its main one.
	 * @param XMP $other The packet to merge in.
	 * @param bool $overwrite Whether to replace properties this packet already has.
	 *   Default true.
	 */
	public function merge(XMP $other, bool $overwrite = true): void
	{
		foreach ($other->getPropertyNames() as [$namespace, $name]) {
			if (!$overwrite && $this->containsProperty($namespace, $name)) {
				continue;
			}
			$node = $other->findProperty($namespace, $name);
			if ($node === null) {
				continue;
			}
			$this->removeProperty($namespace, $name);
			$prefix = $this->prefixFor($namespace);
			$description = $this->descriptionFor($namespace, $prefix);
			if ($node instanceof \DOMAttr) {
				$description->setAttributeNS($namespace, "$prefix:$name", $node->value);
			} else {
				$description->appendChild($this->_dom->importNode($node, true));
			}
		}
	}
}
