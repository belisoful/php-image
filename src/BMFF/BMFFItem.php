<?php

/**
 * BMFFItem class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\BMFF;

/**
 * BMFFItem class.
 *
 * One item of an ISO base media file's `meta` box — the unit HEIF and AVIF store a picture,
 * an Exif block, or an XMP packet in.  An item is described in two places at once, and this
 * class is the join of them: `iinf`/`infe` gives it an {@see getId() id}, a
 * {@see getType() type} (`Exif`, `mime`, `hvc1`, …), a {@see getName() name} and, for a
 * `mime` item, a {@see getContentType() content type}; `iloc` says *where the bytes are*,
 * which is nowhere near the description.
 *
 * The location is what makes items awkward.  With `construction_method` 0 the extent offset
 * is an **absolute file offset**, usually into `mdat`; with 1 it is an offset within the
 * `idat` box.  This class records the extent as read, and — the part that matters for
 * writing — the byte positions of the offset and length fields inside `iloc`, so those two
 * numbers can be patched in place without `iloc` changing size and without anything in the
 * file moving.
 *
 * Only single-extent items are modelled: a split item reads its first extent and reports
 * {@see getIsWritable()} false, because rewriting one would mean re-laying-out the rest.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class BMFFItem
{
	/** The item type of an Exif block: a four-byte TIFF-header offset, then the TIFF. */
	public const ExifType = 'Exif';

	/** The item type of a MIME-typed item, whose content type says what it holds. */
	public const MimeType = 'mime';

	/** The content type a `mime` item carrying an XMP packet declares. */
	public const XmpContentType = 'application/rdf+xml';

	/** The extent offset is an absolute file offset. */
	public const FileConstruction = 0;

	/** The extent offset is relative to the start of the `idat` box's payload. */
	public const IdatConstruction = 1;

	/** @var int The item id. */
	private int $_id;

	/** @var string The four-character item type. */
	private string $_type;

	/** @var string The item name. */
	private string $_name = '';

	/** @var string The declared content type of a `mime` item. */
	private string $_contentType = '';

	/** @var int How the extent offset is to be read; see {@see FileConstruction}. */
	private int $_constructionMethod = self::FileConstruction;

	/**
	 * @var int The item's base offset, which every extent offset is measured from.  Real
	 * writers differ: libheif puts the whole address here and leaves the extent offset zero,
	 * while others leave the base zero — so a rewrite must work relative to it either way.
	 */
	private int $_baseOffset = 0;

	/** @var int The resolved extent offset: the base offset plus the stored extent offset. */
	private int $_offset = 0;

	/** @var int The extent length. */
	private int $_length = 0;

	/** @var int The byte position of the base offset field within the `iloc` payload. */
	private int $_baseField = -1;

	/** @var int The width in bytes of the base offset field; zero when the table has none. */
	private int $_baseWidth = 0;

	/** @var int The byte position of the extent offset field within the `iloc` payload. */
	private int $_offsetField = -1;

	/** @var int The width in bytes of the extent offset field. */
	private int $_offsetWidth = 0;

	/** @var int The byte position of the extent length field within the `iloc` payload. */
	private int $_lengthField = -1;

	/** @var int The width in bytes of the extent length field. */
	private int $_lengthWidth = 0;

	/** @var bool Whether the item has exactly one extent, so it can be rewritten in place. */
	private bool $_isSingleExtent = false;

	/**
	 * @param int $id The item id.
	 * @param string $type The four-character item type.
	 */
	public function __construct(int $id, string $type)
	{
		$this->_id = $id;
		$this->_type = $type;
	}

	/**
	 * Returns the item id, the number `iloc` and `ipma` refer to it by.
	 * @return int The item id.
	 */
	public function getId(): int
	{
		return $this->_id;
	}

	/**
	 * Returns the four-character item type.
	 * @return string The item type (e.g. `Exif`, `mime`, `hvc1`).
	 */
	public function getType(): string
	{
		return $this->_type;
	}

	/**
	 * Returns the item name from its `infe` entry.
	 * @return string The name, empty when unnamed.
	 */
	public function getName(): string
	{
		return $this->_name;
	}

	/**
	 * Sets the item name.
	 * @param string $value The name.
	 */
	public function setName(string $value): void
	{
		$this->_name = $value;
	}

	/**
	 * Returns the content type a `mime` item declares, which is how an XMP item is told
	 * apart from any other MIME payload.
	 * @return string The content type, empty for an item that is not a `mime` item.
	 */
	public function getContentType(): string
	{
		return $this->_contentType;
	}

	/**
	 * Sets the declared content type of a `mime` item.
	 * @param string $value The content type.
	 */
	public function setContentType(string $value): void
	{
		$this->_contentType = $value;
	}

	/**
	 * Indicates whether this is the XMP item: a `mime` item declaring the RDF/XML content type.
	 * @return bool Whether the item carries XMP.
	 */
	public function getIsXmp(): bool
	{
		return $this->_type === self::MimeType && $this->_contentType === self::XmpContentType;
	}

	/**
	 * Returns how the extent offset is to be read.
	 * @return int The construction method; see {@see FileConstruction}.
	 */
	public function getConstructionMethod(): int
	{
		return $this->_constructionMethod;
	}

	/**
	 * Returns the item's base offset, which the stored extent offset is measured from.
	 * @return int The base offset.
	 */
	public function getBaseOffset(): int
	{
		return $this->_baseOffset;
	}

	/**
	 * Returns the resolved extent offset, which is an absolute file offset under
	 * {@see FileConstruction} and an offset into `idat` under {@see IdatConstruction}.
	 * @return int The extent offset.
	 */
	public function getOffset(): int
	{
		return $this->_offset;
	}

	/**
	 * Returns the extent length in bytes.
	 * @return int The extent length.
	 */
	public function getLength(): int
	{
		return $this->_length;
	}

	/**
	 * Records where the item's bytes are and where `iloc` says so, so the two numbers can
	 * later be patched in place.
	 * @param int $constructionMethod How the offset is to be read.
	 * @param int $baseOffset The item's base offset, which the extent offset is measured from.
	 * @param int $baseField The byte position of the base offset field within the `iloc` payload.
	 * @param int $baseWidth The width in bytes of the base offset field; zero when there is none.
	 * @param int $offset The resolved extent offset (the base plus the stored offset).
	 * @param int $length The extent length.
	 * @param int $offsetField The byte position of the offset field within the `iloc` payload.
	 * @param int $offsetWidth The width in bytes of the offset field.
	 * @param int $lengthField The byte position of the length field within the `iloc` payload.
	 * @param int $lengthWidth The width in bytes of the length field.
	 * @param bool $isSingleExtent Whether the item has exactly one extent.
	 */
	public function setLocation(
		int $constructionMethod,
		int $baseOffset,
		int $baseField,
		int $baseWidth,
		int $offset,
		int $length,
		int $offsetField,
		int $offsetWidth,
		int $lengthField,
		int $lengthWidth,
		bool $isSingleExtent,
	): void {
		$this->_constructionMethod = $constructionMethod;
		$this->_baseOffset = $baseOffset;
		$this->_baseField = $baseField;
		$this->_baseWidth = $baseWidth;
		$this->_offset = $offset;
		$this->_length = $length;
		$this->_offsetField = $offsetField;
		$this->_offsetWidth = $offsetWidth;
		$this->_lengthField = $lengthField;
		$this->_lengthWidth = $lengthWidth;
		$this->_isSingleExtent = $isSingleExtent;
	}

	/**
	 * Indicates whether the item's bytes can be replaced by patching `iloc` in place: it
	 * must have one extent, located by an absolute file offset, whose fields are wide enough
	 * to be worth writing into.
	 * @return bool Whether the item is writable in place.
	 */
	public function getIsWritable(): bool
	{
		return $this->_isSingleExtent
			&& $this->_constructionMethod === self::FileConstruction
			&& $this->_offsetField >= 0
			&& $this->_offsetWidth > 0
			&& $this->_lengthWidth > 0;
	}

	/**
	 * Indicates whether an offset and a length still fit the fields `iloc` stored them in.
	 * Rewriting must not widen a field, because that would resize `iloc` and move the file.
	 * @param int $offset The new extent offset.
	 * @param int $length The new extent length.
	 * @return bool Whether both fit.
	 */
	public function fits(int $offset, int $length): bool
	{
		return $offset >= $this->_baseOffset
			&& $this->fitsField($offset - $this->_baseOffset, $this->_offsetWidth)
			&& $this->fitsField($length, $this->_lengthWidth);
	}

	/**
	 * Rewrites the item's extent offset and length inside an `iloc` payload, leaving every
	 * other byte — and so the payload's length — untouched.
	 * The value written is measured from the item's base offset, so a file whose writer put
	 * the address in the base and left the extent zero is rewritten correctly.
	 * @param string $iloc The `iloc` payload.
	 * @param int $offset The new absolute extent offset.
	 * @param int $length The new extent length.
	 * @return string The patched payload, the same length as the one given.
	 */
	public function writeLocation(string $iloc, int $offset, int $length): string
	{
		$stored = $offset - $this->_baseOffset;
		$iloc = substr_replace($iloc, $this->packField($stored, $this->_offsetWidth), $this->_offsetField, $this->_offsetWidth);
		$iloc = substr_replace($iloc, $this->packField($length, $this->_lengthWidth), $this->_lengthField, $this->_lengthWidth);
		$this->_offset = $offset;
		$this->_length = $length;
		return $iloc;
	}

	/**
	 * Indicates whether the item's bytes can be moved by a delta: the field that carries the
	 * address has to be able to hold the result.
	 * @param int $delta The number of bytes the data moves by.
	 * @return bool Whether the move can be recorded.
	 */
	public function canShift(int $delta): bool
	{
		if (!$this->getIsWritable()) {
			return false;
		}
		[$current, $width] = $this->addressField();
		return $this->fitsField($current + $delta, $width);
	}

	/**
	 * Moves the item's recorded location by a delta, adding it to whichever field carries the
	 * address.  Writers differ over that — libheif puts the whole address in the base offset
	 * and leaves the extent zero — so the base is adjusted when the table has one and the
	 * extent offset only when it does not.
	 * @param string $iloc The `iloc` payload.
	 * @param int $delta The number of bytes the data moves by.
	 * @return string The patched payload, the same length as the one given.
	 */
	public function shift(string $iloc, int $delta): string
	{
		[$current, $width] = $this->addressField();
		$position = $this->_baseWidth > 0 ? $this->_baseField : $this->_offsetField;
		$iloc = substr_replace($iloc, $this->packField($current + $delta, $width), $position, $width);
		if ($this->_baseWidth > 0) {
			$this->_baseOffset += $delta;
		}
		$this->_offset += $delta;
		return $iloc;
	}

	/**
	 * Returns the stored value and width of the field that carries the item's address.
	 * @return array{0: int, 1: int} The value and its field width.
	 */
	private function addressField(): array
	{
		return $this->_baseWidth > 0
			? [$this->_baseOffset, $this->_baseWidth]
			: [$this->_offset - $this->_baseOffset, $this->_offsetWidth];
	}

	/**
	 * Indicates whether a value fits a big-endian field of a width.
	 * @param int $value The value.
	 * @param int $width The field width in bytes.
	 * @return bool Whether it fits.
	 */
	private function fitsField(int $value, int $width): bool
	{
		return $value >= 0 && ($width >= 8 || $value < (1 << ($width * 8)));
	}

	/**
	 * Packs a value big-endian into a field of a width.
	 * @param int $value The value.
	 * @param int $width The field width in bytes.
	 * @return string The packed bytes.
	 */
	private function packField(int $value, int $width): string
	{
		$bytes = '';
		for ($i = $width - 1; $i >= 0; $i--) {
			$bytes .= chr(($value >> ($i * 8)) & 0xFF);
		}
		return $bytes;
	}
}
