<?php

/**
 * TIFFIfd class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\TIFF;

/**
 * TIFFIfd class.
 *
 * One image file directory: an ordered set of {@see TIFFTag} fields keyed by tag id.
 * {@see getTag()}/{@see setTag()}/{@see removeTag()} manage the fields;
 * {@see getTagValue()} is the one-step convenient read.  {@see setTagValues()} builds
 * and stores a field from an id, type, and value set in one call.
 *
 * Fields are kept sorted by id, as the TIFF specification requires of a written IFD.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TIFFIfd implements \IteratorAggregate, \Countable
{
	/** @var array<int, TIFFTag> The fields, keyed by tag id. */
	private array $_tags = [];

	/**
	 * Returns a field by tag id.
	 * @param int $id The tag id.
	 * @return ?TIFFTag The field, or null when absent.
	 */
	public function getTag(int $id): ?TIFFTag
	{
		return $this->_tags[$id] ?? null;
	}

	/**
	 * Indicates whether a field is present.
	 * @param int $id The tag id.
	 * @return bool Whether the field exists.
	 */
	public function hasTag(int $id): bool
	{
		return isset($this->_tags[$id]);
	}

	/**
	 * Stores a field, replacing any field with the same id.
	 * @param TIFFTag $tag The field.
	 */
	public function setTag(TIFFTag $tag): void
	{
		$this->_tags[$tag->getId()] = $tag;
		ksort($this->_tags);
	}

	/**
	 * Builds and stores a field from its parts.
	 * @param int $id The tag id.
	 * @param int $type The {@see TIFFDataType}.
	 * @param array|string $values The value set.
	 * @return TIFFTag The stored field.
	 */
	public function setTagValues(int $id, int $type, array|string $values): TIFFTag
	{
		$tag = new TIFFTag($id, $type, $values);
		$this->setTag($tag);
		return $tag;
	}

	/**
	 * Removes a field.
	 * @param int $id The tag id.
	 * @return ?TIFFTag The removed field, or null when absent.
	 */
	public function removeTag(int $id): ?TIFFTag
	{
		$tag = $this->_tags[$id] ?? null;
		unset($this->_tags[$id]);
		return $tag;
	}

	/**
	 * Returns a field's convenient value form ({@see TIFFTag::getValue()}).
	 * @param int $id The tag id.
	 * @return mixed The value, or null when the field is absent.
	 */
	public function getTagValue(int $id): mixed
	{
		return isset($this->_tags[$id]) ? $this->_tags[$id]->getValue() : null;
	}

	/**
	 * Returns all fields keyed by tag id, sorted by id.
	 * @return array<int, TIFFTag> The fields.
	 */
	public function getTags(): array
	{
		return $this->_tags;
	}

	/**
	 * Returns the number of fields.
	 * @return int The field count.
	 */
	public function count(): int
	{
		return count($this->_tags);
	}

	/**
	 * Iterates the fields in tag-id order.
	 * @return \Iterator The field iterator.
	 */
	public function getIterator(): \Iterator
	{
		return new \ArrayIterator($this->_tags);
	}
}
