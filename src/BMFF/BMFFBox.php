<?php

/**
 * BMFFBox class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\BMFF;

use Belisoful\Image\StreamIOTrait;

/**
 * BMFFBox class.
 *
 * One box of the ISO base media file format (ISO/IEC 14496-12), the box grammar that
 * QuickTime originated and that MP4, HEIF, AVIF, JPEG XL, and JUMBF are all written in: a
 * big-endian 32-bit size, a four-character {@see getType() type}, and a payload.  Two
 * escapes to the size field are part of the grammar and both are honoured here — a size of
 * `1` moves the real length into a 64-bit value after the type, and a size of `0` means the
 * box runs to the end of the data.
 *
 * A **container** box holds child boxes instead of opaque bytes.  Which types are containers
 * is format knowledge, not a property of the grammar, so a subclass names its own in
 * {@see ContainerTypes}: JUMBF has the single `jumb` superbox, while an ISO file has `moov`,
 * `trak`, and the rest.  The base class names none, so it reads a flat box sequence — which
 * is exactly what a JPEG XL container is.  A container that is also a **FullBox** carries
 * version and flags ahead of its children and must say so in {@see FullBoxContainers}, or
 * those four bytes are read as a box length and the whole walk desynchronizes.
 *
 * Two properties make a rewrite byte-faithful rather than merely valid:
 * - the **stored size form** is kept, so a box written with the 64-bit length or with the
 *   run-to-the-end length is written back the same way instead of being re-encoded;
 * - a container keeps its {@see getRemainder() remainder}, the trailing bytes that are not
 *   a whole box, and re-appends them.
 *
 * A `uuid` box carries a 16-byte user type ahead of its content; {@see getUserType()} and
 * {@see getUserPayload()} split it, and {@see uuidBox()} builds one.  The payload itself is
 * always stored whole, user type included, so composing it back cannot lose bytes.
 *
 * A subclass must keep the constructor signature: the walk builds boxes of the class it is
 * called on, so every format's box type is constructed the same way.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @phpstan-consistent-constructor
 * @see https://www.iso.org/standard/83102.html ISO/IEC 14496-12 (ISO base media file format)
 */
class BMFFBox
{
	use StreamIOTrait;

	/** The recursion depth honoured when parsing nested container boxes. */
	public const MaxDepth = 16;

	/** The box type that carries a 16-byte user type ahead of its content. */
	public const UuidBox = 'uuid';

	/** The length of a `uuid` box's user type. */
	public const UserTypeLength = 16;

	/**
	 * The box types whose payload is a child box sequence.  The base grammar declares none —
	 * a subclass names the containers its format defines.
	 */
	public const ContainerTypes = [];

	/**
	 * The container types that are **FullBoxes**, mapped to the number of bytes of version
	 * and flags that sit between the header and the first child.  ISO's `meta` is the one
	 * every file hits: parsing it as a plain container reads its version and flags as a box
	 * length and desynchronizes the whole walk.
	 */
	public const FullBoxContainers = [];

	/** @var string The four-character box type. */
	private string $_type;

	/** @var string The payload of a box that is not a container. */
	private string $_payload = '';

	/** @var array<int, BMFFBox> The child boxes of a container. */
	private array $_children = [];

	/** @var string A container's trailing bytes that are not a whole box, kept for a faithful rewrite. */
	private string $_remainder = '';

	/** @var string A FullBox container's version and flags, which precede its children. */
	private string $_fullBoxHeader = '';

	/**
	 * @var bool Whether this box holds its payload as bytes even though its type is a
	 * container: what the walk does at {@see MaxDepth}, and what {@see setPayload()} means.
	 */
	private bool $_isOpaque = false;

	/** @var bool Whether the stored size used the 64-bit form. */
	private bool $_usesLargeSize = false;

	/** @var bool Whether the stored size was zero, meaning the box runs to the end of the data. */
	private bool $_isSizeToEnd = false;

	/**
	 * Constructs a box.
	 * @param string $type The four-character type.
	 * @param string $payload The payload (ignored for a container with children).
	 * @param array<int, BMFFBox> $children The child boxes, for a container.
	 */
	public function __construct(string $type = '', string $payload = '', array $children = [])
	{
		$this->_type = substr(str_pad($type, 4), 0, 4);
		$this->_payload = $payload;
		$this->_children = $children;
	}

	/**
	 * Indicates whether a box type's payload is a child box sequence.
	 * @param string $type The four-character type.
	 * @return bool Whether the type is one of {@see ContainerTypes}.
	 */
	public static function isContainerType(string $type): bool
	{
		return in_array($type, static::ContainerTypes, true);
	}

	/**
	 * Returns how many bytes of version and flags precede a container type's children.
	 * @param string $type The four-character type.
	 * @return int The prefix length; zero for a container that is not a FullBox.
	 */
	public static function fullBoxHeaderLength(string $type): int
	{
		return static::FullBoxContainers[$type] ?? 0;
	}

