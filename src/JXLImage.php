<?php

/**
 * JXLImage class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image;

use Belisoful\Image\BMFF\BMFFBox;
use Belisoful\Image\Meta\EXIF;
use Belisoful\Image\Meta\IPTC;
use Belisoful\Image\Meta\JUMBF\JUMBFBox;
use Belisoful\Image\Meta\XMP;

/**
 * JXLImage class.
 *
 * Reads and writes the metadata of a JPEG XL image (ISO/IEC 18181-2), which comes in two
 * shapes: a **bare codestream** beginning `FF 0A`, which has no box structure and so no
 * metadata at all, and a **container**, a flat sequence of ISO base media boxes beginning
 * with the 12-byte `JXL ` signature box.  The boxes are read through {@see BMFFBox}; JXL
 * declares no container boxes, so the base grammar is exactly right and the pixels are
 * never decoded.
 *
 * The carriers are:
 * - **`Exif`** — a four-byte big-endian offset to the TIFF header, then the TIFF block.
 *   The offset is almost always zero; it is honoured on read and written as zero.
 * - **`xml `** — the XMP packet.
 * - **`jumb`** — JUMBF boxes, the same {@see JUMBFBox} a JPEG carries in APP11.
 *
 * A JXL ICC profile lives **inside the codestream**, not in a box, and JXL defines no IPTC
 * carrier, so {@see setICCProfile()} and {@see setIPTC()} throw rather than accept data
 * they would drop.
 *
 * **Setting metadata on a bare codestream promotes it to a container** rather than failing:
 * the codestream is carried verbatim into a `jxlc` box behind a signature and `ftyp`, which
 * loses nothing.  New boxes are placed before the codestream, where a decoder meets them
 * first.
 *
 * Two deliberate limits, both about things this library will not guess at:
 * - A **`brob`** box is a Brotli-compressed box whose first four bytes name the type it
 *   stands for.  Brotli is not decompressed here (PHP has no bundled Brotli), so a carrier
 *   that exists only in compressed form reads as absent; {@see getHasBrotliCarrier()} says
 *   when that is the case, rather than leaving a caller to assume the file is empty.
 *   Writing a carrier **removes** the `brob` that stood for it, so a file never ends up
 *   holding two copies that disagree.
 * The pixel dimensions come from the codestream's bit-packed `SizeHeader`, read by
 * {@see JXLSizeHeader}; {@see getCodestream()} assembles it from `jxlc`, or from the `jxlp`
 * parts in index order, or from the bare file itself.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @see https://www.iso.org/standard/85253.html ISO/IEC 18181-2 (JPEG XL file format)
 */
class JXLImage extends ImageFile
{
	/** The bare codestream signature. */
	public const CodestreamSignature = "\xFF\x0A";

	/** The type of the box a container file starts with. */
	public const SignatureBoxType = 'JXL ';

	/** The payload of the signature box. */
	public const SignatureBoxContent = "\x0D\x0A\x87\x0A";

	/** The whole 12-byte signature box a container file starts with. */
	public const Signature = "\x00\x00\x00\x0C" . self::SignatureBoxType . self::SignatureBoxContent;

	/** The file-type box. */
	public const FileTypeBox = 'ftyp';

	/** The JPEG XL brand, the major brand of a container file. */
	public const Brand = 'jxl ';

	/** The level box, declaring the codestream level. */
	public const LevelBox = 'jxll';

	/** The box holding the whole codestream. */
	public const CodestreamBox = 'jxlc';

	/** The box holding one part of a split codestream, behind a four-byte index. */
	public const PartialCodestreamBox = 'jxlp';

	/** The bytes of index a `jxlp` box carries before its share of the codestream. */
	public const PartialCodestreamIndexLength = 4;

	/** The bit a `jxlp` index sets to mark the last part of the codestream. */
	public const PartialCodestreamLastFlag = 0x80000000;

	/** The box indexing the keyframes of an animation. */
	public const FrameIndexBox = 'jxli';

	/** The box holding what is needed to reconstruct an original JPEG bit-for-bit. */
	public const JpegReconstructionBox = 'jbrd';

	/** The EXIF box: a four-byte offset to the TIFF header, then the TIFF block. */
	public const ExifBox = 'Exif';

	/** The XMP box. */
	public const XmlBox = 'xml ';

	/** The Brotli-compressed box, whose first four bytes name the type it stands for. */
	public const BrotliBox = 'brob';

	/** The JUMBF box. */
	public const JumbfBox = 'jumb';

	/** The boxes that carry the codestream; new metadata is placed before the first of them. */
	public const CodestreamBoxes = [self::CodestreamBox, self::PartialCodestreamBox];

