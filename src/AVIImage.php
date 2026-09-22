<?php

/**
 * AVIImage class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image;

use Belisoful\Image\Meta\IPTC;
use Belisoful\Image\Meta\XMP;
use Belisoful\Image\Stream\StreamIO;

/**
 * AVIImage class.
 *
 * Reads and writes the metadata of an Audio Video Interleave file: a RIFF container of the
 * `AVI ` form type, whose structure is a `LIST hdrl` of headers, a `LIST movi` of the
 * interleaved media, and an optional `idx1` index.  The media itself is never decoded — an
 * AVI is opened here to read or edit what it says about itself, not to understand video.
 *
 * The carriers are the ones the format actually defines:
 * - **`LIST INFO`** — the RIFF INFO tags ({@see getInfo()}, {@see setInfoValue()}), which
 *   is where an AVI's title, artist, copyright, and creation date live.
 * - **`_PMX`** — the XMP packet ({@see getXMP()}), the chunk id Adobe's XMP specification
 *   gives RIFF files.
 * - **`IDIT`** — the digitization timestamp, scrubbed with the other timestamps.
 *
 * There is no TIFF/Exif carrier and no ICC or IPTC carrier in AVI, so {@see setEXIF()},
 * {@see setICCProfile()}, and {@see setIPTC()} throw rather than accept data they would
 * drop.  (A `LIST exif` attribute list — `ecor`, `emdl`, `etim` — is a different structure
 * from a TIFF block and is not modelled here.)
 *
 * **The `movi` list never moves.**  An AVI's `idx1` entries and any OpenDML `indx` tables
 * address the media, so shifting it would invalidate them.  Every metadata write therefore
 * goes through the same placement rule: a chunk that sits after `movi` is rewritten where it
 * is, a chunk before `movi` is rewritten **in its own slot whenever it fits** — the same
 * length, or shorter with the remainder filled by a `JUNK` chunk — and only a chunk that does
 * not fit has its slot overwritten by `JUNK` and the new one appended at the end.  Nothing
 * before or inside `movi` ever moves, so no index needs fixing up and no file is silently
 * corrupted.
 *
 * "Fits" has a floor.  What is left over has to hold a padding chunk's own eight-byte header,
 * so a replacement that frees fewer than eight bytes cannot reuse its slot even though it is
 * smaller; RIFF pads payloads to an even length, so that means a shortfall of two, four or
 * six bytes falls back to moving.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @see https://learn.microsoft.com/en-us/windows/win32/directshow/avi-riff-file-reference
 */
class AVIImage extends ImageFile
{
	/** The RIFF form type of an AVI file. */
	public const FormType = 'AVI ';

	/**
	 * The smallest chunk that can exist: a four-character id and a size, with no payload.
	 * A slot that frees fewer bytes than this cannot hold the padding that would keep the
	 * media still, so such a write has to move instead.
	 */
	public const MinimumChunk = 8;

	/** The bytes of one `idx1` entry: a chunk id, flags, an offset and a length. */
	public const IndexEntryLength = 16;

	/** The bytes of a `MainAVIHeader`, whose last two fields are the frame dimensions. */
	public const MainHeaderLength = 40;

	// The RIFF INFO tag ids an AVI commonly carries.  The vocabulary is open: any four
	// character id in the list is read and written, these are the ones named here.
	public const InfoArtist = 'IART';
	public const InfoComment = 'ICMT';
	public const InfoCopyright = 'ICOP';
	public const InfoCreationDate = 'ICRD';
	public const InfoEngineer = 'IENG';
	public const InfoGenre = 'IGNR';
	public const InfoKeywords = 'IKEY';
	public const InfoName = 'INAM';
	public const InfoProduct = 'IPRD';
	public const InfoSoftware = 'ISFT';
	public const InfoSource = 'ISRC';
	public const InfoSubject = 'ISBJ';
	public const InfoTechnician = 'ITCH';