	/**
	 * Walks a sequence of boxes, recursing into the container types this class declares.
	 * The walk is tolerant: a length that does not fit the data stops it rather than
	 * throwing, and whatever is left over is returned as the remainder so a caller can
	 * rewrite the sequence byte-faithfully.
	 * @param string $bytes The bytes holding the sequence.
	 * @param int $depth The nesting depth of the sequence.
	 * @return array{0: array<int, static>, 1: string} The boxes and the trailing remainder.
	 */
	public static function parseSequence(string $bytes, int $depth = 0): array
	{
		$boxes = [];
		$len = strlen($bytes);
		$pos = 0;
		while ($pos + 8 <= $len) {
			$boxLength = unpack('N', substr($bytes, $pos, 4))[1];
			$type = substr($bytes, $pos + 4, 4);
			$headerSize = 8;
			$usesLargeSize = false;
			$isSizeToEnd = false;
			if ($boxLength === 1) {
				if ($pos + 16 > $len) {
					break;   // no room for the 64-bit length
				}
				$high = unpack('N', substr($bytes, $pos + 8, 4))[1];
				$low = unpack('N', substr($bytes, $pos + 12, 4))[1];
				$boxLength = ($high << 32) | $low;
				$headerSize = 16;
				$usesLargeSize = true;
			} elseif ($boxLength === 0) {
				$boxLength = $len - $pos;   // to the end of the data
				$isSizeToEnd = true;
			}
			if ($boxLength < $headerSize || $pos + $boxLength > $len) {
				break;
			}
			$payload = substr($bytes, $pos + $headerSize, $boxLength - $headerSize);
			$box = new static($type);
			$box->_usesLargeSize = $usesLargeSize;
			$box->_isSizeToEnd = $isSizeToEnd;
			if (static::isContainerType($type) && $depth < static::MaxDepth) {
				$prefix = static::fullBoxHeaderLength($type);
				$box->_fullBoxHeader = substr($payload, 0, $prefix);
				[$box->_children, $box->_remainder] = static::parseSequence(substr($payload, $prefix), $depth + 1);
			} else {
				$box->_payload = $payload;
				$box->_isOpaque = true;   // including a container stopped by the depth cap
			}
			$boxes[] = $box;
			$pos += $boxLength;
		}
		return [$boxes, substr($bytes, $pos)];
	}

	/**
	 * Parses a sequence of boxes, discarding any trailing bytes that are not a whole box.
	 * @param string $bytes The box bytes.
	 * @param int $depth The current nesting depth.
	 * @return array<int, static> The parsed boxes (empty when nothing parses).
	 */
	public static function parseBoxes(string $bytes, int $depth = 0): array
	{
		[$boxes] = static::parseSequence($bytes, $depth);
		return $boxes;
	}

	/**
	 * Parses the first box of a byte string.
	 * @param string $bytes The box bytes.
	 * @return false|static The box, or false when nothing parses.
	 */
	public static function parse(string $bytes): false|static
	{
		$boxes = static::parseBoxes($bytes);
		return $boxes === [] ? false : $boxes[0];
	}

	/**
	 * Parses boxes from a PSR-7 stream or stream resource.
	 * @param mixed $stream The {@see \Psr\Http\Message\StreamInterface} or PHP stream resource.
	 * @return false|static The first box, or false when nothing parses.
	 */
	public static function fromStream(mixed $stream): false|static
	{
		return static::parse(static::sourceBytes($stream));
	}

	/**
	 * Builds a `uuid` box: the 16-byte user type, then the content.
	 * @param string $userType The user type; padded or truncated to 16 bytes.
	 * @param string $payload The content after the user type.
	 * @return static The box.
	 */
	public static function uuidBox(string $userType, string $payload = ''): static
	{
		return new static(self::UuidBox, substr(str_pad($userType, self::UserTypeLength, "\0"), 0, self::UserTypeLength) . $payload);
	}

	/**
	 * Returns the four-character box type.
	 * @return string The type.
	 */
	public function getType(): string
	{
		return $this->_type;
	}

	/**
	 * Sets the four-character box type.
	 * @param string $value The type.
	 */
	public function setType(string $value): void
	{
		$this->_type = substr(str_pad($value, 4), 0, 4);
	}

	/**
	 * Indicates whether this box holds child boxes rather than opaque bytes.  It is a
	 * container when its type says so **and** it is not holding a payload: a container type
	 * stopped by {@see MaxDepth}, or one given bytes through {@see setPayload()}, keeps
	 * those bytes and writes them back untouched.
	 * @return bool Whether this box holds children.
	 */
	public function getIsContainer(): bool
	{
		return !$this->_isOpaque && static::isContainerType($this->_type);
	}

	/**
	 * Returns the payload of a box that is not a container.
	 * @return string The payload bytes.
	 */
	public function getPayload(): string
	{
		return $this->_payload;
	}

