<?php

/**
 * TIFFDocument class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\TIFF;

use Belisoful\Image\Stream\BinaryReader;
use Psr\Http\Message\StreamInterface;

/**
 * TIFFDocument class.
 *
 * A TIFF structure: the byte-order header, the main IFD chain (IFD0, IFD1, ...), and
 * every sub-IFD reached through the EXIF, GPS, and Interoperability pointer tags.  It
 * is the machinery beneath EXIF metadata — an EXIF APP1 payload is this structure after
 * the `Exif\x00\x00` signature, and a TIFF file is this structure with pixel data.
 *
 * {@see fromString()} parses either byte order tolerantly: a field with an out-of-range
 * offset or an unknown data type is skipped and noted in {@see getWarnings()} rather
 * than failing the parse.  {@see toBinary()} lays the structure back out with all
 * offsets recomputed — except fields flagged {@see TIFFTag::setPreserveOffset()},
 * whose value areas are pinned at their original positions so data with internal
 * absolute pointers (a makernote) survives the rewrite intact.
 *
 * ```php
 * $tiff = TIFFDocument::fromString($app1ExifPayload);
 * $make = $tiff->getIfd(0)?->getTagValue(271);            // 'Canon'
 * $exif = $tiff->getIfd(0)?->getTag(TIFFDocument::ExifIfdTag)?->getSubIfd();
 * $bytes = $tiff->toBinary();                             // recomposed TIFF
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TIFFDocument
{
	use \Belisoful\Image\StreamIOTrait;

	/** The TIFF magic number following the byte-order mark. */
	public const Magic = 42;

	/** The EXIF sub-IFD pointer tag (0x8769). */
	public const ExifIfdTag = 34665;

	/** The GPS sub-IFD pointer tag (0x8825). */
	public const GpsIfdTag = 34853;

	/** The Interoperability sub-IFD pointer tag (0xA005). */
	public const InteropIfdTag = 40965;

	/**
	 * @var array<int, int> The offsets tag => byte-counts tag pairs whose referenced
	 *   data (strips, tiles, free blocks) is captured on parse and relocated on compose.
	 */
	public const OffsetPairs = [
		273 => 279,   // StripOffsets => StripByteCounts
		288 => 289,   // FreeOffsets => FreeByteCounts
		324 => 325,   // TileOffsets => TileByteCounts
	];

	/** @var int The maximum IFD chain length / recursion depth honored on parse. */
	protected const MaxIfds = 64;

	/** @var bool Whether the document is big-endian (MM) rather than little-endian (II). */
	private bool $_bigEndian = true;

	/** @var TIFFIfd[] The main IFD chain (IFD0, IFD1, ...). */
	private array $_ifds = [];

	/** @var string[] The parse anomalies encountered. */
	private array $_warnings = [];

	/** @var int[] The tag ids parsed recursively as sub-IFD pointers. */
	private array $_subIfdTags = [self::ExifIfdTag, self::GpsIfdTag, self::InteropIfdTag];

	/** @var bool Whether the document came from a metadata-only {@see scanStream()}. */
	private bool $_scanned = false;

	/**
	 * Constructs an empty big-endian document.
	 */
	final public function __construct()
	{
	}

	/**
	 * Parses a TIFF structure from bytes.
	 * @param string $bytes The TIFF bytes (from the byte-order mark on).
	 * @param ?int[] $subIfdTags The tag ids to parse as sub-IFD pointers; null for the
	 *   EXIF/GPS/Interoperability defaults.
	 * @return static The parsed document.
	 */
	public static function fromString(string $bytes, ?array $subIfdTags = null): static
	{
		$tiff = new static();
		if ($subIfdTags !== null) {
			$tiff->_subIfdTags = $subIfdTags;
		}
		$tiff->parse($bytes);
		return $tiff;
	}

	/**
	 * Parses a TIFF structure from a PSR-7 stream or stream resource, reading from the
	 * current position to the end (so positioning the stream first scopes what is parsed).
	 * @param mixed $stream The {@see \Psr\Http\Message\StreamInterface} or PHP stream resource.
	 * @param ?int[] $subIfdTags The tag ids to parse as sub-IFD pointers; null for the defaults.
	 * @return static The parsed document.
	 */
	public static function fromStream(mixed $stream, ?array $subIfdTags = null): static
	{
		return static::fromString(static::sourceBytes($stream), $subIfdTags);
	}

	/**
	 * Lazily scans the metadata of a seekable stream through a {@see BinaryReader}:
	 * the header, every IFD table, tag values, and sub-IFDs are read by seeking, and
	 * the strip/tile pixel data is **never touched** — so the metadata of an
	 * arbitrarily large TIFF file loads without materializing the file.  The TIFF is
	 * taken to start at the stream's current position.
	 *
	 * A scanned document is for metadata reading: the strip/tile offset tags keep
	 * their values but carry no captured data ({@see getIsScanned()}), so composing
	 * one produces a metadata-only TIFF.
	 * @param mixed $stream The seekable {@see StreamInterface} or PHP stream resource.
	 * @param ?int[] $subIfdTags The tag ids to parse as sub-IFD pointers; null for the defaults.
	 * @param int $maxTagBytes The per-tag value-size cap; a larger value area is
	 *   skipped with a warning. Default 16 MiB.
	 * @throws \InvalidArgumentException When the source is not a stream.
	 * @throws \RuntimeException When the source is not a stream.
	 * @throws \InvalidArgumentException When the stream is not seekable or not a TIFF structure.
	 * @throws \RuntimeException When the stream is not seekable or not a TIFF structure.
	 * @return static The scanned document.
	 */
	public static function scanStream(mixed $stream, ?array $subIfdTags = null, int $maxTagBytes = 16777216): static
	{
		$io = new BinaryReader($stream);
		if (!$io->isSeekable()) {
			throw new \RuntimeException('Lazily scanning TIFF metadata requires a seekable stream.');
		}
		$tiff = new static();
		if ($subIfdTags !== null) {
			$tiff->_subIfdTags = $subIfdTags;
		}
		$tiff->_scanned = true;
		$tiff->scan($io, $maxTagBytes);
		return $tiff;
	}

	/**
	 * Indicates whether the document came from a metadata-only {@see scanStream()}
	 * (no strip/tile data captured).
	 * @return bool Whether the document was lazily scanned.
	 */
	public function getIsScanned(): bool
	{
		return $this->_scanned;
	}

	/**
	 * Scans the header and IFD chain by seeking.
	 * @param BinaryReader $binary The seekable reader, positioned at the TIFF start.
	 * @param int $maxTagBytes The per-tag value-size cap.
	 * @throws \UnexpectedValueException When the stream is not a TIFF structure.
	 */
	protected function scan(BinaryReader $binary, int $maxTagBytes): void
	{
		$base = $binary->tell();
		try {
			$order = $binary->readBytes(2);
		} catch (\RuntimeException $e) {
			throw new \UnexpectedValueException(sprintf('TIFF data is invalid: %s.', 'shorter than the 8-byte header'));
		}
		if ($order === 'MM') {
			$this->setIsBigEndian(true);
		} elseif ($order === 'II') {
			$this->setIsBigEndian(false);
		} else {
			throw new \UnexpectedValueException(sprintf('TIFF data is invalid: %s.', 'missing MM/II byte-order mark'));
		}
		$binary->setBigEndian($this->getIsBigEndian());
		try {
			if ($binary->readUInt16() !== self::Magic) {
				throw new \UnexpectedValueException(sprintf('TIFF data is invalid: %s.', 'missing magic number 42'));
			}
			$offset = $binary->readUInt32();
		} catch (\RuntimeException $e) {
			throw new \UnexpectedValueException(sprintf('TIFF data is invalid: %s.', 'shorter than the 8-byte header'));
		}
		$seen = [];
		while ($offset > 0 && count($this->_ifds) < static::MaxIfds) {
			if (isset($seen[$offset])) {
				$this->addWarning("IFD chain loops back to offset $offset");
				break;
			}
			$seen[$offset] = true;
			[$ifd, $offset] = $this->scanIfd($binary, $base, $offset, 0, $maxTagBytes);
			if ($ifd === null) {
				break;
			}
			$this->_ifds[] = $ifd;
		}
	}

	/**
	 * Scans one IFD (and its sub-IFDs) by seeking: the entry table is pulled in one
	 * read, and each out-of-line value area in one seek-and-read.
	 * @param BinaryReader $binary The typed reader over the seekable stream.
	 * @param int $base The stream position of the TIFF start.
	 * @param int $offset The IFD table offset within the TIFF.
	 * @param int $depth The sub-IFD recursion depth.
	 * @param int $maxTagBytes The per-tag value-size cap.
	 * @return array The [?TIFFIfd, int nextOffset] pair.
	 */
	protected function scanIfd(BinaryReader $binary, int $base, int $offset, int $depth, int $maxTagBytes): array
	{
		try {
			$binary->seek($base + $offset);
			$count = $binary->readUInt16();
			$table = $binary->readBytes($count * 12 + 4);
		} catch (\RuntimeException $e) {
			$this->addWarning("IFD offset $offset is outside the data");
			return [null, 0];
		}
		$next = $this->readULong($table, $count * 12);
		$ifd = new TIFFIfd();
		for ($i = 0; $i < $count; $i++) {
			$entry = $i * 12;
			$tagId = $this->readUShort($table, $entry);
			$type = $this->readUShort($table, $entry + 2);
			$valueCount = $this->readULong($table, $entry + 4);
			if (!TIFFDataType::isValid($type)) {
				$this->addWarning("tag $tagId has unknown data type $type");
				continue;
			}
			$byteSize = TIFFDataType::getSize($type) * $valueCount;
			if ($byteSize <= 4) {
				$data = substr($table, $entry + 8, max(0, $byteSize));
				$valueOffset = null;
			} else {
				$valueOffset = $this->readULong($table, $entry + 8);
				if ($byteSize > $maxTagBytes) {
					$this->addWarning("tag $tagId value of $byteSize bytes exceeds the $maxTagBytes-byte scan cap");
					continue;
				}
				try {
					$binary->seek($base + $valueOffset);
					$data = $binary->readBytes($byteSize);
				} catch (\RuntimeException $e) {
					$this->addWarning("tag $tagId value at $valueOffset runs past the data");
					continue;
				}
			}
			$tag = new TIFFTag($tagId, $type, TIFFDataType::unpack($type, $data, $this->getIsBigEndian()));
			$tag->setOffset($valueOffset);
			if (in_array($tagId, $this->_subIfdTags, true) && $depth < static::MaxIfds) {
				$subOffset = (int) ($tag->getValues()[0] ?? 0);
				[$subIfd] = $this->scanIfd($binary, $base, $subOffset, $depth + 1, $maxTagBytes);
				if ($subIfd !== null) {
					$tag->setSubIfd($subIfd);
				}
			}
			$ifd->setTag($tag);
		}
		return [$ifd, $next];
	}

	/**
	 * Indicates whether the document is big-endian.
	 * @return bool Whether the byte order is MM (big-endian).
	 */
	public function getIsBigEndian(): bool
	{
		return $this->_bigEndian;
	}

	/**
	 * Sets the byte order the document composes in.
	 * @param bool $value Whether to compose MM (big-endian) rather than II.
	 */
	public function setIsBigEndian(bool $value): void
	{
		$this->_bigEndian = $value;
	}

	/**
	 * Returns the byte-order mark.
	 * @return string 'MM' or 'II'.
	 */
	public function getByteOrder(): string
	{
		return $this->_bigEndian ? 'MM' : 'II';
	}

	/**
	 * Returns an IFD of the main chain.
	 * @param int $index The chain index (0 = IFD0). Default 0.
	 * @return ?TIFFIfd The IFD, or null when absent.
	 */
	public function getIfd(int $index = 0): ?TIFFIfd
	{
		return $this->_ifds[$index] ?? null;
	}

	/**
	 * Returns the main IFD chain.
	 * @return TIFFIfd[] The IFDs in chain order.
	 */
	public function getIfds(): array
	{
		return $this->_ifds;
	}

	/**
	 * Appends an IFD to the main chain.
	 * @param TIFFIfd $ifd The IFD.
	 */
	public function addIfd(TIFFIfd $ifd): void
	{
		$this->_ifds[] = $ifd;
	}

	/**
	 * Removes an IFD from the main chain.
	 * @param int $index The chain index.
	 * @return ?TIFFIfd The removed IFD, or null when absent.
	 */
	public function removeIfd(int $index): ?TIFFIfd
	{
		$ifd = $this->_ifds[$index] ?? null;
		array_splice($this->_ifds, $index, 1);
		return $ifd;
	}

	/**
	 * Returns the anomalies noted while parsing.
	 * @return string[] The warnings.
	 */
	public function getWarnings(): array
	{
		return $this->_warnings;
	}

	/**
	 * Notes a parse anomaly.
	 * @param string $message The warning.
	 */
	protected function addWarning(string $message): void
	{
		$this->_warnings[] = $message;
	}

	/**
	 * Parses the TIFF header and IFD chain.
	 * @param string $bytes The TIFF bytes.
	 * @throws \UnexpectedValueException When the bytes are not a TIFF structure.
	 */
	protected function parse(string $bytes): void
	{
		if (strlen($bytes) < 8) {
			throw new \UnexpectedValueException(sprintf('TIFF data is invalid: %s.', 'shorter than the 8-byte header'));
		}
		$order = substr($bytes, 0, 2);
		if ($order === 'MM') {
			$this->_bigEndian = true;
		} elseif ($order === 'II') {
			$this->_bigEndian = false;
		} else {
			throw new \UnexpectedValueException(sprintf('TIFF data is invalid: %s.', 'missing MM/II byte-order mark'));
		}
		if ($this->readUShort($bytes, 2) !== self::Magic) {
			throw new \UnexpectedValueException(sprintf('TIFF data is invalid: %s.', 'missing magic number 42'));
		}
		$offset = $this->readULong($bytes, 4);
		$seen = [];
		while ($offset > 0 && count($this->_ifds) < static::MaxIfds) {
			if (isset($seen[$offset])) {
				$this->addWarning("IFD chain loops back to offset $offset");
				break;
			}
			$seen[$offset] = true;
			[$ifd, $offset] = $this->readIfd($bytes, $offset, 0);
			if ($ifd === null) {
				break;
			}
			$this->captureExternalData($ifd, $bytes);
			$this->_ifds[] = $ifd;
		}
	}

	/**
	 * Captures the strip/tile/free data an IFD's offset-pair tags reference, so the
	 * blocks travel with the structure and are re-emitted (offsets rewritten) on compose.
	 * @param TIFFIfd $ifd The chain IFD.
	 * @param string $bytes The TIFF bytes.
	 */
	protected function captureExternalData(TIFFIfd $ifd, string $bytes): void
	{
		$len = strlen($bytes);
		foreach (self::OffsetPairs as $offsetsId => $countsId) {
			$offsetsTag = $ifd->getTag($offsetsId);
			$countsTag = $ifd->getTag($countsId);
			if ($offsetsTag === null || $countsTag === null) {
				continue;
			}
			$offsets = (array) $offsetsTag->getValues();
			$counts = (array) $countsTag->getValues();
			if (count($offsets) !== count($counts)) {
				$this->addWarning("tag $offsetsId has " . count($offsets) . ' offsets but ' . count($counts) . ' byte counts');
				continue;
			}
			$blocks = [];
			foreach ($offsets as $i => $blockOffset) {
				$size = (int) $counts[$i];
				if (!is_int($blockOffset) || $blockOffset < 0 || $blockOffset + $size > $len) {
					$this->addWarning("tag $offsetsId block $i at $blockOffset runs past the data");
					continue 2;
				}
				$blocks[] = substr($bytes, $blockOffset, $size);
			}
			$offsetsTag->setExternalData($blocks);
		}
	}

	/**
	 * Reads one IFD (and, on the main chain, its sub-IFDs).
	 * @param string $bytes The TIFF bytes.
	 * @param int $offset The IFD table offset.
	 * @param int $depth The sub-IFD recursion depth.
	 * @param int $valueBase The base added to out-of-line value offsets (makernotes
	 *   with makernote-relative addressing pass the note's offset). Default 0.
	 * @param bool $readNext Whether a next-IFD pointer follows the entries. Default true.
	 * @return array The [?TIFFIfd, int nextOffset] pair.
	 */
	protected function readIfd(string $bytes, int $offset, int $depth, int $valueBase = 0, bool $readNext = true): array
	{
		$len = strlen($bytes);
		if ($offset < 0 || $offset + 2 > $len) {
			$this->addWarning("IFD offset $offset is outside the data");
			return [null, 0];
		}
		$count = $this->readUShort($bytes, $offset);
		if ($offset + 2 + $count * 12 + ($readNext ? 4 : 0) > $len) {
			$this->addWarning("IFD at $offset declares $count entries beyond the data");
			$count = max(0, intdiv($len - $offset - 2, 12));
		}
		$ifd = new TIFFIfd();
		for ($i = 0; $i < $count; $i++) {
			$entry = $offset + 2 + $i * 12;
			$tagId = $this->readUShort($bytes, $entry);
			$type = $this->readUShort($bytes, $entry + 2);
			$valueCount = $this->readULong($bytes, $entry + 4);
			if (!TIFFDataType::isValid($type)) {
				$this->addWarning("tag $tagId has unknown data type $type");
				continue;
			}
			$byteSize = TIFFDataType::getSize($type) * $valueCount;
			if ($byteSize <= 4) {
				$data = substr($bytes, $entry + 8, max(0, $byteSize));
				$valueOffset = null;
			} else {
				$valueOffset = $valueBase + $this->readULong($bytes, $entry + 8);
				if ($valueOffset + $byteSize > $len) {
					$this->addWarning("tag $tagId value at $valueOffset runs past the data");
					continue;
				}
				$data = substr($bytes, $valueOffset, $byteSize);
			}
			$tag = new TIFFTag($tagId, $type, TIFFDataType::unpack($type, $data, $this->_bigEndian));
			$tag->setOffset($valueOffset);
			if (in_array($tagId, $this->_subIfdTags, true) && $depth < static::MaxIfds) {
				$subOffset = (int) ($tag->getValues()[0] ?? 0);
				[$subIfd] = $this->readIfd($bytes, $subOffset, $depth + 1);
				if ($subIfd !== null) {
					$tag->setSubIfd($subIfd);
				}
			}
			$ifd->setTag($tag);
		}
		$next = $readNext && $offset + 2 + $count * 12 + 4 <= $len ? $this->readULong($bytes, $offset + 2 + $count * 12) : 0;
		return [$ifd, $next];
	}

	/**
	 * Reads one IFD table anywhere in a byte block, without following sub-IFD pointers —
	 * the building block for the non-standard IFDs makernotes carry.
	 * @param string $bytes The bytes containing the IFD.
	 * @param int $offset The IFD table offset.
	 * @param int $valueBase The base added to out-of-line value offsets. Default 0.
	 * @param bool $readNext Whether a next-IFD pointer follows the entries. Default true.
	 * @return array The [?TIFFIfd, int nextOffset] pair.
	 */
	public function readIfdAt(string $bytes, int $offset, int $valueBase = 0, bool $readNext = true): array
	{
		return $this->readIfd($bytes, $offset, static::MaxIfds, $valueBase, $readNext);
	}

	/**
	 * Composes the document back to TIFF bytes, recomputing every offset while pinning
	 * fields flagged {@see TIFFTag::getPreserveOffset()} at their original positions.
	 * @return string The TIFF bytes.
	 */
	public function toBinary(): string
	{
		// Normalize the offset-pair tags: byte counts from the captured blocks, and
		// ULong placeholder offsets (patched after allocation).
		foreach ($this->_ifds as $ifd) {
			$this->normalizeExternalData($ifd);
		}

		// Pin pass: reserve the value areas that must stay at their original offsets.
		$pins = [];
		foreach ($this->_ifds as $ifd) {
			$this->collectPins($ifd, $pins);
		}
		usort($pins, fn ($a, $b) => $a[0] <=> $b[0]);

		// Layout pass: place IFD tables and out-of-line values around the pins.
		$cursor = 8;
		$plan = [];
		foreach ($this->_ifds as $index => $ifd) {
			$plan[$index] = $this->layoutIfd($ifd, $cursor, $pins);
		}

		// Block pass: place the external data blocks and patch the offsets tags.
		$blockWrites = [];
		foreach ($this->_ifds as $ifd) {
			foreach (array_keys(self::OffsetPairs) as $offsetsId) {
				$tag = $ifd->getTag($offsetsId);
				$blocks = $tag?->getExternalData();
				if ($blocks === null) {
					continue;
				}
				$offsets = [];
				foreach ($blocks as $block) {
					$blockOffset = $this->allocate($cursor, strlen($block), $pins);
					$offsets[] = $blockOffset;
					$blockWrites[] = [$blockOffset, $block];
				}
				$tag->setValues($offsets);
			}
		}

		$total = $cursor;
		foreach ($pins as [$start, $size]) {
			$total = max($total, $start + $size);
		}

		// Render pass.
		$out = str_repeat("\0", $total);
		$this->writeBytes($out, 0, $this->getByteOrder() . $this->packUShort(self::Magic) . $this->packULong($this->_ifds !== [] ? $plan[0]['table'] : 0));
		foreach ($plan as $index => $layout) {
			$next = $plan[$index + 1]['table'] ?? 0;
			$this->renderIfd($out, $layout, $next);
		}
		foreach ($blockWrites as [$blockOffset, $block]) {
			$this->writeBytes($out, $blockOffset, $block);
		}
		return $out;
	}

	/**
	 * Rewrites an IFD's offset-pair tags from their captured blocks: the byte-counts
	 * tag gets the block lengths (widened to ULong when a length outgrows UShort) and
	 * the offsets tag becomes ULong placeholders sized to the block count.
	 * @param TIFFIfd $ifd The chain IFD.
	 */
	protected function normalizeExternalData(TIFFIfd $ifd): void
	{
		foreach (self::OffsetPairs as $offsetsId => $countsId) {
			$tag = $ifd->getTag($offsetsId);
			$blocks = $tag?->getExternalData();
			if ($blocks === null) {
				continue;
			}
			$lengths = array_map('strlen', $blocks);
			$countsTag = $ifd->getTag($countsId) ?? $ifd->setTagValues($countsId, TIFFDataType::ULong, []);
			if ($countsTag->getType() !== TIFFDataType::UShort && $countsTag->getType() !== TIFFDataType::ULong) {
				$countsTag->setType(TIFFDataType::ULong);
			} elseif ($countsTag->getType() === TIFFDataType::UShort && $lengths !== [] && max($lengths) > 0xFFFF) {
				$countsTag->setType(TIFFDataType::ULong);
			}
			$countsTag->setValues($lengths);
			$tag->setType(TIFFDataType::ULong);
			$tag->setValues(array_fill(0, count($blocks), 0));
		}
	}

	/**
	 * Returns the reserved private spaces of the composed TIFF: the value areas that
	 * {@see TIFFTag::setPreserveOffset()} pins at their original offsets — a camera's
	 * maker notes and any private IFD — as sorted, non-overlapping `[offset, length]`
	 * pairs.  These are exactly the ranges the layout pass places every other field
	 * around, so a caller that leaves them untouched preserves exactly the bytes the
	 * writer pins.
	 * @return array<int, array{0: int, 1: int}> The reserved [offset, length] pairs.
	 */
	public function getReservedSpaces(): array
	{
		$pins = [];
		foreach ($this->_ifds as $ifd) {
			$this->collectPins($ifd, $pins);
		}
		$spaces = array_map(static fn (array $pin): array => [$pin[0], $pin[1]], $pins);
		usort($spaces, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
		return $spaces;
	}

	/**
	 * Collects the pinned value areas of an IFD tree.
	 * @param TIFFIfd $ifd The IFD.
	 * @param array &$pins The [offset, size, TIFFTag] pin list being built.
	 */
	protected function collectPins(TIFFIfd $ifd, array &$pins): void
	{
		foreach ($ifd->getTags() as $tag) {
			if ($this->isPinned($tag)) {
				$pins[] = [$tag->getOffset(), TIFFDataType::getSize($tag->getType()) * $tag->getCount(), $tag];
			}
			if ($tag->getSubIfd() !== null) {
				$this->collectPins($tag->getSubIfd(), $pins);
			}
		}
	}

	/**
	 * Indicates whether a tag's value area is pinned: it must stay at its original offset
	 * on rewrite because internal absolute pointers reference it (a maker note or private
	 * IFD).  Only an out-of-line value — larger than the four-byte inline slot — parsed
	 * with a real offset qualifies; a built value has no offset to preserve.
	 *
	 * This is the single predicate shared by {@see collectPins()} (which reserves the
	 * ranges), {@see layoutIfd()} (which places every other field around them), and so
	 * {@see getReservedSpaces()}, so the reserved-space list can never drift from what the
	 * writer actually pins.
	 * @param TIFFTag $tag The tag.
	 * @return bool Whether the value area is pinned at its original offset.
	 */
	protected function isPinned(TIFFTag $tag): bool
	{
		return $tag->getPreserveOffset()
			&& $tag->getOffset() !== null
			&& $tag->getOffset() >= 8
			&& TIFFDataType::getSize($tag->getType()) * $tag->getCount() > 4;
	}

	/**
	 * Allocates space at the cursor, skipping pinned ranges and keeping word alignment.
	 * @param int &$cursor The allocation cursor.
	 * @param int $size The bytes needed.
	 * @param array<array{int, int}> $pins The pinned [offset, length] ranges.
	 * @return int The allocated offset.
	 */
	protected function allocate(int &$cursor, int $size, array $pins): int
	{
		$cursor += $cursor & 1;
		$moved = true;
		while ($moved) {
			$moved = false;
			foreach ($pins as [$start, $pinSize]) {
				if ($cursor < $start + $pinSize && $start < $cursor + $size) {
					$cursor = $start + $pinSize + (($start + $pinSize) & 1);
					$moved = true;
				}
			}
		}
		$offset = $cursor;
		$cursor += $size;
		return $offset;
	}

	/**
	 * Assigns offsets to an IFD's table, out-of-line values, and sub-IFDs.
	 * @param TIFFIfd $ifd The IFD.
	 * @param int &$cursor The allocation cursor.
	 * @param array $pins The pinned ranges.
	 * @return array The layout: table offset, per-tag value offsets, sub-IFD layouts.
	 */
	protected function layoutIfd(TIFFIfd $ifd, int &$cursor, array $pins): array
	{
		$layout = ['ifd' => $ifd, 'table' => 0, 'values' => [], 'subs' => []];
		$layout['table'] = $this->allocate($cursor, 2 + count($ifd->getTags()) * 12 + 4, $pins);
		foreach ($ifd->getTags() as $id => $tag) {
			$size = TIFFDataType::getSize($tag->getType()) * $tag->getCount();
			if ($tag->getSubIfd() !== null || $size <= 4) {
				continue;
			}
			if ($this->isPinned($tag)) {
				$layout['values'][$id] = $tag->getOffset();
			} else {
				$layout['values'][$id] = $this->allocate($cursor, $size, $pins);
			}
		}
		foreach ($ifd->getTags() as $id => $tag) {
			if ($tag->getSubIfd() !== null) {
				$layout['subs'][$id] = $this->layoutIfd($tag->getSubIfd(), $cursor, $pins);
			}
		}
		return $layout;
	}

	/**
	 * Renders an IFD and its values into the output buffer.
	 * @param string &$out The output buffer.
	 * @param array $layout The {@see layoutIfd()} plan.
	 * @param int $nextOffset The next-IFD pointer value.
	 */
	protected function renderIfd(string &$out, array $layout, int $nextOffset): void
	{
		$ifd = $layout['ifd'];
		$table = $this->packUShort(count($ifd->getTags()));
		foreach ($ifd->getTags() as $id => $tag) {
			if (isset($layout['subs'][$id])) {
				$type = TIFFDataType::ULong;
				$data = $this->packULong($layout['subs'][$id]['table']);
				$count = 1;
			} else {
				$type = $tag->getType();
				$data = TIFFDataType::pack($type, $tag->getValues(), $this->_bigEndian);
				$count = $tag->getCount();
			}
			$table .= $this->packUShort($id) . $this->packUShort($type) . $this->packULong($count);
			if (isset($layout['values'][$id])) {
				$table .= $this->packULong($layout['values'][$id]);
				$this->writeBytes($out, $layout['values'][$id], $data);
			} else {
				$table .= str_pad(substr($data, 0, 4), 4, "\0");
			}
		}
		$table .= $this->packULong($nextOffset);
		$this->writeBytes($out, $layout['table'], $table);
		foreach ($layout['subs'] as $sub) {
			$this->renderIfd($out, $sub, 0);
		}
	}

	/**
	 * Overwrites bytes in the output buffer.
	 * @param string &$out The output buffer.
	 * @param int $offset The write position.
	 * @param string $bytes The bytes to write.
	 */
	protected function writeBytes(string &$out, int $offset, string $bytes): void
	{
		$out = substr_replace($out, $bytes, $offset, strlen($bytes));
	}

	/**
	 * Reads an unsigned 16-bit integer in the document byte order.
	 * @param string $bytes The data.
	 * @param int $offset The read position.
	 * @return int The value.
	 */
	public function readUShort(string $bytes, int $offset): int
	{
		return unpack($this->_bigEndian ? 'n' : 'v', substr($bytes, $offset, 2))[1];
	}

	/**
	 * Reads an unsigned 32-bit integer in the document byte order.
	 * @param string $bytes The data.
	 * @param int $offset The read position.
	 * @return int The value.
	 */
	public function readULong(string $bytes, int $offset): int
	{
		return unpack($this->_bigEndian ? 'N' : 'V', substr($bytes, $offset, 4))[1];
	}

	/**
	 * Packs an unsigned 16-bit integer in the document byte order.
	 * @param int $value The value.
	 * @return string The two bytes.
	 */
	public function packUShort(int $value): string
	{
		return pack($this->_bigEndian ? 'n' : 'v', $value);
	}

	/**
	 * Packs an unsigned 32-bit integer in the document byte order.
	 * @param int $value The value.
	 * @return string The four bytes.
	 */
	public function packULong(int $value): string
	{
		return pack($this->_bigEndian ? 'N' : 'V', $value);
	}
}