	/** @var array<int, BMFFBox> The boxes of a container file, in file order. */
	private array $_boxes = [];

	/** @var string Trailing bytes that are not a whole box, kept for a faithful rewrite. */
	private string $_remainder = '';

	/** @var bool Whether the file is a bare codestream rather than a container. */
	private bool $_isBareCodestream = false;

	/** @var string The bare codestream bytes, while the file is one. */
	private string $_codestream = '';

	/**
	 * Returns the format name.
	 * @return string The format name.
	 */
	public function getFormat(): string
	{
		return 'JXL';
	}

	/**
	 * Indicates whether the bytes are a JPEG XL image, in either shape.
	 * @param string $data The candidate bytes.
	 * @return bool Whether the data is a JXL.
	 */
	public static function isJXL(string $data): bool
	{
		return str_starts_with($data, self::CodestreamSignature) || str_starts_with($data, self::Signature);
	}

	/**
	 * Indicates whether the file is a bare codestream, which carries no metadata until a
	 * setter promotes it to a container.
	 * @return bool Whether the file is a bare codestream.
	 */
	public function getIsBareCodestream(): bool
	{
		return $this->_isBareCodestream;
	}

	/**
	 * Returns the boxes of a container file in order, empty while it is a bare codestream.
	 * @return array<int, BMFFBox> The boxes.
	 */
	public function getBoxes(): array
	{
		return $this->_boxes;
	}

	/**
	 * Replaces the boxes wholesale, which also makes the file a container.
	 * @param array<int, BMFFBox> $value The boxes in file order.
	 */
	public function setBoxes(array $value): void
	{
		$this->_boxes = array_values($value);
		$this->_isBareCodestream = false;
	}

	/**
	 * Returns the first box of a type.
	 * @param string $type The four-character box type.
	 * @return ?BMFFBox The box, or null when absent.
	 */
	public function getBox(string $type): ?BMFFBox
	{
		foreach ($this->_boxes as $box) {
			if ($box->getType() === $type) {
				return $box;
			}
		}
		return null;
	}

	/**
	 * Returns the codestream: the whole file while it is bare, the `jxlc` box's payload, or
	 * the `jxlp` parts joined in index order with each part's four-byte index removed.
	 * @return ?string The codestream, or null when the file carries none.
	 */
	public function getCodestream(): ?string
	{
		if ($this->_isBareCodestream) {
			return $this->_codestream;
		}
		$whole = $this->getBox(self::CodestreamBox);
		if ($whole !== null) {
			return $whole->getPayload();
		}
		$parts = [];
		foreach ($this->_boxes as $box) {
			if ($box->getType() !== self::PartialCodestreamBox || strlen($box->getPayload()) < self::PartialCodestreamIndexLength) {
				continue;
			}
			$index = (int) unpack('N', substr($box->getPayload(), 0, self::PartialCodestreamIndexLength))[1];
			$parts[$index & ~self::PartialCodestreamLastFlag] = substr($box->getPayload(), self::PartialCodestreamIndexLength);
		}
		if ($parts === []) {
			return null;
		}
		ksort($parts);
		return implode('', $parts);
	}

	/**
	 * Returns the codestream's size header, which is where a JPEG XL states its dimensions.
	 * @return ?JXLSizeHeader The size header, or null when there is no readable codestream.
	 */
	public function getSizeHeader(): ?JXLSizeHeader
	{
		$codestream = $this->getCodestream();
		return $codestream === null ? null : JXLSizeHeader::fromCodestream($codestream);
	}

	/**
	 * Indicates whether a carrier is present only as a Brotli-compressed `brob` box, which
	 * this class does not decompress.  A caller that gets null from {@see getXMP()} or
	 * {@see getEXIF()} can tell "absent" from "present but compressed" with this.
	 * @param string $type The four-character type the `brob` stands for (e.g. {@see XmlBox}).
	 * @return bool Whether a `brob` box stands for that type.
	 */
	public function getHasBrotliCarrier(string $type): bool
	{
		foreach ($this->_boxes as $box) {
			if ($box->getType() === self::BrotliBox && substr($box->getPayload(), 0, 4) === $type) {
				return true;
			}
		}
		return false;
	}

	//
	// ─── The metadata carriers ───────────────────────────────────────────────
	//

	/**
	 * Returns the XMP packet text of the `xml ` box.
	 * @return ?string The packet text, or null when absent (or only Brotli-compressed).
	 */
	public function getXmpText(): ?string
	{
		return $this->getBox(self::XmlBox)?->getPayload();
	}

	/**
	 * Sets (or removes, when null) the XMP packet text of the `xml ` box.
	 * @param ?string $xmp The packet text, or null to drop the box.
	 */
	public function setXmpText(?string $xmp): void
	{
		$this->setMetaBox(self::XmlBox, $xmp);
	}

