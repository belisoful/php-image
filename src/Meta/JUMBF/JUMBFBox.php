<?php

/**
 * JUMBFBox class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\Meta\JUMBF;

use Belisoful\Image\StreamIOTrait;

/**
 * JUMBFBox class.
 *
 * One box of the JPEG Universal Metadata Box Format (ISO/IEC 19566-5), the
 * box-structured metadata a JPEG carries in its APP11 segments and the shape Exif 3.0
 * defines for annotation data: a big-endian length, a four-character
 * {@see getType() type}, and a payload — with the 64-bit extended length used
 * automatically when a box outgrows 32 bits.
 *
 * A {@see SuperBox} (`jumb`) holds child boxes instead of opaque bytes: a
 * {@see getDescription() description box} (`jumd`, see {@see JUMBFDescription})
 * naming the content, then the content boxes themselves.  {@see xml()},
 * {@see json()}, and {@see exifAnnotation()} build the common superboxes in one call,
 * and {@see getContentData()} reads the payload straight back.
 *
 * ```php
 * $box = JUMBFBox::xml('exif-annotation', '<rdf:RDF …/>');
 * $jpeg->setJumbfBoxes([$box]);                       // written as APP11 segments
 *
 * foreach ($jpeg->getJumbfBoxes() as $box) {
 *     $box->getLabel();                               // 'exif-annotation'
 *     $box->getContentType();                         // 'xml '
 *     $box->getContentData();                         // the XML text
 * }
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @see https://www.iso.org/standard/73604.html ISO/IEC 19566-5 (JUMBF)
 */
class JUMBFBox
{
	use StreamIOTrait;

	/** The superbox type, whose payload is a sequence of child boxes. */
	public const SuperBox = 'jumb';

	/** The description box type, the first child of every superbox. */
	public const DescriptionBox = 'jumd';

	/** The XML content box type (note the trailing space). */
	public const XmlBox = 'xml ';

	/** The JSON (and JSON-LD) content box type. */
	public const JsonBox = 'json';

	/** The CBOR content box type. */
	public const CborBox = 'cbor';

	/** The codestream content box type. */
	public const CodestreamBox = 'jp2c';

	/** The UUID content box type. */
	public const UuidBox = 'uuid';

	/** The embedded-file description box type. */
	public const EmbeddedFileDescriptionBox = 'bfdb';

	/** The embedded-file data box type. */
	public const EmbeddedFileDataBox = 'bidb';

	/** @var int The recursion depth honored when parsing nested superboxes. */
	protected const MaxDepth = 16;

	/** @var string The four-character box type. */
	private string $_type;

	/** @var string The payload of a non-superbox. */
	private string $_payload = '';

	/** @var JUMBFBox[] The child boxes of a superbox. */
	private array $_children = [];

	/**
	 * Constructs a box.
	 * @param string $type The four-character type.
	 * @param string $payload The payload (ignored for a superbox with children).
	 * @param JUMBFBox[] $children The child boxes, for a superbox.
	 */
	final public function __construct(string $type = self::SuperBox, string $payload = '', array $children = [])
	{
		$this->_type = substr(str_pad($type, 4), 0, 4);
		$this->_payload = $payload;
		$this->_children = $children;
	}

	/**
	 * Parses a sequence of boxes.
	 * @param string $bytes The box bytes.
	 * @param int $depth The current nesting depth.
	 * @return static[] The parsed boxes (empty when nothing parses).
	 */
	public static function parseBoxes(string $bytes, int $depth = 0): array
	{
		$boxes = [];
		$len = strlen($bytes);
		$pos = 0;
		while ($pos + 8 <= $len) {
			$boxLength = unpack('N', substr($bytes, $pos, 4))[1];
			$type = substr($bytes, $pos + 4, 4);
			$headerSize = 8;
			if ($boxLength === 1) {
				if ($pos + 16 > $len) {
					break;
				}
				$high = unpack('N', substr($bytes, $pos + 8, 4))[1];
				$low = unpack('N', substr($bytes, $pos + 12, 4))[1];
				$boxLength = ($high << 32) | $low;
				$headerSize = 16;
			} elseif ($boxLength === 0) {
				$boxLength = $len - $pos;   // to the end of the data
			}
			if ($boxLength < $headerSize || $pos + $boxLength > $len) {
				break;
			}
			$payload = substr($bytes, $pos + $headerSize, $boxLength - $headerSize);
			$box = new static($type);
			if ($type === self::SuperBox && $depth < static::MaxDepth) {
				$box->setChildren(static::parseBoxes($payload, $depth + 1));
			} else {
				$box->setPayload($payload);
			}
			$boxes[] = $box;
			$pos += $boxLength;
		}
		return $boxes;
	}

	/**
	 * Parses the first box of a byte string.
	 * @param string $bytes The box bytes.
	 * @return false|static The box, or false when nothing parses.
	 */
	public static function parse(string $bytes): false|static
	{
		$boxes = static::parseBoxes($bytes);
		return $boxes === [] ? false : $boxes[0];
	}

	/**
	 * Parses boxes from a PSR-7 stream or stream resource.
	 * @param mixed $stream The {@see \Psr\Http\Message\StreamInterface} or PHP stream resource.
	 * @return false|static The first box, or false when nothing parses.
	 */
	public static function fromStream(mixed $stream): false|static
	{
		return static::parse(static::sourceBytes($stream));
	}