	/**
	 * The INFO tags each {@see PrivacyCategory} removes.  Only identifying fields are
	 * listed: what the file says about the picture (dimensions, rates, stream formats)
	 * is never touched, so a scrubbed AVI is still a playable video.
	 */
	protected const PrivacyInfoTags = [
		PrivacyCategory::Author => [
			self::InfoArtist, self::InfoCopyright, self::InfoEngineer,
			self::InfoTechnician, self::InfoSource, self::InfoProduct,
		],
		PrivacyCategory::Description => [
			self::InfoName, self::InfoComment, self::InfoKeywords,
			self::InfoSubject, self::InfoGenre,
		],
		PrivacyCategory::Timestamp => [self::InfoCreationDate],
		PrivacyCategory::Software => [self::InfoSoftware],
	];

	/** @var ?RIFFContainer The underlying RIFF container, or null before a parse. */
	private ?RIFFContainer $_riff = null;

	/** @var ?int The total frame count from the main header. */
	private ?int $_totalFrames = null;

	/** @var ?int The frame period in microseconds, from the main header. */
	private ?int $_microSecPerFrame = null;

	/**
	 * Returns the format name.
	 * @return string The format name.
	 */
	public function getFormat(): string
	{
		return 'AVI';
	}

	/**
	 * Indicates whether the bytes are a RIFF container of the `AVI ` form type.
	 * @param string $data The candidate bytes.
	 * @return bool Whether the data is an AVI.
	 */
	public static function isAVI(string $data): bool
	{
		return strlen($data) >= 12 && strncmp($data, RIFFChunkType::Riff, 4) === 0
			&& strncmp(substr($data, 8, 4), self::FormType, 4) === 0;
	}

	/**
	 * Returns the RIFF container backing the AVI, starting an empty `AVI ` form when the
	 * container has not been parsed from bytes.
	 * @return RIFFContainer The RIFF container.
	 */
	public function getRIFF(): RIFFContainer
	{
		if ($this->_riff === null) {
			$this->_riff = new RIFFContainer();
			$this->_riff->setFormType(self::FormType);
		}
		return $this->_riff;
	}

	/**
	 * Returns the total frame count declared by the main header.
	 * @return ?int The frame count, or null when the header is absent.
	 */
	public function getTotalFrames(): ?int
	{
		return $this->_totalFrames;
	}

	/**
	 * Returns the frame period in microseconds declared by the main header.
	 * @return ?int The frame period, or null when the header is absent.
	 */
	public function getMicroSecPerFrame(): ?int
	{
		return $this->_microSecPerFrame;
	}

	//
	// ─── The INFO list ───────────────────────────────────────────────────────
	//

	/**
	 * Returns the `LIST INFO` tags in file order, each id mapped to its text with the
	 * trailing NUL of the stored string removed.
	 * @return array<string, string> The INFO tags, empty when the list is absent.
	 */
	public function getInfo(): array
	{
		$list = $this->getRIFF()->getList(RIFFChunkType::InfoList);
		if ($list === null) {
			return [];
		}
		$info = [];
		foreach ($list->getChunks() as $chunk) {
			$info[$chunk->getType()] = rtrim($chunk->getData(), "\0");
		}
		return $info;
	}

	/**
	 * Replaces the `LIST INFO` tags wholesale, writing each value as the NUL-terminated
	 * string the format stores.  An empty array drops the list.
	 * @param array<string, string> $info The INFO tags, each keyed by its four-character id.
	 */
	public function setInfo(array $info): void
	{
		$existing = $this->findChunkIndex(RIFFChunkType::RiffList, RIFFChunkType::InfoList);
		if ($info === []) {
			$this->placeChunk($existing, null);
			return;
		}
		$payload = RIFFChunkType::InfoList;
		foreach ($info as $id => $value) {
			$text = rtrim($value, "\0") . "\0";
			$payload .= substr(str_pad((string) $id, 4), 0, 4) . pack('V', strlen($text)) . $text;
			if (strlen($text) & 1) {
				$payload .= "\0"; // pad to an even length
			}
		}
		$this->placeChunk($existing, new RIFFList(RIFFChunkType::RiffList, strlen($payload), 0, $payload));
	}