	/**
	 * Returns the parsed XMP packet.
	 * @return ?XMP The XMP, or null when absent or unparsable.
	 */
	public function getXMP(): ?XMP
	{
		$text = $this->getXmpText();
		if ($text === null) {
			return null;
		}
		$xmp = XMP::parse($text);
		return $xmp === false ? null : $xmp;
	}

	/**
	 * Sets (or removes, when null) the XMP packet.
	 * @param ?XMP $xmp The XMP, or null to drop the box.
	 */
	public function setXMP(?XMP $xmp): void
	{
		$this->setXmpText($xmp?->toPacketText());
	}

	/**
	 * Returns the EXIF metadata of the `Exif` box, stepping over the four-byte offset that
	 * precedes the TIFF header.
	 * @return ?EXIF The EXIF, or null when absent or unparsable.
	 */
	public function getEXIF(): ?EXIF
	{
		$payload = $this->getBox(self::ExifBox)?->getPayload();
		if ($payload === null || strlen($payload) < 4) {
			return null;
		}
		$tiff = substr($payload, 4 + (int) unpack('N', substr($payload, 0, 4))[1]);
		if ($tiff === '') {
			return null;
		}
		try {
			$exif = EXIF::fromTiffString($tiff);
		} catch (\RuntimeException $e) {
			return null;
		}
		$exif->setSignature('');
		return $exif;
	}

	/**
	 * Sets (or removes, when null) the EXIF metadata, writing the bare TIFF block behind a
	 * zero offset — the form every writer uses.
	 * @param ?EXIF $exif The EXIF, or null to drop the box.
	 */
	public function setEXIF(?EXIF $exif): void
	{
		if ($exif !== null) {
			$exif->setSignature('');   // the box holds no segment signature
		}
		$this->setMetaBox(self::ExifBox, $exif === null ? null : pack('N', 0) . $exif->toBinary());
	}

	/**
	 * Returns the JUMBF boxes the file carries, each parsed from a `jumb` box.
	 * @return array<int, JUMBFBox> The JUMBF boxes.
	 */
	public function getJumbfBoxes(): array
	{
		$boxes = [];
		foreach ($this->_boxes as $box) {
			if ($box->getType() !== self::JumbfBox) {
				continue;
			}
			// A `jumb` box always re-reads as one JUMBF superbox, so this adds exactly one.
			foreach (JUMBFBox::parseBoxes($box->toBinary()) as $jumbf) {
				$boxes[] = $jumbf;
			}
		}
		return $boxes;
	}

	/**
	 * Replaces the JUMBF boxes, writing one `jumb` box each.  An empty array drops them.
	 * @param array<int, JUMBFBox> $boxes The JUMBF boxes.
	 */
	public function setJumbfBoxes(array $boxes): void
	{
		$this->toContainer();
		$kept = array_values(array_filter($this->_boxes, fn ($b) => $b->getType() !== self::JumbfBox));
		$at = $this->metaInsertIndex($kept);
		$fresh = [];
		foreach ($boxes as $jumbf) {
			foreach (BMFFBox::parseBoxes($jumbf->toBinary()) as $parsed) {
				$fresh[] = $parsed;
			}
		}
		array_splice($kept, $at, 0, $fresh);
		$this->_boxes = $kept;
	}

	/**
	 * Returns no IPTC: JPEG XL has no carrier for IIM records.
	 * @return ?IPTC Always null.
	 */
	public function getIPTC(): ?IPTC
	{
		return null;
	}

	/**
	 * Refuses an IPTC record set: ISO/IEC 18181-2 defines the `Exif`, `xml `, and `jumb`
	 * boxes and no IIM one.  Rather than accept data it would drop on {@see save()}, this
	 * throws — put the equivalent properties in {@see setXMP() XMP}, which JXL does carry.
	 * @param ?IPTC $iptc The IPTC record set; only null is accepted.
	 * @throws \RuntimeException When an IPTC record set is given.
	 */
	public function setIPTC(?IPTC $iptc): void
	{
		if ($iptc !== null) {
			throw new \RuntimeException('A JPEG XL image has no IPTC carrier; put the equivalent properties in XMP.');
		}
	}

	/**
	 * Returns no ICC profile: a JXL profile is encoded in the codestream, which this class
	 * does not decode.
	 * @return ?string Always null.
	 */
	public function getICCProfile(): ?string
	{
		return null;
	}

