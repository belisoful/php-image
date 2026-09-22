<?php

/**
 * RIFFContainer class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image;

use Belisoful\Image\Stream\BinaryReader;
use Belisoful\Image\Stream\SourceRange;
use Belisoful\Image\Stream\StreamIO;
use Psr\Http\Message\StreamInterface;

/**
 * RIFFContainer class.
 *
 * Reads a Resource Interchange File Format container (used by WAV, AVI, and WebP).  A
 * RIFF file is the literal `RIFF`, a little-endian 32-bit size, a four-character form
 * type (such as `WEBP`), then a sequence of chunks, each a four-character id, a
 * little-endian 32-bit size, and a payload padded to an even length.
 *
 * Three variants of that header exist and all are read and written back as they were found:
 *
 * - **`RIFX`** is the same grammar with **every size big-endian**.  The byte order is carried
 *   into nested lists, so a `RIFX` file rewrites correctly at any depth.
 * - **`RF64`** (EBU Tech 3306) and **`BW64`** (ITU-R BS.2088, the same structure under its
 *   later name) carry files past four gigabytes: the container size is the sentinel
 *   `0xFFFFFFFF` and the real 64-bit sizes live in a leading `ds64` chunk — one field for the
 *   container, one for `data`, and a table for any other chunk.  A chunk whose stored size is
 *   the sentinel takes its real size from there, and a rewrite updates `ds64` to match what
 *   it actually wrote.
 *
 * It exposes the {@see getFormType() form type} and the {@see getChunks() chunks};
 * {@see WebPImage} builds on it to read WebP dimensions.
 *
 * A `LIST` chunk holds a four-character list type and a nested chunk sequence in the same
 * grammar, so the walk recurses: a list is read as a {@see RIFFList}, which is a chunk to
 * this container and a chunk collection in its own right.  The nesting is what AVI's
 * `LIST hdrl`/`LIST INFO` and Exif WAVE's `LIST exif` are made of.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class RIFFContainer
{
	use StreamIOTrait;

	/**
	 * Final so the static parse factories can safely instantiate any subclass with
	 * `new static()`; construction state comes from parse(), never constructor arguments.
	 */
	final public function __construct()
	{
	}

	/** The container signatures this class reads; the first is the ordinary one. */
	public const Signatures = [
		RIFFChunkType::Riff,
		RIFFChunkType::Rifx,
		RIFFChunkType::Rf64,
		RIFFChunkType::Bw64,
	];

	/** @var string The container signature, one of {@see Signatures}. */
	private string $_signature = RIFFChunkType::Riff;

	/** @var string The four-character form type (e.g. 'WEBP', 'WAVE', 'AVI '). */
	private string $_formType = '';


	/** @var array<int, ImageChunk> The chunks in file order. */
	private array $_chunks = [];

	/**
	 * Creates a reader from a raw byte string.
	 * @param string $bytes The RIFF bytes.
	 * @return static The parsed reader.
	 */
	public static function fromString(string $bytes): static
	{
		$riff = new static();
		$riff->parse($bytes);
		return $riff;
	}

	/**
	 * Creates a reader from a PSR-7 stream or stream resource, reading it in full
	 * (a seekable stream is rewound first).
	 * @param mixed $stream The RIFF {@see StreamInterface} or PHP stream resource.
	 * @return static The parsed reader.
	 */
	public static function fromStream(mixed $stream): static
	{
		StreamIO::rewind($stream);
		return static::fromString(static::sourceBytes($stream));
	}

	/**
	 * Lazily reads a RIFF container from a seekable stream: every chunk header is read,
	 * but a chunk whose id is in {@see $deferTypes} keeps its bytes as a deferred range
	 * into the still-open source rather than loading them, so a container far larger than
	 * memory (a big WebP pixel chunk) opens for a metadata edit.  Pair it with
	 * {@see streamTo()}; the source must stay open and seekable until then.
	 * A `LIST` may be deferred either by its bare id or by the qualified `LIST:<listType>`
	 * form, so AVI can stream its `LIST movi` past while still reading `LIST hdrl` and
	 * `LIST INFO`.  A deferred list stays an opaque {@see ImageChunk}; one that is read
	 * becomes a {@see RIFFList} exactly as an eager parse would make it.
	 * @param mixed $stream The seekable {@see StreamInterface} or PHP stream resource.
	 * @param string[] $deferTypes The chunk ids to keep deferred (the large payloads), each a
	 *   four-character id or, for a list, the qualified `LIST:<listType>`.
	 * @throws \RuntimeException When the stream is not seekable.
	 * @throws \UnexpectedValueException When the bytes lack a RIFF header.
	 * @return static The lazily parsed container.
	 */
	public static function fromStreamLazy(mixed $stream, array $deferTypes): static
	{
		StreamIO::rewind($stream);
		$binary = new BinaryReader($stream);
		if (!$binary->isSeekable()) {
			throw new \RuntimeException('Lazily reading a RIFF container requires a seekable stream.');
		}
		$header = $binary->readBytes(12);
		if (!in_array(substr($header, 0, 4), self::Signatures, true)) {
			throw new \UnexpectedValueException(sprintf('RIFF data is invalid: %s.', 'missing RIFF header'));
		}
		$riff = new static();
		$riff->_signature = substr($header, 0, 4);
		$riff->_formType = substr($header, 8, 4);
		$big = $riff->getIsBigEndian();
		$sizes64 = [];
		while (true) {
			$start = $binary->tell();
			try {
				$chunkHeader = $binary->readBytes(8);
			} catch (\RuntimeException $e) {
				break;   // no more chunks
			}
			$id = substr($chunkHeader, 0, 4);
			$size = self::unpackSize(substr($chunkHeader, 4, 4), $big);
			if ($size === RIFFChunkType::Size64Sentinel && isset($sizes64[$id])) {
				$size = $sizes64[$id];
			}
			$whole = 8 + $size + ($size & 1); // header + payload + even-length pad
			$isList = $id === RIFFChunkType::RiffList && $size >= 4;
			$qualified = $isList ? $id . ':' . $binary->readBytes(4) : $id;
			if ($isList) {
				$binary->seek($start + 8); // the list type was only peeked at
			}
			if (in_array($id, $deferTypes, true) || ($isList && in_array($qualified, $deferTypes, true))) {
				$riff->_chunks[] = ImageChunk::deferred($id, $size, $start + 8, new SourceRange($stream, $start, $whole));
			} else {
				$payload = $binary->readBytes($size);
				if ($id === RIFFChunkType::DataSize64) {
					$sizes64 = self::readSize64Chunk($payload);   // the first chunk sizes the rest
				}
				$riff->_chunks[] = $isList
					? new RIFFList($id, $size, $start + 8, $payload, 0, $big)
					: new ImageChunk($id, $size, $start + 8, $payload);
			}
			$binary->seek($start + $whole);
		}
		return $riff;
	}

	/**
	 * Creates a reader from a file path.
	 * @param string $path The file path.
	 * @throws \RuntimeException When the file cannot be read.
	 * @return static The parsed reader.
	 */
	public static function fromFile(string $path): static
	{
		$bytes = @file_get_contents($path);
		if ($bytes === false) {
			throw new \RuntimeException(sprintf('Image file \'%s\' cannot be read.', $path));
		}
		return static::fromString($bytes);
	}

	/**
	 * Packs a chunk size in the container's byte order.
	 * @param int $size The size.
	 * @param bool $bigEndian Whether the container is `RIFX`.
	 * @return string The four packed bytes.
	 */
	public static function packSize(int $size, bool $bigEndian): string
	{
		return pack($bigEndian ? 'N' : 'V', $size);
	}

	/**
	 * Reads a chunk size in the container's byte order.
	 * @param string $bytes The four packed bytes.
	 * @param bool $bigEndian Whether the container is `RIFX`.
	 * @return int The size.
	 */
	public static function unpackSize(string $bytes, bool $bigEndian): int
	{
		return (int) unpack($bigEndian ? 'N' : 'V', str_pad($bytes, 4, "\0"))[1];
	}

	/**
	 * Returns the container signature: `RIFF`, the big-endian `RIFX`, or the 64-bit `RF64`
	 * or `BW64`.
	 * @return string The signature.
	 */
	public function getSignature(): string
	{
		return $this->_signature;
	}

	/**
	 * Sets the container signature, which decides the byte order and whether sizes may be
	 * carried in a `ds64` chunk.
	 * @param string $value One of {@see Signatures}.
	 * @throws \UnexpectedValueException When the signature is not one this class writes.
	 */
	public function setSignature(string $value): void
	{
		if (!in_array($value, self::Signatures, true)) {
			throw new \UnexpectedValueException(sprintf('RIFF data is invalid: %s.', 'unknown container signature ' . $value));
		}
		$this->_signature = $value;
	}

	/**
	 * Indicates whether the container's sizes are big-endian, which only `RIFX` makes them.
	 * @return bool Whether the sizes are big-endian.
	 */
	public function getIsBigEndian(): bool
	{
		return $this->_signature === RIFFChunkType::Rifx;
	}

	/**
	 * Indicates whether the container carries its real sizes in a `ds64` chunk, which the
	 * 64-bit signatures do.
	 * @return bool Whether the container is a 64-bit one.
	 */
	public function getIsSize64(): bool
	{
		return $this->_signature === RIFFChunkType::Rf64 || $this->_signature === RIFFChunkType::Bw64;
	}

	/**
	 * Returns the four-character form type.
	 * @return string The form type (e.g. 'WEBP').
	 */
	public function getFormType(): string
	{
		return $this->_formType;
	}

	/**
	 * Returns all chunks in file order.
	 * @return array<int, ImageChunk> The chunks.
	 */
	public function getChunks(): array
	{
		return $this->_chunks;
	}

	/**
	 * Replaces the chunks wholesale, for a caller that rearranges them itself (AVI places a
	 * metadata chunk by index, to hold the media list where it is).
	 * @param array<int, ImageChunk> $value The chunks in file order.
	 */
	public function setChunks(array $value): void
	{
		$this->_chunks = array_values($value);
	}

	/**
	 * Returns the first chunk of a given id.
	 * @param string $id The four-character chunk id (e.g. 'VP8 ', 'VP8L').
	 * @return ?ImageChunk The chunk, or null when absent.
	 */
	public function getChunk(string $id): ?ImageChunk
	{
		foreach ($this->getChunks() as $chunk) {
			if ($chunk->getType() === $id) {
				return $chunk;
			}
		}
		return null;
	}

	/**
	 * Returns the first `LIST` chunk of a given list type (e.g. 'INFO', 'hdrl', 'exif').
	 * @param string $listType The four-character list type.
	 * @return ?RIFFList The list, or null when absent.
	 */
	public function getList(string $listType): ?RIFFList
	{
		foreach ($this->getChunks() as $chunk) {
			if ($chunk instanceof RIFFList && $chunk->getListType() === $listType) {
				return $chunk;
			}
		}
		return null;
	}

	/**
	 * Sets the four-character form type (e.g. 'WAVE', 'WEBP').
	 * @param string $value The form type.
	 */
	public function setFormType(string $value): void
	{
		$this->_formType = substr(str_pad($value, 4), 0, 4);
	}

	/**
	 * Stores a chunk: replaces the first chunk with the same id, or appends.
	 * @param ImageChunk $chunk The chunk.
	 */
	public function setChunk(ImageChunk $chunk): void
	{
		// No ordering: a plain RIFF form places its chunks freely.  The formats whose
		// order is normative pass one to {@see setChunkInOrder()}.
		$this->setChunkInOrder($chunk, []);
	}

	/**
	 * Appends a chunk, even when one of the same id is present.
	 * @param ImageChunk $chunk The chunk.
	 */
	public function addChunk(ImageChunk $chunk): void
	{
		$this->_chunks[] = $chunk;
	}

	/**
	 * Inserts a chunk before every other, for the formats whose header chunk must lead.
	 * @param ImageChunk $chunk The chunk.
	 */
	public function prependChunk(ImageChunk $chunk): void
	{
		array_unshift($this->_chunks, $chunk);
	}

	/**
	 * Inserts a chunk at a position, for the formats whose chunk order is normative.
	 * @param ImageChunk $chunk The chunk.
	 * @param int $index The position; clamped to the chunk count, so a large index appends.
	 */
	public function insertChunk(ImageChunk $chunk, int $index): void
	{
		array_splice($this->_chunks, max(0, min($index, count($this->_chunks))), 0, [$chunk]);
	}

	/**
	 * Stores a chunk in the position a canonical ordering gives it: an existing chunk of
	 * the same id is replaced in place, otherwise the chunk is inserted before the first
	 * chunk that must follow it.  An id absent from the ordering is appended.
	 * @param ImageChunk $chunk The chunk.
	 * @param string[] $order The chunk ids in the order the format requires.
	 */
	public function setChunkInOrder(ImageChunk $chunk, array $order): void
	{
		foreach ($this->_chunks as $i => $existing) {
			if ($existing->getType() === $chunk->getType()) {
				$this->_chunks[$i] = $chunk;
				return;
			}
		}
		$rank = array_search($chunk->getType(), $order, true);
		if ($rank === false) {
			$this->_chunks[] = $chunk;
			return;
		}
		foreach ($this->_chunks as $i => $existing) {
			$existingRank = array_search($existing->getType(), $order, true);
			if ($existingRank === false || $existingRank > $rank) {
				$this->insertChunk($chunk, $i);
				return;
			}
		}
		$this->_chunks[] = $chunk;
	}

	/**
	 * Removes every chunk with an id.
	 * @param string $id The four-character chunk id.
	 * @return bool Whether a chunk was removed.
	 */
	public function removeChunk(string $id): bool
	{
		$before = count($this->_chunks);
		$this->_chunks = array_values(array_filter($this->_chunks, fn ($c) => $c->getType() !== $id));
		return count($this->_chunks) !== $before;
	}

	/**
	 * Rebuilds the RIFF container from its form type and chunks.
	 * @return string The composed RIFF bytes.
	 */
	public function toBinary(): string
	{
		$big = $this->getIsBigEndian();
		$this->updateSize64();
		$body = $this->getFormType();
		foreach ($this->getChunks() as $chunk) {
			$data = $chunk->getData();
			$body .= $chunk->getType() . self::packSize($this->storedSize($chunk->getType(), strlen($data)), $big) . $data;
			if (strlen($data) & 1) {
				$body .= "\0"; // pad to an even length
			}
		}
		return $this->_signature . self::packSize($this->storedSize('', strlen($body)), $big) . $body;
	}

	/**
	 * Writes the container to a target, copying each deferred chunk straight from the
	 * source in bounded memory and rebuilding every other (loaded or edited) chunk, so a
	 * container opened with {@see fromStreamLazy()} is rewritten without holding its large
	 * payloads.  The RIFF size header is computed from the chunk sizes, deferred included.
	 * @param mixed $target A writable {@see StreamInterface} or PHP stream resource.
	 * @throws \InvalidArgumentException When the target is neither.
	 * @throws \RuntimeException When the target stops accepting bytes.
	 * @return int The number of bytes written.
	 */
	public function streamTo(mixed $target): int
	{
		$big = $this->getIsBigEndian();
		$this->updateSize64();
		$bodyLength = strlen($this->getFormType());
		foreach ($this->getChunks() as $chunk) {
			$dataLength = $chunk->getDeferredRange() !== null ? $chunk->getSize() : strlen($chunk->getData());
			$bodyLength += 8 + $dataLength + ($dataLength & 1);
		}
		$written = StreamIO::writeAll($target, $this->_signature . self::packSize($this->storedSize('', $bodyLength), $big) . $this->getFormType());
		foreach ($this->getChunks() as $chunk) {
			$range = $chunk->getDeferredRange();
			if ($range !== null) {
				$written += $range->writeTo($target);   // whole chunk: id + size + payload + pad, verbatim
				continue;
			}
			$data = $chunk->getData();
			$bytes = $chunk->getType() . self::packSize($this->storedSize($chunk->getType(), strlen($data)), $big) . $data;
			if (strlen($data) & 1) {
				$bytes .= "\0";
			}
			$written += StreamIO::writeAll($target, $bytes);
		}
		return $written;
	}

	/**
	 * Walks the RIFF header and chunk list.
	 * @param string $bytes The RIFF bytes.
	 * @throws \UnexpectedValueException When the bytes lack a RIFF header.
	 */
	protected function parse(string $bytes): void
	{
		$len = strlen($bytes);
		if ($len < 12 || !in_array(substr($bytes, 0, 4), self::Signatures, true)) {
			throw new \UnexpectedValueException(sprintf('RIFF data is invalid: %s.', 'missing RIFF header'));
		}
		$this->_signature = substr($bytes, 0, 4);
		$this->_formType = substr($bytes, 8, 4);
		[$this->_chunks] = self::parseChunks($bytes, 12, $len, 0, 0, $this->getIsBigEndian(), $this->readSize64($bytes));
	}

	/**
	 * Walks a RIFF chunk sequence — the container's own body and, recursively, the payload of
	 * every {@see RIFFList} — into chunks in file order.  A `LIST` whose payload can hold a
	 * list type becomes a {@see RIFFList} until {@see RIFFList::MaxDepth} is reached, past
	 * which it stays an opaque {@see ImageChunk}.
	 *
	 * The trailing bytes that are not a whole chunk are returned rather than dropped, so the
	 * caller can re-append them and rewrite a sequence byte-faithfully.
	 * @param string $bytes The bytes holding the sequence.
	 * @param int $start The index the sequence starts at.
	 * @param int $end The index the sequence ends at.
	 * @param int $origin The file offset that index 0 of $bytes sits at, for chunk offsets.
	 * @param int $depth The nesting depth of the sequence, counted from the container's chunks.
	 * @param bool $bigEndian Whether the sizes are big-endian, as an `RIFX` container's are.
	 * @param array<string, int> $sizes64 The real sizes of the chunks whose stored size is the
	 *   {@see RIFFChunkType::Size64Sentinel}, keyed by chunk id, from a `ds64` chunk.
	 * @return array{0: array<int, ImageChunk>, 1: string} The chunks and the trailing remainder.
	 */
	public static function parseChunks(string $bytes, int $start, int $end, int $origin = 0, int $depth = 0, bool $bigEndian = false, array $sizes64 = []): array
	{
		$chunks = [];
		$i = $start;
		while ($i + 8 <= $end) {
			$id = substr($bytes, $i, 4);
			$size = self::unpackSize(substr($bytes, $i + 4, 4), $bigEndian);
			if ($size === RIFFChunkType::Size64Sentinel && isset($sizes64[$id])) {
				$size = $sizes64[$id];   // the real size lives in the ds64 chunk
			}
			$payload = substr($bytes, $i + 8, max(0, min($size, $end - $i - 8)));
			if ($id === RIFFChunkType::RiffList && strlen($payload) >= 4 && $depth < RIFFList::MaxDepth) {
				$chunks[] = new RIFFList($id, $size, $origin + $i + 8, $payload, $depth, $bigEndian);
			} else {
				$chunks[] = new ImageChunk($id, $size, $origin + $i + 8, $payload);
			}
			$i += 8 + $size + ($size & 1); // payload padded to an even length
		}
		return [$chunks, $i < $end ? substr($bytes, $i, $end - $i) : ''];
	}
	//
	// ─── The 64-bit size chunk ───────────────────────────────────────────────
	//

	/**
	 * Reads the real sizes a 64-bit container keeps in its leading `ds64` chunk, so the walk
	 * can resolve a chunk whose stored size is the sentinel.
	 * @param string $bytes The whole container.
	 * @return array<string, int> The real sizes, keyed by chunk id; empty for a 32-bit container.
	 */
	protected function readSize64(string $bytes): array
	{
		if (!$this->getIsSize64() || substr($bytes, 12, 4) !== RIFFChunkType::DataSize64) {
			return [];
		}
		$size = self::unpackSize(substr($bytes, 16, 4), $this->getIsBigEndian());
		return self::readSize64Chunk(substr($bytes, 20, $size));
	}

	/**
	 * Reads a `ds64` payload: the container size, the `data` size, the sample count, then a
	 * table of any other chunk that needs a 64-bit size.
	 * @param string $payload The `ds64` payload.
	 * @return array<string, int> The real sizes, keyed by chunk id.  The container's own size
	 *   is keyed by the empty string, since it has no chunk id.
	 */
	protected static function readSize64Chunk(string $payload): array
	{
		if (strlen($payload) < 28) {
			return [];
		}
		$sizes = [
			'' => self::unpackSize64(substr($payload, 0, 8)),
			RIFFChunkType::WaveData => self::unpackSize64(substr($payload, 8, 8)),
		];
		$count = self::unpackSize(substr($payload, 24, 4), false);
		for ($i = 0; $i < $count; $i++) {
			$entry = 28 + $i * 12;
			if ($entry + 12 > strlen($payload)) {
				break;
			}
			$sizes[substr($payload, $entry, 4)] = self::unpackSize64(substr($payload, $entry + 4, 8));
		}
		return $sizes;
	}

	/**
	 * Rewrites the `ds64` chunk so its sizes are the ones about to be written: the container
	 * size, the `data` size, and each id the table already names.  The sample count is left
	 * as it was read, being a fact about the audio rather than about the layout.
	 */
	protected function updateSize64(): void
	{
		$ds64 = $this->getIsSize64() ? $this->getChunk(RIFFChunkType::DataSize64) : null;
		if ($ds64 === null) {
			return;
		}
		$payload = $ds64->getData();
		if (strlen($payload) < 28) {
			return;
		}
		$lengths = [];
		foreach ($this->getChunks() as $chunk) {
			$lengths[$chunk->getType()] = $chunk->getDeferredRange() !== null ? $chunk->getSize() : strlen($chunk->getData());
		}
		$body = strlen($this->getFormType());
		foreach ($lengths as $length) {
			$body += 8 + $length + ($length & 1);
		}
		$payload = substr_replace($payload, self::packSize64($body), 0, 8);
		if (isset($lengths[RIFFChunkType::WaveData])) {
			$payload = substr_replace($payload, self::packSize64($lengths[RIFFChunkType::WaveData]), 8, 8);
		}
		$count = self::unpackSize(substr($payload, 24, 4), false);
		for ($i = 0; $i < $count; $i++) {
			$entry = 28 + $i * 12;
			if ($entry + 12 > strlen($payload)) {
				break;
			}
			$id = substr($payload, $entry, 4);
			if (isset($lengths[$id])) {
				$payload = substr_replace($payload, self::packSize64($lengths[$id]), $entry + 4, 8);
			}
		}
		$ds64->setData($payload);
	}

	/**
	 * Returns the size to store in a chunk's 32-bit header: the sentinel when a 64-bit
	 * container carries that chunk's real size in `ds64`, and the size itself otherwise.
	 * @param string $id The chunk id, or the empty string for the container's own size.
	 * @param int $size The real size.
	 * @return int The size to store.
	 */
	protected function storedSize(string $id, int $size): int
	{
		if (!$this->getIsSize64()) {
			return $size;
		}
		$ds64 = $this->getChunk(RIFFChunkType::DataSize64);
		return $ds64 !== null && array_key_exists($id, self::readSize64Chunk($ds64->getData()))
			? RIFFChunkType::Size64Sentinel
			: $size;
	}

	/**
	 * Reads a little-endian 64-bit size.
	 * @param string $bytes The eight packed bytes.
	 * @return int The size.
	 */
	protected static function unpackSize64(string $bytes): int
	{
		return (int) unpack('P', str_pad($bytes, 8, "\0"))[1];
	}

	/**
	 * Packs a little-endian 64-bit size.
	 * @param int $size The size.
	 * @return string The eight packed bytes.
	 */
	protected static function packSize64(int $size): string
	{
		return pack('P', $size);
	}
}