	/**
	 * Returns one `LIST INFO` tag.
	 * @param string $id The four-character INFO tag id (e.g. {@see InfoArtist}).
	 * @return ?string The text, or null when the tag is absent.
	 */
	public function getInfoValue(string $id): ?string
	{
		return $this->getInfo()[$id] ?? null;
	}

	/**
	 * Sets (or removes, when null) one `LIST INFO` tag, keeping the others in their order.
	 * @param string $id The four-character INFO tag id (e.g. {@see InfoArtist}).
	 * @param ?string $value The text, or null to remove the tag.
	 */
	public function setInfoValue(string $id, ?string $value): void
	{
		$info = $this->getInfo();
		if ($value === null) {
			unset($info[$id]);
		} else {
			$info[$id] = $value;
		}
		$this->setInfo($info);
	}

	//
	// ─── The metadata carriers ───────────────────────────────────────────────
	//

	/**
	 * Returns the XMP packet text of the `_PMX` chunk.
	 * @return ?string The packet text, or null when absent.
	 */
	public function getXmpText(): ?string
	{
		return $this->getRIFF()->getChunk(RIFFChunkType::XmpRiff)?->getData();
	}

	/**
	 * Sets (or removes, when null) the XMP packet text of the `_PMX` chunk.
	 * @param ?string $xmp The packet text, or null to drop the chunk.
	 */
	public function setXmpText(?string $xmp): void
	{
		$this->setMetaChunk(RIFFChunkType::XmpRiff, $xmp);
	}

	/**
	 * Returns the parsed XMP packet of the `_PMX` chunk.
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
	 * @param ?XMP $xmp The XMP, or null to drop the chunk.
	 */
	public function setXMP(?XMP $xmp): void
	{
		$this->setXmpText($xmp?->toPacketText());
	}

	/**
	 * Returns the digitization timestamp of the `IDIT` chunk.
	 * @return ?string The timestamp text, or null when absent.
	 */
	public function getDigitizationTime(): ?string
	{
		$data = $this->getRIFF()->getChunk(RIFFChunkType::DigitizationTime)?->getData();
		return $data === null ? null : rtrim($data, "\0");
	}

	/**
	 * Sets (or removes, when null) the digitization timestamp of the `IDIT` chunk.
	 * @param ?string $value The timestamp text, or null to drop the chunk.
	 */
	public function setDigitizationTime(?string $value): void
	{
		$this->setMetaChunk(RIFFChunkType::DigitizationTime, $value === null ? null : rtrim($value, "\0") . "\0");
	}

	/**
	 * Returns no IPTC: an AVI has no carrier for IIM records.
	 * @return ?IPTC Always null.
	 */
	public function getIPTC(): ?IPTC
	{
		return null;
	}

	/**
	 * Refuses an IPTC record set: the AVI file reference defines the INFO tags, and there
	 * is no established RIFF chunk for IIM records.  Rather than accept data it would drop
	 * on {@see save()}, this throws — put the equivalent properties in {@see setXMP() XMP},
	 * which AVI does carry in `_PMX`.
	 * @param ?IPTC $iptc The IPTC record set; only null is accepted.
	 * @throws \RuntimeException When an IPTC record set is given.
	 */
	public function setIPTC(?IPTC $iptc): void
	{
		if ($iptc !== null) {
			throw new \RuntimeException('An AVI has no IPTC carrier; put the equivalent properties in XMP.');
		}
	}

	/**
	 * Returns no ICC profile: an AVI has no carrier for one (`ICCP` is WebP's chunk).
	 * @return ?string Always null.
	 */
	public function getICCProfile(): ?string
	{
		return null;
	}

	/**
	 * Refuses an ICC profile: the `ICCP` chunk belongs to the WebP form, and AVI defines
	 * no equivalent.  Rather than accept a profile it would drop on {@see save()}, this
	 * throws.
	 * @param ?string $profile The profile bytes; only null is accepted.
	 * @throws \RuntimeException When a profile is given.
	 */
	public function setICCProfile(?string $profile): void
	{
		if ($profile !== null) {
			throw new \RuntimeException('An AVI has no ICC profile carrier.');
		}
	}

