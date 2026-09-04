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
 * It exposes the {@see getFormType() form type} and the {@see getChunks() chunks};
 * {@see WebPImage} builds on it to read WebP dimensions.
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
	 * @param mixed $stream The seekable {@see StreamInterface} or PHP stream resource.
	 * @param string[] $deferTypes The chunk ids to keep deferred (the large payloads).
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
		if (strncmp($header, RIFFChunkType::Riff, 4) !== 0) {
			throw new \UnexpectedValueException(sprintf('RIFF data is invalid: %s.', 'missing RIFF header'));
		}
		$riff = new static();
		$riff->_formType = substr($header, 8, 4);
		while (true) {
			$start = $binary->tell();
			try {
				$chunkHeader = $binary->readBytes(8);
			} catch (\RuntimeException $e) {
				break;   // no more chunks
			}
			$id = substr($chunkHeader, 0, 4);
			$size = (int) unpack('V', substr($chunkHeader, 4, 4))[1];
			$whole = 8 + $size + ($size & 1); // header + payload + even-length pad
			if (in_array($id, $deferTypes, true)) {
				$riff->_chunks[] = ImageChunk::deferred($id, $size, $start + 8, new SourceRange($stream, $start, $whole));
			} else {
				$riff->_chunks[] = new ImageChunk($id, $size, $start + 8, $binary->readBytes($size));
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
		$body = $this->getFormType();
		foreach ($this->getChunks() as $chunk) {
			$data = $chunk->getData();
			$body .= $chunk->getType() . pack('V', strlen($data)) . $data;
			if (strlen($data) & 1) {
				$body .= "\0"; // pad to an even length
			}
		}
		return RIFFChunkType::Riff . pack('V', strlen($body)) . $body;
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
		$bodyLength = strlen($this->getFormType());
		foreach ($this->getChunks() as $chunk) {
			$dataLength = $chunk->getDeferredRange() !== null ? $chunk->getSize() : strlen($chunk->getData());
			$bodyLength += 8 + $dataLength + ($dataLength & 1);
		}
		$written = StreamIO::writeAll($target, RIFFChunkType::Riff . pack('V', $bodyLength) . $this->getFormType());
		foreach ($this->getChunks() as $chunk) {
			$range = $chunk->getDeferredRange();
			if ($range !== null) {
				$written += $range->writeTo($target);   // whole chunk: id + size + payload + pad, verbatim
				continue;
			}
			$data = $chunk->getData();
			$bytes = $chunk->getType() . pack('V', strlen($data)) . $data;
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
		if ($len < 12 || strncmp($bytes, RIFFChunkType::Riff, 4) !== 0) {
			throw new \UnexpectedValueException(sprintf('RIFF data is invalid: %s.', 'missing RIFF header'));
		}
		$this->_formType = substr($bytes, 8, 4);

		$i = 12;
		while ($i + 8 <= $len) {
			$id = substr($bytes, $i, 4);
			$size = (int) unpack('V', substr($bytes, $i + 4, 4))[1];
			$payload = substr($bytes, $i + 8, $size);
			$this->_chunks[] = new ImageChunk($id, $size, $i + 8, $payload);
			$i += 8 + $size + ($size & 1); // payload padded to an even length
		}
	}
}