	/**
	 * Sets the payload of a box that is not a container.
	 * @param string $value The payload bytes.
	 */
	public function setPayload(string $value): void
	{
		$this->_payload = $value;
		$this->_isOpaque = true;   // these bytes are the payload, whatever the type means
	}

	/**
	 * Returns the child boxes of a container.
	 * @return array<int, BMFFBox> The children.
	 */
	public function getChildren(): array
	{
		return $this->_children;
	}

	/**
	 * Sets the child boxes of a container.
	 * @param array<int, BMFFBox> $value The children.
	 */
	public function setChildren(array $value): void
	{
		$this->_children = array_values($value);
		$this->_isOpaque = false;
	}

	/**
	 * Appends a child box.
	 * @param BMFFBox $box The child.
	 */
	public function addChild(BMFFBox $box): void
	{
		$this->_children[] = $box;
		$this->_isOpaque = false;
	}

	/**
	 * Returns the first child box of a type.
	 * @param string $type The four-character type.
	 * @return ?BMFFBox The child, or null when absent.
	 */
	public function getChild(string $type): ?BMFFBox
	{
		foreach ($this->_children as $child) {
			if ($child->getType() === $type) {
				return $child;
			}
		}
		return null;
	}

	/**
	 * Returns a FullBox container's version and flags as stored.  A box built rather than
	 * parsed has none until it is composed, which pads them to the length its type needs.
	 * @return string The version and flags, empty for a container that is not a FullBox.
	 */
	public function getFullBoxHeader(): string
	{
		return $this->_fullBoxHeader;
	}

	/**
	 * Sets a FullBox container's version and flags.
	 * @param string $value The version and flags.
	 */
	public function setFullBoxHeader(string $value): void
	{
		$this->_fullBoxHeader = $value;
	}

	/**
	 * Returns a container's trailing bytes that could not be read as a whole box, which a
	 * rewrite re-appends so nothing is lost.
	 * @return string The remainder, empty when the payload parsed exactly.
	 */
	public function getRemainder(): string
	{
		return $this->_remainder;
	}

	/**
	 * Indicates whether the box is written with the 64-bit length form.
	 * @return bool Whether the extended length is used.
	 */
	public function getUsesLargeSize(): bool
	{
		return $this->_usesLargeSize;
	}

	/**
	 * Forces (or releases) the 64-bit length form.  A box too large for 32 bits uses it
	 * regardless.
	 * @param bool $value Whether to write the extended length.
	 */
	public function setUsesLargeSize(bool $value): void
	{
		$this->_usesLargeSize = $value;
	}

	/**
	 * Indicates whether the box is written with a zero size, meaning it runs to the end of
	 * the data — legal only for the last box of a file.
	 * @return bool Whether the run-to-the-end length is used.
	 */
	public function getIsSizeToEnd(): bool
	{
		return $this->_isSizeToEnd;
	}

	/**
	 * Writes (or stops writing) the box with a zero size, meaning it runs to the end of the
	 * data.  The caller is responsible for it being the last box.
	 * @param bool $value Whether to write the run-to-the-end length.
	 */
	public function setIsSizeToEnd(bool $value): void
	{
		$this->_isSizeToEnd = $value;
	}

	/**
	 * Returns the user type of a `uuid` box.
	 * @return ?string The 16-byte user type, or null when this is not a `uuid` box holding one.
	 */
	public function getUserType(): ?string
	{
		if ($this->_type !== self::UuidBox || strlen($this->_payload) < self::UserTypeLength) {
			return null;
		}
		return substr($this->_payload, 0, self::UserTypeLength);
	}

	/**
	 * Returns the content of a `uuid` box, after its user type.
	 * @return ?string The content, or null when this is not a `uuid` box holding a user type.
	 */
	public function getUserPayload(): ?string
	{
		return $this->getUserType() === null ? null : substr($this->_payload, self::UserTypeLength);
	}

	/**
	 * Packs the box (and any children) back to bytes, keeping the size form it was read
	 * with and moving to the 64-bit length when a box outgrows 32 bits.
	 * @return string The box bytes.
	 */
	public function toBinary(): string
	{
		$payload = $this->_payload;
		if ($this->getIsContainer()) {
			// A FullBox container always writes its version and flags, even one built from
			// nothing: without them a re-read takes the first four bytes of the first child
			// for the header and loses a box.
			$payload = str_pad($this->_fullBoxHeader, static::fullBoxHeaderLength($this->_type), "\0");
			foreach ($this->_children as $child) {
				$payload .= $child->toBinary();
			}
			$payload .= $this->_remainder;
		}
		if ($this->_isSizeToEnd) {
			return pack('N', 0) . $this->_type . $payload;
		}
		$length = 8 + strlen($payload);
		if ($this->_usesLargeSize || $length > 0xFFFFFFFF) {
			$length = 16 + strlen($payload);
			return pack('N', 1) . $this->_type . pack('NN', $length >> 32, $length & 0xFFFFFFFF) . $payload;
		}
		return pack('N', $length) . $this->_type . $payload;
	}
}