	//
	// ─── Reading and writing ─────────────────────────────────────────────────
	//

	/**
	 * Lazily reads an AVI from a seekable stream: the headers and metadata chunks are read,
	 * but the `LIST movi` media is kept as a deferred range into the still-open source, so
	 * an AVI far larger than memory opens for a metadata edit.  Pair it with
	 * {@see streamTo()}; the source must stay open and seekable until then.
	 * @param mixed $stream The seekable {@see \Psr\Http\Message\StreamInterface} or PHP stream resource.
	 * @throws \RuntimeException When the stream is not seekable.
	 * @throws \UnexpectedValueException When the form type is not `AVI `.
	 * @return static The lazily parsed AVI.
	 */
	public static function fromStreamLazy(mixed $stream): static
	{
		StreamIO::rewind($stream);
		$riff = RIFFContainer::fromStreamLazy($stream, [RIFFChunkType::RiffList . ':' . RIFFChunkType::MovieList]);
		if ($riff->getFormType() !== self::FormType) {
			throw new \UnexpectedValueException(sprintf('AVI data is invalid: %s.', 'RIFF form type is not AVI'));
		}
		$avi = new static();
		$avi->_riff = $riff;
		$avi->readMainHeader();
		return $avi;
	}

	/**
	 * Writes the AVI to a target, copying the deferred `LIST movi` straight from the source
	 * in bounded memory, so an AVI opened with {@see fromStreamLazy()} is rewritten around
	 * a metadata edit without holding its media.
	 * @param mixed $target A writable {@see \Psr\Http\Message\StreamInterface} or PHP stream resource.
	 * @throws \InvalidArgumentException When the target is neither.
	 * @throws \RuntimeException When the target stops accepting bytes.
	 * @return int The number of bytes written.
	 */
	public function streamTo(mixed $target): int
	{
		return $this->getRIFF()->streamTo($target);
	}

	/**
	 * Rebuilds the AVI from its RIFF container.
	 * @return string The composed AVI bytes.
	 */
	protected function compose(): string
	{
		return $this->getRIFF()->toBinary();
	}

	/**
	 * Parses the RIFF/`AVI ` container and reads the main header.
	 * @throws \UnexpectedValueException When the bytes are not a RIFF container of the `AVI ` form.
	 */
	protected function parse(): void
	{
		$riff = RIFFContainer::fromString($this->getBytesDirect());
		if ($riff->getFormType() !== self::FormType) {
			throw new \UnexpectedValueException(sprintf('AVI data is invalid: %s.', 'RIFF form type is not AVI'));
		}
		$this->_riff = $riff;
		$this->readMainHeader();
	}

	/**
	 * Reads the frame dimensions, frame count, and frame period from the `avih` main header
	 * inside `LIST hdrl`.  A file without one keeps them unknown rather than guessing.
	 */
	private function readMainHeader(): void
	{
		$avih = $this->getRIFF()->getList(RIFFChunkType::HeaderList)?->getChunk(RIFFChunkType::AviHeader)?->getData();
		if ($avih === null || strlen($avih) < self::MainHeaderLength) {
			return;
		}
		$fields = unpack('VmicroSecPerFrame/VmaxBytesPerSec/VpaddingGranularity/Vflags/VtotalFrames/VinitialFrames/Vstreams/VsuggestedBufferSize/Vwidth/Vheight', $avih);
		$this->_microSecPerFrame = $fields['microSecPerFrame'];
		$this->_totalFrames = $fields['totalFrames'];
		$this->setWidthDirect($fields['width']);
		$this->setHeightDirect($fields['height']);
	}

	//
	// ─── Placement: the media list never moves ───────────────────────────────
	//

