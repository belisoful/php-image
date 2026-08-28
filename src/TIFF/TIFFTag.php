<?php

/**
 * TIFFTag class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\TIFF;

/**
 * TIFFTag class.
 *
 * One IFD field: the numeric {@see getId() id}, the {@see getType() TIFF data type},
 * and the {@see getValues() value set} ({@see TIFFDataType}'s representation — an
 * array, or a byte string for Ascii/Undefined).  {@see getValue()} unwraps a
 * single-element set to its scalar, and {@see getRational()} answers a rational
 * element as a float.
 *
 * A tag whose value is itself an IFD (the EXIF, GPS, and Interoperability sub-IFD
 * pointers) carries the parsed child as {@see getSubIfd()}; the pointer value is then
 * recomputed on compose.  A tag read from a file remembers its original value-area
 * {@see getOffset() offset}; setting {@see setPreserveOffset()} asks the writer to
 * keep the value at that exact offset (the makernote safeguard — internal makernote
 * pointers stay valid across a rewrite).
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TIFFTag
{
	/** @var int The tag id. */
	private int $_id;

	/** @var int The {@see TIFFDataType} of the value set. */
	private int $_type;

	/** @var array|string The value set (byte string for Ascii/Undefined). */
	private array|string $_values;

	/** @var ?TIFFIfd The parsed child IFD of a sub-IFD pointer tag. */
	private ?TIFFIfd $_subIfd = null;

	/** @var ?int The value-area offset the tag was read from, when out-of-line. */
	private ?int $_offset = null;

	/** @var bool Whether the writer must keep the value at its original offset. */
	private bool $_preserveOffset = false;

	/** @var ?string[] The captured data blocks of an offsets tag (strips/tiles), or null. */
	private ?array $_externalData = null;

	/**
	 * Constructs a tag.
	 * @param int $id The tag id.
	 * @param int $type The {@see TIFFDataType}.
	 * @param array|string $values The value set.
	 */
	public function __construct(int $id, int $type, array|string $values = [])
	{
		$this->_id = $id;
		$this->_type = $type;
		$this->_values = $values;
	}

	/**
	 * Returns the tag id.
	 * @return int The tag id.
	 */
	public function getId(): int
	{
		return $this->_id;
	}

	/**
	 * Returns the TIFF data type.
	 * @return int A {@see TIFFDataType} constant.
	 */
	public function getType(): int
	{
		return $this->_type;
	}

	/**
	 * Sets the TIFF data type.
	 * @param int $value A {@see TIFFDataType} constant.
	 */
	public function setType(int $value): void
	{
		TIFFDataType::getSize($value);
		$this->_type = $value;
	}

	/**
	 * Returns the value set.
	 * @return array|string The values (a byte string for Ascii/Undefined).
	 */
	public function getValues(): array|string
	{
		return $this->_values;
	}

	/**
	 * Sets the value set.
	 * @param array|string $values The values (a byte string for Ascii/Undefined).
	 */
	public function setValues(array|string $values): void
	{
		$this->_values = $values;
	}

	/**
	 * Returns the element count of the value set.
	 * @return int The element count.
	 */
	public function getCount(): int
	{
		return TIFFDataType::countOf($this->_type, $this->_values);
	}

	/**
	 * Returns the single value of a one-element set, the trimmed string of an Ascii
	 * tag, or the whole value set.
	 * @return mixed The convenient scalar form, or the full set.
	 */
	public function getValue(): mixed
	{
		if ($this->_type === TIFFDataType::Ascii || $this->_type === TIFFDataType::Utf8) {
			return rtrim((string) $this->_values, "\0");
		}
		if (is_array($this->_values) && count($this->_values) === 1) {
			return $this->_values[0];
		}
		return $this->_values;
	}

	/**
	 * Returns a rational element as a float.
	 * @param int $index The element index. Default 0.
	 * @return ?float The quotient, or null when not rational or dividing by zero.
	 */
	public function getRational(int $index = 0): ?float
	{
		if (($this->_type !== TIFFDataType::URational && $this->_type !== TIFFDataType::SRational)
			|| !isset($this->_values[$index]) || !$this->_values[$index][1]) {
			return null;
		}
		return $this->_values[$index][0] / $this->_values[$index][1];
	}

	/**
	 * Returns the parsed child IFD of a sub-IFD pointer tag.
	 * @return ?TIFFIfd The child IFD, or null.
	 */
	public function getSubIfd(): ?TIFFIfd
	{
		return $this->_subIfd;
	}

	/**
	 * Sets (or clears, when null) the parsed child IFD.
	 * @param ?TIFFIfd $value The child IFD, or null.
	 */
	public function setSubIfd(?TIFFIfd $value): void
	{
		$this->_subIfd = $value;
	}

	/**
	 * Returns the value-area offset the tag was read from.
	 * @return ?int The original offset, or null when inline or synthesized.
	 */
	public function getOffset(): ?int
	{
		return $this->_offset;
	}

	/**
	 * Sets the value-area offset the tag was read from.
	 * @param ?int $value The original offset, or null.
	 */
	public function setOffset(?int $value): void
	{
		$this->_offset = $value;
	}

	/**
	 * Indicates whether the writer must keep the value at its original offset.
	 * @return bool Whether the offset is preserved on compose.
	 */
	public function getPreserveOffset(): bool
	{
		return $this->_preserveOffset;
	}

	/**
	 * Sets whether the writer must keep the value at its original offset, so data with
	 * internal absolute pointers (a makernote) stays valid across a rewrite.
	 * @param bool $value Whether to preserve the offset.
	 */
	public function setPreserveOffset(bool $value): void
	{
		$this->_preserveOffset = $value;
	}

	/**
	 * Returns the captured external data blocks of an offsets tag: the strip or tile
	 * bytes each value element points at.  The writer re-emits the blocks and rewrites
	 * the offsets (and the paired byte-counts tag) accordingly.
	 * @return ?string[] The data blocks, or null when the tag carries none.
	 */
	public function getExternalData(): ?array
	{
		return $this->_externalData;
	}

	/**
	 * Sets (or clears, when null) the external data blocks of an offsets tag.
	 * @param ?string[] $blocks The data blocks, one per value element, or null.
	 */
	public function setExternalData(?array $blocks): void
	{
		$this->_externalData = $blocks;
	}
}
