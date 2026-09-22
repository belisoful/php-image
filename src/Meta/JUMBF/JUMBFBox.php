<?php

/**
 * JUMBFBox class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\Meta\JUMBF;

use Belisoful\Image\BMFF\BMFFBox;

/**
 * JUMBFBox class.
 *
 * One box of the JPEG Universal Metadata Box Format (ISO/IEC 19566-5), the box-structured
 * metadata a JPEG carries in its APP11 segments and the shape Exif 3.0 defines for
 * annotation data.  JUMBF is written in the ISO base media box grammar, so the parsing and
 * composing — the 32-bit length, the 64-bit extended length, the run-to-the-end length, and
 * the nesting — come from {@see BMFFBox}; what this class adds is what JUMBF means by
 * those boxes.
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
class JUMBFBox extends BMFFBox
{
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

	/** The embedded-file description box type. */
	public const EmbeddedFileDescriptionBox = 'bfdb';

	/** The embedded-file data box type. */
	public const EmbeddedFileDataBox = 'bidb';

	/** The only JUMBF box whose payload is a sequence of child boxes. */
	public const ContainerTypes = [self::SuperBox];

	/**
	 * Constructs a box, defaulting to the superbox JUMBF is built out of.
	 * @param string $type The four-character type.
	 * @param string $payload The payload (ignored for a superbox with children).
	 * @param array<int, \Belisoful\Image\BMFF\BMFFBox> $children The child boxes, for a superbox.
	 */
	final public function __construct(string $type = self::SuperBox, string $payload = '', array $children = [])
	{
		parent::__construct($type, $payload, $children);
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
	 * Indicates whether the box is a superbox, the JUMBF name for the one box type whose
	 * payload is a sequence of children.
	 * @return bool Whether the type is {@see SuperBox}.
	 */
	public function getIsSuperBox(): bool
	{
		return $this->getIsContainer();
	}

	/**
	 * Returns the description of a superbox.
	 * @return ?JUMBFDescription The description, or null when absent or unparsable.
	 */
	public function getDescription(): ?JUMBFDescription
	{
		foreach ($this->getChildren() as $child) {
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
	 * @return array<int, \Belisoful\Image\BMFF\BMFFBox> The content boxes.
	 */
	public function getContentBoxes(): array
	{
		return array_values(array_filter($this->getChildren(), fn ($c) => $c->getType() !== self::DescriptionBox));
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
			return $this->getPayload();
		}
		return ($this->getContentBoxes()[0] ?? null)?->getPayload();
	}

}