	/**
	 * Indicates whether the file's chunks may simply be rearranged, which is the exception to
	 * the never-move rule: it holds when **every structure that addresses the media can be
	 * shown to survive the move**, and for an AVI that comes down to two questions.
	 *
	 * - Is there an OpenDML index?  `indx` super-indexes and the `ix##` chunks they point at
	 *   hold **file-absolute** positions, so a move invalidates them.  A file carrying one is
	 *   refused.  Only `indx` is looked for: `ix##` chunks exist only in files that also carry
	 *   an `indx`, and finding them would mean reading the media this class never reads.
	 * - Are the `idx1` offsets relative to `movi`?  Both conventions are in the wild.  A
	 *   relative table stays correct however far the media moves, because every entry is
	 *   measured from the list's own start; an absolute one does not, so it is refused.
	 *
	 * The test compares the table's first entry against where the media list's first chunk
	 * actually sits, which is a fact the parse already knows.  A **deferred** media list has
	 * not been read, so nothing can be proven about it and the answer is no.
	 * @return bool Whether chunks may be moved rather than fitted into the file's padding.
	 */
	public function getCanRearrange(): bool
	{
		if ($this->getRIFF()->getList(RIFFChunkType::HeaderList)?->getChunk(RIFFChunkType::OpenDmlIndex) !== null) {
			return false;
		}
		foreach ($this->getRIFF()->getList(RIFFChunkType::HeaderList)?->getChunks() ?? [] as $child) {
			if ($child instanceof RIFFList && $child->getChunk(RIFFChunkType::OpenDmlIndex) !== null) {
				return false;   // an OpenDML super-index inside a stream list
			}
		}
		$index = $this->getRIFF()->getChunk(RIFFChunkType::Index);
		if ($index === null) {
			return true;   // nothing addresses the media at all
		}
		$movi = $this->findMoviIndex();
		$list = $movi === null ? null : $this->getRIFF()->getChunks()[$movi];
		if (!$list instanceof RIFFList || strlen($index->getData()) < self::IndexEntryLength) {
			return false;   // a deferred or absent media list proves nothing
		}
		$first = $list->getChunks()[0] ?? null;
		if ($first === null) {
			return false;
		}
		$stored = (int) unpack('V', substr($index->getData(), 8, 4))[1];
		return $stored === $first->getOffset() - self::MinimumChunk - $list->getOffset();
	}

