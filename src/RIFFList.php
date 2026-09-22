<?php

/**
 * RIFFList class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image;

/**
 * RIFFList class.
 *
 * One `LIST` chunk of a RIFF container.  A list's payload is a four-character list type
 * (such as `INFO`, `hdrl`, or `exif`) followed by a nested chunk sequence in exactly the
 * grammar the container itself uses, so a list is both a {@see ImageChunk} to whatever
 * holds it and a chunk collection in its own right.  Lists nest: AVI's `LIST hdrl` holds
 * a `LIST strl` per stream.
 *
 * The children are **materialized on demand** — a list nobody looks into keeps its payload
 * exactly as it was read.  Once they are materialized the payload is always recomposed
 * from them, so a child edited in place is never lost to a stale copy; recomposition is
 * byte-faithful because the walker hands back any trailing bytes that are not a whole
 * chunk and {@see getData()} re-appends them.
 *
 * A **deferred** list (one {@see RIFFContainer::fromStreamLazy()} was told to keep as a range into
 * its source, such as AVI's multi-gigabyte `LIST movi`) is deliberately not one of these:
 * it stays an opaque {@see ImageChunk} that streams straight through, because parsing it
 * is the one thing a lazy read exists to avoid.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class RIFFList extends ImageChunk
{
	/**
	 * The deepest list nesting the walker follows.  Beyond it a `LIST` is kept as a plain
	 * {@see ImageChunk}, so a cyclic or hostile file cannot recurse without bound.
	 */
	public const MaxDepth = 16;

	/** @var string The four-character list type (e.g. 'INFO', 'hdrl', 'exif'). */
	private string $_listType;

	/** @var int The nesting depth of this list, counted from the container's own chunks. */
	private int $_depth;

	/** @var ?array<int, ImageChunk> The child chunks, or null until materialized. */
	private ?array $_children = null;

	/** @var string Trailing payload bytes that are not a whole chunk, kept for a faithful rewrite. */
	private string $_remainder = '';

	/** @var bool Whether the container's sizes are big-endian, as an `RIFX` container's are. */
	private bool $_bigEndian = false;

	/**
	 * @param string $type The four-character chunk type (`LIST`).
	 * @param int $size The payload size in bytes.
	 * @param int $offset The byte offset of the payload within the file.
	 * @param string $data The payload bytes (the list type, then the child chunks).
	 * @param int $depth The nesting depth of this list.
	 * @param bool $bigEndian Whether the container's sizes are big-endian (`RIFX`).
	 */
	public function __construct(string $type, int $size, int $offset, string $data, int $depth = 0, bool $bigEndian = false)
	{
		$this->_listType = substr($data, 0, 4);
		$this->_depth = $depth;
		$this->_bigEndian = $bigEndian;
		parent::__construct($type, $size, $offset, $data);
	}

	/**
	 * Returns the four-character list type.
	 * @return string The list type (e.g. 'INFO').
	 */
	public function getListType(): string
	{
		return $this->_listType;
	}

	/**
	 * Sets the four-character list type, keeping the children.
	 * @param string $value The list type; padded or truncated to four characters.
	 */
	public function setListType(string $value): void
	{
		$this->getChunks();  // materialize before the payload is recomposed from the children
		$this->_listType = substr(str_pad($value, 4), 0, 4);
	}

	/**
	 * Indicates whether the child sizes are written big-endian, as an `RIFX` container's are.
	 * @return bool Whether the sizes are big-endian.
	 */
	public function getIsBigEndian(): bool
	{
		return $this->_bigEndian;
	}

	/**
	 * Sets whether the child sizes are written big-endian.
	 * @param bool $value Whether the sizes are big-endian.
	 */
	public function setIsBigEndian(bool $value): void
	{
		$this->_bigEndian = $value;
	}

	/**
	 * Returns the nesting depth of this list, counted from the container's own chunks.
	 * @return int The nesting depth.
	 */
	public function getDepth(): int
	{
		return $this->_depth;
	}

	/**
	 * Returns the child chunks in order, walking the payload on the first call.
	 * @return array<int, ImageChunk> The child chunks.
	 */
	public function getChunks(): array
	{
		if ($this->_children === null) {
			$payload = parent::getData();
			$this->_listType = substr($payload, 0, 4);
			[$this->_children, $this->_remainder] = RIFFContainer::parseChunks($payload, 4, strlen($payload), $this->getOffset(), $this->_depth + 1, $this->_bigEndian);
		}
		return $this->_children;
	}

	/**
	 * Replaces the child chunks wholesale.
	 * @param array<int, ImageChunk> $value The child chunks in order.
	 */
	public function setChunks(array $value): void
	{
		$this->getChunks();  // materialize, so the list type and remainder are known
		$this->_children = array_values($value);
	}

	/**
	 * Returns the first child chunk of a given id.
	 * @param string $id The four-character chunk id.
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
	 * Returns the first nested list of a given list type.
	 * @param string $listType The four-character list type (e.g. 'strl').
	 * @return ?RIFFList The nested list, or null when absent.
	 */
	public function getList(string $listType): ?RIFFList
	{
		foreach ($this->getChunks() as $chunk) {
			if ($chunk instanceof self && $chunk->getListType() === $listType) {
				return $chunk;
			}
		}
		return null;
	}

	/**
	 * Stores a child chunk: replaces the first child with the same id, or appends.
	 * @param ImageChunk $chunk The chunk.
	 */
	public function setChunk(ImageChunk $chunk): void
	{
		$children = $this->getChunks();
		foreach ($children as $i => $existing) {
			if ($existing->getType() === $chunk->getType()) {
				$children[$i] = $chunk;
				$this->_children = $children;
				return;
			}
		}
		$children[] = $chunk;
		$this->_children = $children;
	}

	/**
	 * Appends a child chunk, even when one of the same id is present.
	 * @param ImageChunk $chunk The chunk.
	 */
	public function addChunk(ImageChunk $chunk): void
	{
		$children = $this->getChunks();
		$children[] = $chunk;
		$this->_children = $children;
	}

	/**
	 * Removes every child chunk with an id.
	 * @param string $id The four-character chunk id.
	 * @return bool Whether a chunk was removed.
	 */
	public function removeChunk(string $id): bool
	{
		$children = $this->getChunks();
		$this->_children = array_values(array_filter($children, fn ($c) => $c->getType() !== $id));
		return count($this->_children) !== count($children);
	}

	/**
	 * Returns the payload: the stored bytes while the children are untouched, otherwise the
	 * list type, the recomposed children, and any trailing bytes the walker could not read
	 * as a whole chunk.
	 * @return string The payload.
	 */
	public function getData(): string
	{
		if ($this->_children === null) {
			return parent::getData();
		}
		$body = $this->_listType;
		foreach ($this->_children as $chunk) {
			$data = $chunk->getData();
			$body .= $chunk->getType() . RIFFContainer::packSize(strlen($data), $this->_bigEndian) . $data;
			if (strlen($data) & 1) {
				$body .= "\0"; // pad to an even length
			}
		}
		return $body . $this->_remainder;
	}

	/**
	 * Returns the payload size, measuring the recomposed payload once the children are
	 * materialized so an edited list reports what it will write.
	 * @return int The payload size.
	 */
	public function getSize(): int
	{
		return $this->_children === null ? parent::getSize() : strlen($this->getData());
	}

	/**
	 * Replaces the payload wholesale, dropping any materialized children so the new bytes
	 * are the list rather than a stale parse of the old ones.
	 * @param string $value The payload (the list type, then the child chunks).
	 */
	public function setData(string $value): void
	{
		$this->_children = null;
		$this->_remainder = '';
		$this->_listType = substr($value, 0, 4);
		parent::setData($value);
	}
}