	/**
	 * Refuses an ICC profile: JPEG XL carries the profile inside the codestream, so storing
	 * one would mean re-encoding the pixels.  Rather than accept a profile it would drop on
	 * {@see save()}, this throws.
	 * @param ?string $profile The profile bytes; only null is accepted.
	 * @throws \RuntimeException When a profile is given.
	 */
	public function setICCProfile(?string $profile): void
	{
		if ($profile !== null) {
			throw new \RuntimeException('A JPEG XL ICC profile is encoded in the codestream, which is not re-encoded.');
		}
	}

	//
	// ─── Reading and writing ─────────────────────────────────────────────────
	//

	/**
	 * Writes the JXL to a target.
	 * @param mixed $target A writable {@see \Psr\Http\Message\StreamInterface} or PHP stream resource.
	 * @return int The number of bytes written.
	 */
	public function streamTo(mixed $target): int
	{
		return $this->writeTo($target);
	}

	/**
	 * Rebuilds the file: the codestream itself while it is bare, otherwise every box in
	 * order and any trailing bytes that were not a whole box.
	 * @return string The composed JXL bytes.
	 */
	protected function compose(): string
	{
		if ($this->_isBareCodestream) {
			return $this->_codestream;
		}
		$bytes = '';
		foreach ($this->_boxes as $box) {
			$bytes .= $box->toBinary();
		}
		return $bytes . $this->_remainder;
	}

	/**
	 * Parses the file as a bare codestream or as a box container.
	 * @throws \UnexpectedValueException When the bytes are neither.
	 */
	protected function parse(): void
	{
		$bytes = $this->getBytesDirect();
		if (str_starts_with($bytes, self::CodestreamSignature)) {
			$this->_isBareCodestream = true;
			$this->_codestream = $bytes;
			$this->readDimensions();
			return;
		}
		if (!str_starts_with($bytes, self::Signature)) {
			throw new \UnexpectedValueException(sprintf('JPEG XL data is invalid: %s.', 'missing the codestream or container signature'));
		}
		[$this->_boxes, $this->_remainder] = BMFFBox::parseSequence($bytes);
		$this->readDimensions();
	}

	/**
	 * Promotes a bare codestream to a container, carrying the codestream verbatim into a
	 * `jxlc` box behind the signature and file-type boxes so nothing is lost.  A file that
	 * is already a container is left alone.
	 */
	protected function toContainer(): void
	{
		if (!$this->_isBareCodestream) {
			return;
		}
		$this->_boxes = [
			new BMFFBox(self::SignatureBoxType, self::SignatureBoxContent),
			new BMFFBox(self::FileTypeBox, self::Brand . pack('N', 0) . self::Brand),
			new BMFFBox(self::CodestreamBox, $this->_codestream),
		];
		$this->_isBareCodestream = false;
		$this->_codestream = '';
	}

	/**
	 * Returns the index a new metadata box belongs at: before the first codestream box, so
	 * a decoder meets the metadata first, or at the end when there is no codestream yet.
	 * @param array<int, BMFFBox> $boxes The boxes to place within.
	 * @return int The insertion index.
	 */
	private function metaInsertIndex(array $boxes): int
	{
		foreach ($boxes as $i => $box) {
			if (in_array($box->getType(), self::CodestreamBoxes, true)) {
				return $i;
			}
		}
		return count($boxes);
	}

	/**
	 * Stores (or drops, when null) a metadata box of a type: an existing box of that type is
	 * rewritten where it is, and a new one is placed before the codestream.  Any `brob` box
	 * standing for the same type is removed, so the file cannot hold a plain copy and a
	 * compressed copy that disagree.
	 * @param string $type The four-character box type.
	 * @param ?string $payload The payload, or null to drop the box.
	 */
	protected function setMetaBox(string $type, ?string $payload): void
	{
		$this->toContainer();
		$boxes = [];
		$replaced = false;
		foreach ($this->_boxes as $box) {
			if ($box->getType() === self::BrotliBox && substr($box->getPayload(), 0, 4) === $type) {
				continue;   // the compressed stand-in for what is being written
			}
			if ($box->getType() !== $type) {
				$boxes[] = $box;
				continue;
			}
			if ($payload !== null && !$replaced) {
				$boxes[] = new BMFFBox($type, $payload);
				$replaced = true;
			}
		}
		if ($payload !== null && !$replaced) {
			array_splice($boxes, $this->metaInsertIndex($boxes), 0, [new BMFFBox($type, $payload)]);
		}
		$this->_boxes = $boxes;
	}

	/**
	 * Reads the pixel dimensions from the codestream's size header, leaving them unknown when
	 * there is no codestream to read them from.
	 */
	protected function readDimensions(): void
	{
		$header = $this->getSizeHeader();
		if ($header !== null) {
			$this->setWidthDirect($header->getWidth());
			$this->setHeightDirect($header->getHeight());
		}
	}
}
