<?php

/**
 * EXIF class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\Meta;

use Belisoful\Image\PrivacyScrubbableInterface;
use Belisoful\Image\PhotoshopIRB;
use Belisoful\Image\PrivacyCategory;
use Belisoful\Image\TIFF\TIFFDataType;
use Belisoful\Image\TIFF\TIFFDocument;
use Belisoful\Image\TIFF\TIFFIfd;
use Belisoful\Image\TIFF\TIFFTag;
use Belisoful\Image\Stream\BinaryReader;
use Belisoful\Image\Stream\StreamIO;

/**
 * EXIF class.
 *
 * EXIF metadata: the {@see TIFFDocument} TIFF structure behind a JPEG APP1 `Exif`
 * segment, a TIFF file, or a Kodak APP3 `Meta` segment, with the EXIF vocabulary on
 * top.  The named IFD accessors reach the standard directories — {@see getIfd0()},
 * {@see getExifIfd()}, {@see getGpsIfd()}, {@see getInteropIfd()}, and the
 * {@see getThumbnailIfd() IFD1} — creating them (and their pointer tags) on demand for
 * the writable ones.
 *
 * Tags are reachable by name across all groups: {@see getValueByName()} answers the
 * raw value and {@see getTextByName()} the human-readable interpretation through
 * {@see EXIFTags}; {@see setValueByName()} writes a tag, inferring its group and
 * placing it in the right IFD.  The embedded metadata blocks are bridged to their own
 * classes: the {@see getIPTC() IPTC} record set (tag 33723), the raw
 * {@see getXmpText() XMP packet} (tag 700), the {@see getIrbData() Photoshop IRB}
 * (tag 34377), the {@see getPimData() PrintIM block} (tag 50341), and the
 * {@see getMakernoteData() makernote} (tag 37500), whose value area is pinned at its
 * original offset on rewrite so internal absolute pointers survive — the classic
 * makernote-corruption hazard of EXIF rewriters.
 *
 * The {@see getThumbnail() IFD1 JPEG thumbnail} is captured on parse and re-linked on
 * {@see toBinary()}, which recomposes the whole structure (signature included for the
 * segment forms).
 *
 * ```php
 * $exif = EXIF::fromSegment($app1Payload);
 * $exif->getValueByName('Model');                     // 'PowerShot S45'
 * $exif->getTextByName('ExposureTime');               // '1/125 sec'
 * $exif->setValueByName('Artist', 'A. Photographer');
 * $app1 = $exif->toBinary();                          // rewrite, makernote intact
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class EXIF implements PrivacyScrubbableInterface
{
	use \Belisoful\Image\StreamIOTrait;

	/** The JPEG APP1 EXIF signature. */
	public const ExifSignature = "Exif\x00\x00";

	/** The Kodak JPEG APP3 Meta signature. */
	public const MetaSignature = "Meta\x00\x00";

	/** The IFD0 IPTC-NAA tag. */
	public const IptcTag = 33723;

	/** The IFD0 XMP packet tag. */
	public const XmpTag = 700;

	/** The IFD0 Photoshop IRB tag. */
	public const IrbTag = 34377;

	/** The IFD0 PrintIM tag. */
	public const PimTag = 50341;

	/** The EXIF-IFD makernote tag. */
	public const MakerNoteTag = 37500;

	/** The IFD1 thumbnail offset (JPEGInterchangeFormat) tag. */
	public const ThumbnailOffsetTag = 513;

	/** The IFD1 thumbnail length (JPEGInterchangeFormatLength) tag. */
	public const ThumbnailLengthTag = 514;

	/** @var TIFFDocument The TIFF structure. */
	private TIFFDocument $_tiff;

	/** @var string The segment signature ({@see ExifSignature} or {@see MetaSignature}), or '' for a TIFF file. */
	private string $_signature = self::ExifSignature;

	/** @var ?string The IFD1 JPEG thumbnail bytes. */
	private ?string $_thumbnail = null;

	/** @var ?string The original TIFF bytes the EXIF was parsed from, for makernote decoding. */
	private ?string $_rawTiff = null;

	/**
	 * Constructs an EXIF over a TIFF structure (an empty one by default).
	 * @param ?TIFFDocument $tiff The TIFF structure, or null to start empty.
	 */
	final public function __construct(?TIFFDocument $tiff = null)
	{
		$this->_tiff = $tiff ?? new TIFFDocument();
	}

	/**
	 * Indicates whether a segment payload carries EXIF (or Kodak Meta) data.
	 * @param string $payload The APP1/APP3 payload.
	 * @return bool Whether the payload starts with an EXIF or Meta signature.
	 */
	public static function isExifSegment(string $payload): bool
	{
		return str_starts_with($payload, self::ExifSignature) || str_starts_with($payload, self::MetaSignature);
	}

	/**
	 * Parses a JPEG APP1 `Exif` (or APP3 `Meta`) segment payload.
	 * @param string $payload The segment payload, signature included.
	 * @return false|static The parsed EXIF, or false when the signature is absent.
	 */
	public static function fromSegment(string $payload): false|static
	{
		foreach ([self::ExifSignature, self::MetaSignature] as $signature) {
			if (str_starts_with($payload, $signature)) {
				$exif = static::fromTiffString(substr($payload, strlen($signature)));
				$exif->_signature = $signature;
				return $exif;
			}
		}
		return false;
	}

	/**
	 * Parses a TIFF structure (a TIFF file's bytes, or an EXIF block without signature).
	 * @param string $bytes The TIFF bytes.
	 * @return static The parsed EXIF.
	 */
	public static function fromTiffString(string $bytes): static
	{
		$exif = new static(TIFFDocument::fromString($bytes, static::subIfdTags()));
		$exif->_signature = '';
		$exif->_rawTiff = $bytes;
		$exif->captureThumbnail($bytes);
		$exif->pinMakernote();
		return $exif;
	}

	/**
	 * Parses EXIF from a TIFF file.
	 * @param string $path The TIFF file path.
	 * @throws \RuntimeException When the file cannot be read or is not a TIFF.
	 * @return static The parsed EXIF.
	 */
	public static function fromTiffFile(string $path): static
	{
		$bytes = @file_get_contents($path);
		if ($bytes === false) {
			throw new \RuntimeException(sprintf('Image file \'%s\' cannot be read.', $path));
		}
		return static::fromTiffString($bytes);
	}

	/**
	 * Parses EXIF from a PSR-7 stream or stream resource, reading from the current
	 * position to the end and auto-detecting the form: a segment payload when an
	 * `Exif`/`Meta` signature leads, a bare TIFF structure otherwise.
	 * @param mixed $stream The {@see \Psr\Http\Message\StreamInterface} or PHP stream resource.
	 * @return static The parsed EXIF.
	 */
	public static function fromStream(mixed $stream): static
	{
		$bytes = static::sourceBytes($stream);
		if (static::isExifSegment($bytes)) {
			$exif = static::fromSegment($bytes);
			if ($exif !== false) {
				return $exif;
			}
		}
		return static::fromTiffString($bytes);
	}

	/**
	 * Lazily scans the EXIF metadata of a seekable stream holding a TIFF file (taken
	 * to start at the stream's current position): every IFD, tag, and the IFD1
	 * thumbnail are read by seeking through a {@see \Belisoful\Image\Stream\BinaryReader},
	 * and the pixel strip/tile data is never touched — the metadata of an arbitrarily
	 * large file loads without materializing it.
	 *
	 * A scanned EXIF has no raw TIFF bytes, so a makernote whose format addresses the
	 * surrounding file absolutely (e.g. Canon) decodes only its inline values; the
	 * self-contained makernote forms decode fully.
	 * @param mixed $stream The seekable {@see \Psr\Http\Message\StreamInterface} or PHP stream resource.
	 * @param bool $deferStrips Whether to capture strip/tile data as deferred ranges (for {@see streamTo()}). Default false.
	 * @return static The scanned EXIF.
	 */
	public static function scanStream(mixed $stream, bool $deferStrips = false): static
	{
		$io = new BinaryReader($stream);
		$base = $io->tell();
		$exif = new static(TIFFDocument::scanStream($stream, static::subIfdTags(), deferStrips: $deferStrips));
		$exif->_signature = '';
		$exif->pinMakernote();

		// The IFD1 JPEG thumbnail is metadata-sized: pick it up with one more seek.
		$ifd1 = $exif->_tiff->getIfd(1);
		$offset = $ifd1?->getTagValue(self::ThumbnailOffsetTag);
		$length = $ifd1?->getTagValue(self::ThumbnailLengthTag);
		if (is_int($offset) && is_int($length) && $length > 0) {
			try {
				$io->seek($base + $offset);
				$exif->_thumbnail = $io->readBytes($length);
			} catch (\RuntimeException $e) {
				// A dangling thumbnail pointer is tolerated, like the in-memory parse.
			}
		}
		return $exif;
	}

	/**
	 * Lazily scans the EXIF metadata of a TIFF file (see {@see scanStream()}).
	 * @param string $path The TIFF file path.
	 * @return static The scanned EXIF.
	 */
	public static function scanFile(string $path): static
	{
		$handle = @fopen($path, 'rb');
		if ($handle === false) {
			throw new \RuntimeException(sprintf('Image file \'%s\' cannot be read.', $path));
		}
		try {
			return static::scanStream($handle);
		} finally {
			fclose($handle);
		}
	}

	/**
	 * Returns the tag ids parsed as sub-IFD pointers: every SubIFD-typed tag the
	 * {@see EXIFTags} knowledge base defines (EXIF, GPS, Interoperability, Kodak).
	 * @return int[] The sub-IFD pointer tag ids.
	 */
	protected static function subIfdTags(): array
	{
		$ids = [];
		foreach (EXIFTags::Definitions as $tags) {
			foreach ($tags as $id => $def) {
				if ($def['type'] === 'SubIFD') {
					$ids[$id] = $id;
				}
			}
		}
		return array_values($ids);
	}

	/**
	 * Captures the IFD1 JPEG thumbnail from the original bytes.
	 * @param string $bytes The TIFF bytes.
	 */
	protected function captureThumbnail(string $bytes): void
	{
		$ifd1 = $this->_tiff->getIfd(1);
		if ($ifd1 === null) {
			return;
		}
		$offset = $ifd1->getTagValue(self::ThumbnailOffsetTag);
		$length = $ifd1->getTagValue(self::ThumbnailLengthTag);
		if (is_int($offset) && is_int($length) && $length > 0 && $offset + $length <= strlen($bytes)) {
			$this->_thumbnail = substr($bytes, $offset, $length);
		}
	}

	/**
	 * Pins the makernote value area at its original offset for the rewrite safeguard.
	 */
	protected function pinMakernote(): void
	{
		$note = $this->getExifIfd()?->getTag(self::MakerNoteTag);
		if ($note !== null && $note->getOffset() !== null) {
			$note->setPreserveOffset(true);
		}
	}

	/**
	 * Returns the underlying TIFF structure.
	 * @return TIFFDocument The TIFF document.
	 */
	public function getTiff(): TIFFDocument
	{
		return $this->_tiff;
	}

	/**
	 * Indicates whether this is a Kodak APP3 `Meta` block rather than EXIF.
	 * @return bool Whether the Meta signature applies.
	 */
	public function getIsMeta(): bool
	{
		return $this->_signature === self::MetaSignature;
	}

	/**
	 * Sets whether the block composes as a Kodak APP3 `Meta` segment rather than EXIF.
	 * @param bool $value Whether to use the Meta signature.
	 */
	public function setIsMeta(bool $value): void
	{
		$this->_signature = $value ? self::MetaSignature : self::ExifSignature;
	}

	/**
	 * Returns the segment signature composed before the TIFF bytes.
	 * @return string {@see ExifSignature}, {@see MetaSignature}, or '' for a bare TIFF.
	 */
	public function getSignature(): string
	{
		return $this->_signature;
	}

	/**
	 * Sets the segment signature composed before the TIFF bytes.
	 * @param string $value {@see ExifSignature}, {@see MetaSignature}, or '' for a bare
	 *   TIFF (the TIFF-file form).
	 * @throws \UnexpectedValueException When the signature is none of those.
	 */
	public function setSignature(string $value): void
	{
		if ($value !== '' && $value !== self::ExifSignature && $value !== self::MetaSignature) {
			throw new \InvalidArgumentException(sprintf('\'%s\' is not an EXIF segment signature; use Exif, Meta, or an empty string.', bin2hex($value)));
		}
		$this->_signature = $value;
	}

	/**
	 * Returns the reserved private spaces within {@see toBinary()}: the maker note (tag
	 * 37500) and any other {@see TIFFTag::setPreserveOffset() pinned} value area, as
	 * `[offset, length]` pairs indexing the bytes {@see toBinary()} returns — the ranges
	 * are shifted past the segment signature so they line up with the composed output.
	 * These feed the framework's private-space stream decorators, letting a consumer edit
	 * an EXIF block while the maker notes are protected exactly as the writer protects them.
	 * @return array<int, array{0: int, 1: int}> The reserved [offset, length] pairs.
	 */
	public function getReservedSpaces(): array
	{
		$shift = strlen($this->_signature);
		return array_map(
			static fn (array $space): array => [$space[0] + $shift, $space[1]],
			$this->_tiff->getReservedSpaces(),
		);
	}

	/**
	 * Returns the free spaces of {@see toBinary()}: the ranges *outside* every reserved
	 * space, as `[offset, length]` pairs in offset order.  It is the complement of
	 * {@see getReservedSpaces()} over the composed length, so a caller can read or
	 * rewrite the editable regions while leaving the maker notes and private IFDs alone.
	 * @return array<int, array{0: int, 1: int}> The free `[offset, length]` ranges.
	 */
	public function getFreeSpaces(): array
	{
		return static::complementSpaces($this->getReservedSpaces(), strlen($this->toBinary()));
	}

	/**
	 * Returns the ranges of `[0, $length)` that the given spaces do not cover.
	 * @param array<int, array{0: int, 1: int}> $spaces The occupied `[offset, length]` pairs.
	 * @param int $length The total length the complement is taken over.
	 * @return array<int, array{0: int, 1: int}> The free `[offset, length]` ranges.
	 */
	public static function complementSpaces(array $spaces, int $length): array
	{
		usort($spaces, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
		$free = [];
		$cursor = 0;
		foreach ($spaces as [$offset, $size]) {
			if ($offset > $cursor) {
				$free[] = [$cursor, min($offset, $length) - $cursor];
			}
			$cursor = max($cursor, $offset + $size);
		}
		if ($cursor < $length) {
			$free[] = [$cursor, $length - $cursor];
		}
		return $free;
	}

	/**
	 * Returns IFD0, creating it when absent.
	 * @return TIFFIfd The first IFD.
	 */
	public function getIfd0(): TIFFIfd
	{
		if ($this->_tiff->getIfd(0) === null) {
			$this->_tiff->addIfd(new TIFFIfd());
		}
		return $this->_tiff->getIfd(0);
	}

	/**
	 * Returns a sub-IFD reached by a pointer tag of IFD0 (or another parent).
	 * @param TIFFIfd $parent The parent IFD.
	 * @param int $pointerTag The pointer tag id.
	 * @param bool $create Whether to create the sub-IFD (and pointer) when absent.
	 * @return ?TIFFIfd The sub-IFD, or null.
	 */
	protected function subIfd(TIFFIfd $parent, int $pointerTag, bool $create): ?TIFFIfd
	{
		$tag = $parent->getTag($pointerTag);
		if ($tag === null) {
			if (!$create) {
				return null;
			}
			$tag = $parent->setTagValues($pointerTag, TIFFDataType::ULong, [0]);
		}
		if ($tag->getSubIfd() === null) {
			if (!$create) {
				return null;
			}
			$tag->setSubIfd(new TIFFIfd());
		}
		return $tag->getSubIfd();
	}

	/**
	 * Returns the EXIF sub-IFD.
	 * @param bool $create Whether to create it when absent. Default false.
	 * @return ?TIFFIfd The EXIF IFD, or null.
	 */
	public function getExifIfd(bool $create = false): ?TIFFIfd
	{
		return $this->subIfd($this->getIfd0(), TIFFDocument::ExifIfdTag, $create);
	}

	/**
	 * Returns the GPS sub-IFD.
	 * @param bool $create Whether to create it when absent. Default false.
	 * @return ?TIFFIfd The GPS IFD, or null.
	 */
	public function getGpsIfd(bool $create = false): ?TIFFIfd
	{
		return $this->subIfd($this->getIfd0(), TIFFDocument::GpsIfdTag, $create);
	}

	/**
	 * Returns the Interoperability sub-IFD (inside the EXIF IFD).
	 * @param bool $create Whether to create it when absent. Default false.
	 * @return ?TIFFIfd The Interoperability IFD, or null.
	 */
	public function getInteropIfd(bool $create = false): ?TIFFIfd
	{
		$exif = $this->getExifIfd($create);
		return $exif === null ? null : $this->subIfd($exif, TIFFDocument::InteropIfdTag, $create);
	}

	/**
	 * Returns the thumbnail IFD (IFD1).
	 * @param bool $create Whether to create it when absent. Default false.
	 * @return ?TIFFIfd IFD1, or null.
	 */
	public function getThumbnailIfd(bool $create = false): ?TIFFIfd
	{
		if ($this->_tiff->getIfd(1) === null && $create) {
			$this->getIfd0();
			$this->_tiff->addIfd(new TIFFIfd());
		}
		return $this->_tiff->getIfd(1);
	}

	/**
	 * Resolves a tag name to its live tag, searching IFD0/EXIF/GPS/Interoperability.
	 * @param string $name The tag name (e.g. 'Model', 'FNumber', 'GPSLatitude').
	 * @return ?TIFFTag The tag, or null when absent or unknown.
	 */
	public function getTagByName(string $name): ?TIFFTag
	{
		$found = EXIFTags::findByName($name);
		if ($found === null) {
			return null;
		}
		[$group, $id] = $found;
		return $this->groupIfd($group)?->getTag($id);
	}

	/**
	 * Returns the raw value of a tag by name.
	 * @param string $name The tag name.
	 * @return mixed The value ({@see TIFFTag::getValue()}), or null when absent.
	 */
	public function getValueByName(string $name): mixed
	{
		return $this->getTagByName($name)?->getValue();
	}

	/**
	 * Returns the human-readable text of a tag by name.
	 * @param string $name The tag name.
	 * @return ?string The interpreted text, or null when absent.
	 */
	public function getTextByName(string $name): ?string
	{
		$found = EXIFTags::findByName($name);
		if ($found === null) {
			return null;
		}
		[$group, $id] = $found;
		$tag = $this->groupIfd($group)?->getTag($id);
		return $tag === null ? null : EXIFTags::textValue($tag, $group, $this->_tiff->getIsBigEndian());
	}

	/**
	 * Returns the copyright holder's machine-learning intentions (Exif 3.1 tag 37511):
	 * a map of {@see EXIFTags::LearningUsages usage} to
	 * {@see EXIFTags::LearningIntentions indication of intention}.
	 * @return array<int, int> The usage to intention map (empty when absent).
	 */
	public function getLearningIntentions(): array
	{
		$data = $this->getExifIfd()?->getTag(37511);
		if ($data === null) {
			return [];
		}
		return EXIFTags::decodeLearningOptOut($this->tagBytes($data), $this->_tiff->getIsBigEndian());
	}

	/**
	 * Sets (or removes, when empty) the machine-learning intentions.  The
	 * specification requires the `All / Individual usage is not specified` set (usage 0)
	 * to be present and listed first, and forbids repeating a usage; both are enforced
	 * here.
	 * @param array<int, int> $sets The usage to intention map.
	 * @throws \InvalidArgumentException When the usage 0 set is missing.
	 */
	public function setLearningIntentions(array $sets): void
	{
		if ($sets === []) {
			$this->getExifIfd()?->removeTag(37511);
			return;
		}
		if (!isset($sets[0])) {
			throw new \InvalidArgumentException('The LearningOptOutIn tag requires the usage 0 (All / Individual usage is not specified) set.');
		}
		$this->getExifIfd(true)->setTagValues(
			37511,
			TIFFDataType::Undefined,
			EXIFTags::encodeLearningOptOut($sets, $this->_tiff->getIsBigEndian()),
		);
	}

	/**
	 * Sets a tag by name, inferring its group, IFD, and data type.  A pure-ASCII string
	 * becomes an Ascii tag; a string with multibyte text becomes the EXIF 3.0 UTF-8
	 * type; both get a NUL terminator.  An integer becomes a Long; a float becomes a
	 * Rational.
	 * @param string $name The tag name.
	 * @param null|array|float|int|string $value The value; null removes the tag.
	 * @return bool Whether the name resolved and the tag was written or removed.
	 */
	public function setValueByName(string $name, null|int|float|string|array $value): bool
	{
		$found = EXIFTags::findByName($name);
		if ($found === null) {
			return false;
		}
		[$group, $id] = $found;
		$ifd = $this->groupIfd($group, true);
		if ($ifd === null) {
			return false;
		}
		if ($value === null) {
			$ifd->removeTag($id);
			return true;
		}
		if (is_string($value)) {
			$type = preg_match('/[\x80-\xFF]/', $value) ? TIFFDataType::Utf8 : TIFFDataType::Ascii;
			$ifd->setTagValues($id, $type, rtrim($value, "\0") . "\0");
		} elseif (is_int($value)) {
			$ifd->setTagValues($id, TIFFDataType::ULong, [$value]);
		} elseif (is_float($value)) {
			$den = 1000000;
			$ifd->setTagValues($id, TIFFDataType::URational, [[(int) round($value * $den), $den]]);
		} else {
			$type = is_array($value[0] ?? null) ? TIFFDataType::URational : TIFFDataType::ULong;
			$ifd->setTagValues($id, $type, $value);
		}
		return true;
	}

	/**
	 * Returns the live IFD a tag group lives in.
	 * @param string $group The {@see EXIFTags} group.
	 * @param bool $create Whether to create the IFD when absent.
	 * @return ?TIFFIfd The IFD, or null.
	 */
	protected function groupIfd(string $group, bool $create = false): ?TIFFIfd
	{
		return match ($group) {
			EXIFTags::TIFF, EXIFTags::Meta => $this->getIfd0(),
			EXIFTags::EXIF => $this->getExifIfd($create),
			EXIFTags::GPS => $this->getGpsIfd($create),
			EXIFTags::Interoperability => $this->getInteropIfd($create),
			default => null,
		};
	}

	/**
	 * Returns the raw bytes of a tag value, repacking numeric sets in document order.
	 * @param TIFFTag $tag The tag.
	 * @return string The value bytes.
	 */
	protected function tagBytes(TIFFTag $tag): string
	{
		return TIFFDataType::pack($tag->getType(), $tag->getValues(), $this->_tiff->getIsBigEndian());
	}

	/**
	 * Returns the embedded IPTC record set (IFD0 tag 33723).
	 * @return ?IPTC The IPTC, or null when absent or unparsable.
	 */
	public function getIPTC(): ?IPTC
	{
		$tag = $this->getIfd0()->getTag(self::IptcTag);
		if ($tag === null) {
			return null;
		}
		$bytes = $this->tagBytes($tag);
		$iptc = IPTC::parse($bytes);
		return $iptc === false ? null : $iptc;
	}

	/**
	 * Sets (or removes, when null) the embedded IPTC record set.
	 * @param ?IPTC $iptc The IPTC, or null to remove the tag.
	 */
	public function setIPTC(?IPTC $iptc): void
	{
		if ($iptc === null) {
			$this->getIfd0()->removeTag(self::IptcTag);
		} else {
			$this->getIfd0()->setTagValues(self::IptcTag, TIFFDataType::Undefined, $iptc->toBinary(null, false));
		}
	}

	/**
	 * Returns the embedded XMP packet text (IFD0 tag 700).
	 * @return ?string The XMP XML, or null when absent.
	 */
	public function getXmpText(): ?string
	{
		$tag = $this->getIfd0()->getTag(self::XmpTag);
		return $tag === null ? null : rtrim($this->tagBytes($tag), "\0");
	}

	/**
	 * Sets (or removes, when null) the embedded XMP packet text.
	 * @param ?string $xmp The XMP XML, or null to remove the tag.
	 */
	public function setXmpText(?string $xmp): void
	{
		if ($xmp === null) {
			$this->getIfd0()->removeTag(self::XmpTag);
		} else {
			$this->getIfd0()->setTagValues(self::XmpTag, TIFFDataType::UByte, array_map('ord', str_split($xmp)));
		}
	}

	/**
	 * Returns the embedded XMP packet parsed as a {@see XMP} DOM.
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
	 * Sets (or removes, when null) the embedded XMP packet.
	 * @param ?XMP $xmp The XMP, or null to remove the tag.
	 */
	public function setXMP(?XMP $xmp): void
	{
		$this->setXmpText($xmp?->toPacketText());
	}

	/**
	 * Returns the embedded Photoshop IRB bytes (IFD0 tag 34377).
	 * @return ?string The IRB bytes, or null when absent.
	 */
	public function getIrbData(): ?string
	{
		$tag = $this->getIfd0()->getTag(self::IrbTag);
		return $tag === null ? null : $this->tagBytes($tag);
	}

	/**
	 * Sets (or removes, when null) the embedded Photoshop IRB bytes.
	 * @param ?string $irb The IRB bytes, or null to remove the tag.
	 */
	public function setIrbData(?string $irb): void
	{
		if ($irb === null) {
			$this->getIfd0()->removeTag(self::IrbTag);
		} else {
			$this->getIfd0()->setTagValues(self::IrbTag, TIFFDataType::Undefined, $irb);
		}
	}

	/**
	 * Returns the embedded Photoshop image resources parsed as a {@see PhotoshopIRB}.
	 * @return ?PhotoshopIRB The IRB, or null when absent or unparsable.
	 */
	public function getIRB(): ?PhotoshopIRB
	{
		$data = $this->getIrbData();
		if ($data === null) {
			return null;
		}
		$irb = PhotoshopIRB::parse($data);
		return $irb === false ? null : $irb;
	}

	/**
	 * Sets (or removes, when null) the embedded Photoshop image resources.
	 * @param ?PhotoshopIRB $irb The IRB, or null to remove the tag.
	 */
	public function setIRB(?PhotoshopIRB $irb): void
	{
		$this->setIrbData($irb?->toBinary());
	}

	/**
	 * Returns the embedded PrintIM block bytes (IFD0 tag 50341).
	 * @return ?string The PIM bytes, or null when absent.
	 */
	public function getPimData(): ?string
	{
		$tag = $this->getIfd0()->getTag(self::PimTag);
		return $tag === null ? null : $this->tagBytes($tag);
	}

	/**
	 * Returns the embedded PrintIM block parsed as a {@see PrintIM}.
	 * @return ?PrintIM The PIM, or null when absent or unparsable.
	 */
	public function getPIM(): ?PrintIM
	{
		$data = $this->getPimData();
		if ($data === null) {
			return null;
		}
		$pim = PrintIM::parse($data, $this->_tiff->getIsBigEndian());
		return $pim === false ? null : $pim;
	}

	/**
	 * Sets (or removes, when null) the embedded PrintIM block.
	 * @param ?PrintIM $pim The PIM, or null to remove the tag.
	 */
	public function setPIM(?PrintIM $pim): void
	{
		if ($pim === null) {
			$this->getIfd0()->removeTag(self::PimTag);
		} else {
			$this->getIfd0()->setTagValues(self::PimTag, TIFFDataType::Undefined, $pim->toBinary());
		}
	}

	/**
	 * Returns the raw makernote bytes (EXIF IFD tag 37500).
	 * @return ?string The makernote bytes, or null when absent.
	 */
	public function getMakernoteData(): ?string
	{
		$tag = $this->getExifIfd()?->getTag(self::MakerNoteTag);
		return $tag === null ? null : $this->tagBytes($tag);
	}

	/**
	 * Returns the original TIFF bytes the EXIF was parsed from.
	 * @return ?string The parsed-from bytes, or null for a synthesized EXIF.
	 */
	public function getRawTiff(): ?string
	{
		return $this->_rawTiff;
	}

	/**
	 * Decodes the camera makernote through the maker registry.
	 * @return ?Makernote\Makernote The decoded makernote, or null when absent or unrecognized.
	 */
	public function getMakernote(): ?Makernote\Makernote
	{
		return Makernote\Makernote::fromExif($this);
	}

	/**
	 * Returns the camera make (IFD0 tag 271).
	 * @return ?string The make, or null when absent.
	 */
	public function getMake(): ?string
	{
		$value = $this->getIfd0()->getTagValue(271);
		return is_string($value) ? trim($value) : null;
	}

	/**
	 * Returns the camera model (IFD0 tag 272).
	 * @return ?string The model, or null when absent.
	 */
	public function getModel(): ?string
	{
		$value = $this->getIfd0()->getTagValue(272);
		return is_string($value) ? trim($value) : null;
	}

	/**
	 * Returns the GPS latitude as signed decimal degrees (southern latitudes negative).
	 * @return ?float The latitude, or null when absent.
	 */
	public function getLatitude(): ?float
	{
		return $this->gpsCoordinate(2, 1, 'S');
	}

	/**
	 * Sets (or removes, when null) the GPS latitude from signed decimal degrees.
	 * @param ?float $degrees The latitude (-90..90), or null to remove it.
	 */
	public function setLatitude(?float $degrees): void
	{
		$this->setGpsCoordinate(2, 1, $degrees, 'N', 'S');
	}

	/**
	 * Returns the GPS longitude as signed decimal degrees (western longitudes negative).
	 * @return ?float The longitude, or null when absent.
	 */
	public function getLongitude(): ?float
	{
		return $this->gpsCoordinate(4, 3, 'W');
	}

	/**
	 * Sets (or removes, when null) the GPS longitude from signed decimal degrees.
	 * @param ?float $degrees The longitude (-180..180), or null to remove it.
	 */
	public function setLongitude(?float $degrees): void
	{
		$this->setGpsCoordinate(4, 3, $degrees, 'E', 'W');
	}

	/**
	 * Returns the GPS altitude in metres (below sea level negative).
	 * @return ?float The altitude, or null when absent.
	 */
	public function getAltitude(): ?float
	{
		$gps = $this->getGpsIfd();
		$altitude = $gps?->getTag(6)?->getRational();
		if ($altitude === null) {
			return null;
		}
		$below = ($gps->getTagValue(5) ?? 0) === 1;
		return $below ? -$altitude : $altitude;
	}

	/**
	 * Sets (or removes, when null) the GPS altitude in metres.
	 * @param ?float $metres The altitude (negative for below sea level), or null to remove it.
	 */
	public function setAltitude(?float $metres): void
	{
		if ($metres === null) {
			$this->getGpsIfd()?->removeTag(5);
			$this->getGpsIfd()?->removeTag(6);
			return;
		}
		$gps = $this->gpsIfdForWrite();
		$gps->setTagValues(5, TIFFDataType::UByte, [$metres < 0 ? 1 : 0]);
		$gps->setTagValues(6, TIFFDataType::URational, [[(int) round(abs($metres) * 1000), 1000]]);
	}

	/**
	 * Returns the GPS date and time as a UTC instant (tags 29 GPSDateStamp and 7
	 * GPSTimeStamp together).
	 * @return ?\DateTimeImmutable The UTC timestamp, or null when either tag is absent.
	 */
	public function getGpsTimestamp(): ?\DateTimeImmutable
	{
		$gps = $this->getGpsIfd();
		$date = $gps?->getTagValue(29);
		$time = $gps?->getTag(7);
		if (!is_string($date) || $time === null || $time->getCount() < 3) {
			return null;
		}
		$parsed = \DateTimeImmutable::createFromFormat(
			'Y:m:d H:i:s',
			sprintf('%s %02d:%02d:%02d', trim($date), (int) $time->getRational(0), (int) $time->getRational(1), (int) $time->getRational(2)),
			new \DateTimeZone('UTC'),
		);
		return $parsed === false ? null : $parsed;
	}

	/**
	 * Sets (or removes, when null) the GPS date and time, converted to UTC.
	 * @param ?\DateTimeInterface $timestamp The instant, or null to remove both tags.
	 */
	public function setGpsTimestamp(?\DateTimeInterface $timestamp): void
	{
		if ($timestamp === null) {
			$this->getGpsIfd()?->removeTag(7);
			$this->getGpsIfd()?->removeTag(29);
			return;
		}
		$utc = \DateTimeImmutable::createFromInterface($timestamp)->setTimezone(new \DateTimeZone('UTC'));
		$gps = $this->gpsIfdForWrite();
		$gps->setTagValues(7, TIFFDataType::URational, [
			[(int) $utc->format('G'), 1],
			[(int) $utc->format('i'), 1],
			[(int) $utc->format('s'), 1],
		]);
		$gps->setTagValues(29, TIFFDataType::Ascii, $utc->format('Y:m:d') . "\0");
	}

	/**
	 * Reads a degrees/minutes/seconds GPS coordinate as signed decimal degrees.
	 * @param int $valueTag The coordinate tag (2 latitude, 4 longitude).
	 * @param int $refTag The reference tag (1 or 3).
	 * @param string $negativeRef The reference letter meaning negative ('S' or 'W').
	 * @return ?float The signed decimal degrees, or null when absent.
	 */
	protected function gpsCoordinate(int $valueTag, int $refTag, string $negativeRef): ?float
	{
		$gps = $this->getGpsIfd();
		$tag = $gps?->getTag($valueTag);
		if ($tag === null || $tag->getCount() < 1) {
			return null;
		}
		$degrees = ($tag->getRational(0) ?? 0.0)
			+ ($tag->getCount() > 1 ? ($tag->getRational(1) ?? 0.0) / 60 : 0)
			+ ($tag->getCount() > 2 ? ($tag->getRational(2) ?? 0.0) / 3600 : 0);
		$ref = $gps->getTagValue($refTag);
		return strcasecmp(trim((string) $ref), $negativeRef) === 0 ? -$degrees : $degrees;
	}

	/**
	 * Writes a signed decimal-degree coordinate as the reference letter and the
	 * degrees/minutes/seconds rationals (seconds kept to 1/10000).
	 * @param int $valueTag The coordinate tag (2 latitude, 4 longitude).
	 * @param int $refTag The reference tag (1 or 3).
	 * @param ?float $degrees The signed decimal degrees, or null to remove the pair.
	 * @param string $positiveRef The reference letter for positive values ('N' or 'E').
	 * @param string $negativeRef The reference letter for negative values ('S' or 'W').
	 */
	protected function setGpsCoordinate(int $valueTag, int $refTag, ?float $degrees, string $positiveRef, string $negativeRef): void
	{
		if ($degrees === null) {
			$this->getGpsIfd()?->removeTag($valueTag);
			$this->getGpsIfd()?->removeTag($refTag);
			return;
		}
		$gps = $this->gpsIfdForWrite();
		$absolute = abs($degrees);
		$wholeDegrees = (int) floor($absolute);
		$minutesFloat = ($absolute - $wholeDegrees) * 60;
		$wholeMinutes = (int) floor($minutesFloat);
		$seconds = ($minutesFloat - $wholeMinutes) * 60;
		$gps->setTagValues($refTag, TIFFDataType::Ascii, ($degrees < 0 ? $negativeRef : $positiveRef) . "\0");
		$gps->setTagValues($valueTag, TIFFDataType::URational, [
			[$wholeDegrees, 1],
			[$wholeMinutes, 1],
			[(int) round($seconds * 10000), 10000],
		]);
	}

	/**
	 * Returns the GPS IFD for writing, creating it and seeding the mandatory
	 * GPSVersionID (2.3.0.0) when absent.
	 * @return TIFFIfd The GPS IFD.
	 */
	protected function gpsIfdForWrite(): TIFFIfd
	{
		$gps = $this->getGpsIfd(true);
		if (!$gps->hasTag(0)) {
			$gps->setTagValues(0, TIFFDataType::UByte, [2, 3, 0, 0]);
		}
		return $gps;
	}

	/**
	 * Returns the IFD1 JPEG thumbnail bytes.
	 * @return ?string The thumbnail JPEG, or null when absent.
	 */
	public function getThumbnail(): ?string
	{
		return $this->_thumbnail;
	}

	/**
	 * Sets (or removes, when null) the IFD1 JPEG thumbnail.
	 * @param ?string $jpegBytes The thumbnail JPEG bytes, or null to remove it.
	 */
	public function setThumbnail(?string $jpegBytes): void
	{
		$this->_thumbnail = $jpegBytes;
		if ($jpegBytes === null && ($ifd1 = $this->_tiff->getIfd(1)) !== null) {
			$ifd1->removeTag(self::ThumbnailOffsetTag);
			$ifd1->removeTag(self::ThumbnailLengthTag);
		}
	}

	//
	// ─── Privacy ─────────────────────────────────────────────────────────────
	//

	/**
	 * @var array<int, array<int, array{0: string, 1: int}>> The tags each
	 *   {@see PrivacyCategory} flag removes, as [group, tag id] pairs.  The IFD-level
	 *   categories (Location, MakerNote, Thumbnail, Interoperability) are handled
	 *   structurally by {@see clearPrivateData()} rather than listed here.
	 */
	protected const PrivacyTags = [
		PrivacyCategory::Author => [
			[EXIFTags::TIFF, 315],    // Artist
			[EXIFTags::TIFF, 33432],  // Copyright
			[EXIFTags::TIFF, 40093],  // XPAuthor
			[EXIFTags::EXIF, 42032],  // CameraOwnerName
			[EXIFTags::EXIF, 42039],  // Photographer (Exif 3.0)
			[EXIFTags::EXIF, 42040],  // ImageEditor (Exif 3.0)
		],
		PrivacyCategory::Description => [
			[EXIFTags::TIFF, 269],    // DocumentName
			[EXIFTags::TIFF, 270],    // ImageDescription
			[EXIFTags::TIFF, 285],    // PageName
			[EXIFTags::TIFF, 40091],  // XPTitle
			[EXIFTags::TIFF, 40092],  // XPComment
			[EXIFTags::TIFF, 40094],  // XPKeywords
			[EXIFTags::TIFF, 40095],  // XPSubject
			[EXIFTags::EXIF, 37510],  // UserComment
			[EXIFTags::EXIF, 42038],  // Title (Exif 3.0)
		],
		PrivacyCategory::CameraModel => [
			[EXIFTags::TIFF, 271],    // Make
			[EXIFTags::TIFF, 272],    // Model
			[EXIFTags::EXIF, 42034],  // LensSpecification
			[EXIFTags::EXIF, 42035],  // LensMake
			[EXIFTags::EXIF, 42036],  // LensModel
		],
		PrivacyCategory::SerialNumber => [
			[EXIFTags::EXIF, 42016],  // ImageUniqueID
			[EXIFTags::EXIF, 42033],  // BodySerialNumber
			[EXIFTags::EXIF, 42037],  // LensSerialNumber
		],
		PrivacyCategory::Timestamp => [
			[EXIFTags::TIFF, 306],    // DateTime
			[EXIFTags::EXIF, 36867],  // DateTimeOriginal
			[EXIFTags::EXIF, 36868],  // DateTimeDigitized
			[EXIFTags::EXIF, 36880],  // OffsetTime
			[EXIFTags::EXIF, 36881],  // OffsetTimeOriginal
			[EXIFTags::EXIF, 36882],  // OffsetTimeDigitized
			[EXIFTags::EXIF, 37520],  // SubSecTime
			[EXIFTags::EXIF, 37521],  // SubSecTimeOriginal
			[EXIFTags::EXIF, 37522],  // SubSecTimeDigitized
		],
		PrivacyCategory::Software => [
			[EXIFTags::TIFF, 305],    // Software
			[EXIFTags::TIFF, 316],    // HostComputer
			[EXIFTags::EXIF, 42041],  // CameraFirmware
			[EXIFTags::EXIF, 42042],  // RAWDevelopingSoftware
			[EXIFTags::EXIF, 42043],  // ImageEditingSoftware
			[EXIFTags::EXIF, 42044],  // MetadataEditingSoftware
		],
	];

	/**
	 * Removes identifying information from the EXIF block, by category, so a photo can
	 * leave a user's control without disclosing where, when, by whom, or with what it was
	 * taken.  The default clears **everything**; pass a combination of
	 * {@see PrivacyCategory} flags to keep some categories.
	 *
	 * ```php
	 * $exif->clearPrivateData();                                       // the safe default
	 * $exif->clearPrivateData(PrivacyCategory::Location | PrivacyCategory::Identity);
	 * $exif->clearPrivateData(PrivacyCategory::All & ~PrivacyCategory::CameraModel);
	 * ```
	 *
	 * Removal is structural where a whole directory is identifying — the GPS IFD, the
	 * maker note, the IFD1 thumbnail, the Interoperability IFD — and tag-by-tag otherwise,
	 * so the block stays a well-formed EXIF and the fields that describe the picture
	 * rather than a person (exposure, colour, dimensions) are untouched.  A tag that is
	 * absent is simply skipped; the method never fails.
	 *
	 * This clears the EXIF block only.  A container's other carriers — XMP, IPTC, the
	 * Photoshop IRB, and a JPEG's comment — can hold the same facts and are the caller's
	 * to clear.
	 * @param int $types The {@see PrivacyCategory} flags to remove. Default {@see PrivacyCategory::All}.
	 * @return int The number of tags and directories removed.
	 */
	public function clearPrivateData(int $types = PrivacyCategory::All): int
	{
		$removed = 0;

		foreach (self::PrivacyTags as $flag => $tags) {
			if (($types & $flag) === 0) {
				continue;
			}
			foreach ($tags as [$group, $id]) {
				if ($this->groupIfd($group)?->removeTag($id) !== null) {
					$removed++;
				}
			}
		}

		if (($types & PrivacyCategory::Location) && $this->getIfd0()->removeTag(TIFFDocument::GpsIfdTag) !== null) {
			$removed++;   // the pointer tag takes the whole GPS directory with it
		}
		if (($types & PrivacyCategory::MakerNote) && $this->getExifIfd()?->removeTag(self::MakerNoteTag) !== null) {
			$removed++;
		}
		if (($types & PrivacyCategory::Interoperability) && $this->getExifIfd()?->removeTag(TIFFDocument::InteropIfdTag) !== null) {
			$removed++;
		}
		if (($types & PrivacyCategory::Thumbnail) && ($this->_thumbnail !== null || $this->_tiff->getIfd(1) !== null)) {
			$this->_thumbnail = null;
			if ($this->_tiff->getIfd(1) !== null) {
				$this->_tiff->removeIfd(1);   // the whole IFD1, not just the pointer pair
			}
			$removed++;
		}

		return $removed;
	}

	/**
	 * Composes the EXIF back to bytes: the signature (for a segment form), the TIFF
	 * structure with recomputed offsets, and the re-linked IFD1 thumbnail.
	 * @return string The EXIF bytes.
	 */
	public function toBinary(): string
	{
		if ($this->_thumbnail !== null) {
			$ifd1 = $this->getThumbnailIfd(true);
			$ifd1->setTagValues(self::ThumbnailLengthTag, TIFFDataType::ULong, [strlen($this->_thumbnail)]);
			$ifd1->setTagValues(self::ThumbnailOffsetTag, TIFFDataType::ULong, [0]);
			$length = strlen($this->_tiff->toBinary());
			$length += $length & 1;
			$ifd1->getTag(self::ThumbnailOffsetTag)->setValues([$length]);
			$bytes = str_pad($this->_tiff->toBinary(), $length, "\0") . $this->_thumbnail;
		} else {
			$bytes = $this->_tiff->toBinary();
		}
		return $this->_signature . $bytes;
	}

	/**
	 * Writes the EXIF to a target, streaming the TIFF structure (its deferred strip/tile
	 * data copied straight from the source) where it can — the bare-TIFF form of a scanned
	 * TIFF file.  The signature-prefixed segment form and the re-linked IFD1 thumbnail are
	 * metadata-sized, so those compose in full.
	 * @param mixed $target A writable {@see \Psr\Http\Message\StreamInterface} or PHP stream resource.
	 * @throws \InvalidArgumentException When the target is neither.
	 * @throws \RuntimeException When the target stops accepting bytes.
	 * @return int The number of bytes written.
	 */
	public function streamTo(mixed $target): int
	{
		if ($this->_signature !== '' || $this->_thumbnail !== null) {
			return StreamIO::writeAll($target, $this->toBinary());
		}
		return $this->_tiff->streamTo($target);
	}

	/**
	 * Composes the EXIF as a JPEG segment payload, EXIF signature included.
	 * @return string The APP1 (or APP3 Meta) payload.
	 */
	public function toSegment(): string
	{
		if ($this->_signature === '') {
			return self::ExifSignature . $this->toBinary();
		}
		return $this->toBinary();
	}
}