	/**
	 * Builds a superbox from a description and its content boxes.
	 * @param JUMBFDescription $description The description.
	 * @param JUMBFBox[] $content The content boxes.
	 * @return static The superbox.
	 */
	public static function superBox(JUMBFDescription $description, array $content): static
	{
		$children = [new static(self::DescriptionBox, $description->toBinary())];
		foreach ($content as $box) {
			$children[] = $box;
		}
		return new static(self::SuperBox, '', $children);
	}

	/**
	 * Builds a labelled superbox carrying an XML document.
	 * @param string $label The content label.
	 * @param string $xml The XML text.
	 * @return static The superbox.
	 */
	public static function xml(string $label, string $xml): static
	{
		return static::superBox(
			new JUMBFDescription(JUMBFDescription::XmlUuid, $label),
			[new static(self::XmlBox, $xml)],
		);
	}

	/**
	 * Builds a labelled superbox carrying a JSON (or JSON-LD) document.
	 * @param string $label The content label.
	 * @param string $json The JSON text.
	 * @return static The superbox.
	 */
	public static function json(string $label, string $json): static
	{
		return static::superBox(
			new JUMBFDescription(JUMBFDescription::JsonUuid, $label),
			[new static(self::JsonBox, $json)],
		);
	}

	/**
	 * Builds the Exif annotation superbox of CIPA DC-008: the Exif content-type UUID
	 * with an XML or JSON content box.
	 * @param string $label The content label.
	 * @param string $data The annotation document.
	 * @param string $contentType The content box type; {@see XmlBox} or {@see JsonBox}.
	 *   Default {@see XmlBox}.
	 * @return static The superbox.
	 */
	public static function exifAnnotation(string $label, string $data, string $contentType = self::XmlBox): static
	{
		return static::superBox(
			new JUMBFDescription(JUMBFDescription::ExifUuid, $label),
			[new static($contentType, $data)],
		);
	}

	/**
	 * Returns the four-character box type.
	 * @return string The type.
	 */
	public function getType(): string
	{
		return $this->_type;
	}

	/**
	 * Sets the four-character box type.
	 * @param string $value The type.
	 */
	public function setType(string $value): void
	{
		$this->_type = substr(str_pad($value, 4), 0, 4);
	}

	/**
	 * Returns the payload of a non-superbox.
	 * @return string The payload bytes.
	 */
	public function getPayload(): string
	{
		return $this->_payload;
	}

	/**
	 * Sets the payload of a non-superbox.
	 * @param string $value The payload bytes.
	 */
	public function setPayload(string $value): void
	{
		$this->_payload = $value;
	}

	/**
	 * Returns the child boxes of a superbox.
	 * @return JUMBFBox[] The children.
	 */
	public function getChildren(): array
	{
		return $this->_children;
	}

	/**
	 * Sets the child boxes of a superbox.
	 * @param JUMBFBox[] $value The children.
	 */
	public function setChildren(array $value): void
	{
		$this->_children = $value;
	}

	/**
	 * Appends a child box.
	 * @param JUMBFBox $box The child.
	 */
	public function addChild(JUMBFBox $box): void
	{
		$this->_children[] = $box;
	}

	/**
	 * Indicates whether the box is a superbox.
	 * @return bool Whether the type is {@see SuperBox}.
	 */
	public function getIsSuperBox(): bool
	{
		return $this->_type === self::SuperBox;
	}

	/**
	 * Returns the description of a superbox.
	 * @return ?JUMBFDescription The description, or null when absent or unparsable.
	 */
	public function getDescription(): ?JUMBFDescription
	{
		foreach ($this->_children as $child) {
			if ($child->getType() === self::DescriptionBox) {
				$description = JUMBFDescription::parse($child->getPayload());
				return $description === false ? null : $description;
			}
		}
		return null;
	}

	/**
	 * Returns the label of a superbox's description.
	 * @return ?string The label, or null when absent.
	 */
	public function getLabel(): ?string
	{
		return $this->getDescription()?->getLabel();
	}

	/**
	 * Returns a superbox's content boxes (every child but the description).
	 * @return JUMBFBox[] The content boxes.
	 */
	public function getContentBoxes(): array
	{
		return array_values(array_filter($this->_children, fn ($c) => $c->getType() !== self::DescriptionBox));
	}

	/**
	 * Returns the type of a superbox's first content box.
	 * @return ?string The content box type, or null when there is none.
	 */
	public function getContentType(): ?string
	{
		return ($this->getContentBoxes()[0] ?? null)?->getType();
	}

	/**
	 * Returns the payload of a superbox's first content box (or this box's own payload
	 * when it is not a superbox).
	 * @return ?string The content bytes, or null when there is none.
	 */
	public function getContentData(): ?string
	{
		if (!$this->getIsSuperBox()) {
			return $this->_payload;
		}
		return ($this->getContentBoxes()[0] ?? null)?->getPayload();
	}

	/**
	 * Packs the box (and any children) back to bytes, choosing the 64-bit extended
	 * length when the box outgrows 32 bits.
	 * @return string The box bytes.
	 */
	public function toBinary(): string
	{
		$payload = $this->_payload;
		if ($this->getIsSuperBox()) {
			$payload = '';
			foreach ($this->_children as $child) {
				$payload .= $child->toBinary();
			}
		}
		$length = 8 + strlen($payload);
		if ($length > 0xFFFFFFFF) {
			$length += 8;
			return pack('N', 1) . $this->_type . pack('NN', $length >> 32, $length & 0xFFFFFFFF) . $payload;
		}
		return pack('N', $length) . $this->_type . $payload;
	}
}