	/**
	 * Returns the index of the top-level chunk of an id, optionally narrowed to a `LIST` of
	 * a list type.
	 * @param string $id The four-character chunk id.
	 * @param ?string $listType The list type to match, or null for any chunk of the id.
	 * @return ?int The index within the container's chunks, or null when absent.
	 */
	private function findChunkIndex(string $id, ?string $listType = null): ?int
	{
		foreach ($this->getRIFF()->getChunks() as $i => $chunk) {
			if ($chunk->getType() !== $id) {
				continue;
			}
			if ($listType === null || ($chunk instanceof RIFFList && $chunk->getListType() === $listType)) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * Returns the index of the `LIST movi` media list, which the writer holds in place.  A
	 * deferred `LIST` is it by construction: {@see fromStreamLazy()} defers that list alone.
	 * @return ?int The index within the container's chunks, or null when there is no media.
	 */
	private function findMoviIndex(): ?int
	{
		foreach ($this->getRIFF()->getChunks() as $i => $chunk) {
			if ($chunk->getType() !== RIFFChunkType::RiffList) {
				continue;
			}
			if ($chunk->getDeferredRange() !== null || ($chunk instanceof RIFFList && $chunk->getListType() === RIFFChunkType::MovieList)) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * Stores (or drops, when null) a top-level metadata chunk of an id under the placement
	 * rule of {@see placeChunk()}.
	 * @param string $id The four-character chunk id.
	 * @param ?string $data The payload, or null to drop the chunk.
	 */
	protected function setMetaChunk(string $id, ?string $data): void
	{
		$existing = $this->findChunkIndex($id);
		$this->placeChunk($existing, $data === null ? null : new ImageChunk($id, strlen($data), 0, $data));
	}

	/**
	 * Puts a metadata chunk into the container without moving the `LIST movi` media, which
	 * `idx1` and any OpenDML index address:
	 *
	 * - a chunk that is not there yet is appended at the end, past everything;
	 * - a chunk that sits after the media is rewritten (or removed) where it is, since
	 *   nothing addresses what follows the media;
	 * - a chunk before the media is rewritten **in its own slot whenever it fits**: exactly,
	 *   or shorter with a `JUNK` chunk filling what is left over, which holds every later byte
	 *   still while keeping the metadata where a reader expects it;
	 * - a chunk that does not fit vacates its slot to `JUNK` and then looks for room in the
	 *   file's **other** padding, which is space already set aside and so costs nothing to
	 *   fill; adjacent padding is merged first, so two small runs can serve one larger write;
	 * - only when no padding can take it is the chunk appended at the end.
	 *
	 * @param ?int $index The index of the chunk being replaced, or null when there is none.
	 * @param ?ImageChunk $chunk The chunk to store, or null to drop the existing one.
	 */
	private function placeChunk(?int $index, ?ImageChunk $chunk): void
	{
		$riff = $this->getRIFF();
		if ($this->getCanRearrange()) {
			$this->rearrangeChunk($index, $chunk);
			return;
		}
		if ($index === null) {
			if ($chunk !== null && !$this->placeInPadding($chunk)) {
				$riff->addChunk($chunk);   // nothing moves: the end is past every offset
			}
			return;
		}
		$chunks = $riff->getChunks();
		$movi = $this->findMoviIndex();
		$existing = $chunks[$index];
		if ($movi === null || $index > $movi) {
			// Past the media, so the bytes are free to change length.
			if ($chunk === null) {
				unset($chunks[$index]);
			} else {
				$chunks[$index] = $chunk;
			}
			$riff->setChunks($chunks);
			return;
		}
		$gap = $chunk === null ? null : self::slotGap($this->wholeSize($existing->getSize()), $this->wholeSize(strlen($chunk->getData())));
		if ($gap !== null) {
			// It fits where it is, so the media stays put; anything left over becomes padding.
			$chunks[$index] = $chunk;
			if ($gap > 0) {
				array_splice($chunks, $index + 1, 0, [$this->junk($gap - self::MinimumChunk)]);
			}
			$riff->setChunks($chunks);
			return;
		}
		// It outgrew its own slot, so the slot becomes padding and the chunk looks for room
		// among the file's other padding before resorting to the end.
		$chunks[$index] = $this->junk($existing->getSize());
		$riff->setChunks($chunks);
		$this->mergeAdjacentPadding();
		if ($chunk !== null && !$this->placeInPadding($chunk)) {
			$riff->addChunk($chunk);
		}
	}

	/**
	 * Writes a chunk into the file's existing padding, if any of it is the right size.  A
	 * `JUNK` chunk is space the file has already set aside, so filling it moves nothing:
	 * every byte before and after stays exactly where it was.  The smallest padding that can
	 * take the chunk is used, which leaves the larger runs intact for a larger write later.
	 * @param ImageChunk $chunk The chunk to place.
	 * @return bool Whether a padding chunk took it.
	 */
	protected function placeInPadding(ImageChunk $chunk): bool
	{
		$riff = $this->getRIFF();
		$want = $this->wholeSize(strlen($chunk->getData()));
		$best = null;
		$bestGap = null;
		foreach ($riff->getChunks() as $i => $candidate) {
			if ($candidate->getType() !== RIFFChunkType::Junk) {
				continue;
			}
			$gap = self::slotGap($this->wholeSize($candidate->getSize()), $want);
			if ($gap !== null && ($bestGap === null || $gap < $bestGap)) {
				$best = $i;
				$bestGap = $gap;
			}
		}
		if ($best === null || $bestGap === null) {
			return false;
		}
		$chunks = $riff->getChunks();
		$chunks[$best] = $chunk;
		if ($bestGap > 0) {
			array_splice($chunks, $best + 1, 0, [$this->junk($bestGap - self::MinimumChunk)]);
		}
		$riff->setChunks($chunks);
		return true;
	}

	/**
	 * Joins neighbouring padding chunks into one.  Two adjacent `JUNK` chunks occupy the same
	 * bytes as a single one of their combined length, so merging them moves nothing and turns
	 * two slots too small to be useful into one that may not be.
	 */
	protected function mergeAdjacentPadding(): void
	{
		$merged = [];
		foreach ($this->getRIFF()->getChunks() as $chunk) {
			$last = $merged === [] ? null : $merged[count($merged) - 1];
			if ($chunk->getType() === RIFFChunkType::Junk && $last !== null && $last->getType() === RIFFChunkType::Junk) {
				$merged[count($merged) - 1] = $this->junk($this->wholeSize($last->getSize()) + $this->wholeSize($chunk->getSize()) - self::MinimumChunk);
				continue;
			}
			$merged[] = $chunk;
		}
		$this->getRIFF()->setChunks($merged);
	}

	/**
	 * Returns the whole on-disk length of a chunk: its header, its payload, and the byte that
	 * pads an odd payload to an even length.
	 * @param int $payload The payload length.
	 * @return int The whole length.
	 */
	protected function wholeSize(int $payload): int
	{
		return self::MinimumChunk + $payload + ($payload & 1);
	}

	/**
	 * Returns how many bytes a replacement leaves over in the slot it is written into, or
	 * null when it cannot be written there at all.  Nothing left over is a perfect fit; what
	 * is left over must be enough for a padding chunk's own header, so a slot that frees
	 * fewer bytes than {@see MinimumChunk} has to be vacated rather than reused.
	 * @param int $slot The whole length available.
	 * @param int $want The whole length to write.
	 * @return ?int The bytes left over, or null when the replacement does not fit.
	 */
	protected static function slotGap(int $slot, int $want): ?int
	{
		$gap = $slot - $want;
		return ($gap === 0 || $gap >= self::MinimumChunk) ? $gap : null;
	}

	/**
	 * Builds a padding chunk of a payload length.
	 * @param int $payload The payload length.
	 * @return ImageChunk The padding chunk.
	 */
	protected function junk(int $payload): ImageChunk
	{
		return new ImageChunk(RIFFChunkType::Junk, $payload, 0, str_repeat("\0", $payload));
	}

	/**
	 * Removes the identifying INFO tags and the digitization timestamp, leaving every field
	 * that describes the video itself.  The XMP packet is scrubbed by the base class through
	 * {@see getXMP()}/{@see setXMP()}.
	 * @param int $types The {@see PrivacyCategory} flags to remove.
	 * @return int The number of fields removed.
	 */
	protected function clearFormatPrivateData(int $types): int
	{
		$removed = 0;
		$info = $this->getInfo();
		foreach (self::PrivacyInfoTags as $category => $ids) {
			if (!($types & $category)) {
				continue;
			}
			foreach ($ids as $id) {
				if (isset($info[$id])) {
					unset($info[$id]);
					$removed++;
				}
			}
		}
		if ($removed > 0) {
			$this->setInfo($info);
		}
		if (($types & PrivacyCategory::Timestamp) && $this->getDigitizationTime() !== null) {
			$this->setDigitizationTime(null);
			$removed++;
		}
		return $removed;
	}

	/**
	 * Writes a chunk the straightforward way, for a file that has been shown to tolerate its
	 * contents moving: the chunk simply replaces, is removed, or is inserted before the media
	 * list, and everything after it shifts.  No padding is left behind and the file grows or
	 * shrinks by exactly what changed.
	 * @param ?int $index The index of the chunk being replaced, or null when there is none.
	 * @param ?ImageChunk $chunk The chunk to store, or null to drop the existing one.
	 */
	protected function rearrangeChunk(?int $index, ?ImageChunk $chunk): void
	{
		$riff = $this->getRIFF();
		$chunks = $riff->getChunks();
		if ($index !== null) {
			if ($chunk === null) {
				unset($chunks[$index]);
			} else {
				$chunks[$index] = $chunk;
			}
			$riff->setChunks($chunks);
			return;
		}
		if ($chunk === null) {
			return;
		}
		$movi = $this->findMoviIndex();
		if ($movi === null) {
			$riff->addChunk($chunk);
			return;
		}
		array_splice($chunks, $movi, 0, [$chunk]);   // metadata belongs ahead of the media
		$riff->setChunks($chunks);
	}
}
