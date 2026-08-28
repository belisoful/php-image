<?php

/**
 * JUMBFDescription class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\Meta\JUMBF;

/**
 * JUMBFDescription class.
 *
 * The content of a JUMBF description box (`jumd`), the first child of every JUMBF
 * superbox: the 16-byte {@see getUuid() type UUID} naming what the superbox carries,
 * a {@see getToggles() toggles} byte, and the optional {@see getLabel() label},
 * {@see getId() id}, and {@see getSignature() signature} the toggles enable.
 *
 * The content-type UUIDs of the box types that carry data follow one reserved pattern
 * — the four ASCII type bytes then `00 11 00 10 80 00 00 AA 00 38 9B 71` — so
 * {@see typeUuid()} builds any of them, and the constants name the ones this library
 * writes: {@see ExifUuid} (the Exif annotation box of CIPA DC-008), {@see XmlUuid},
 * {@see JsonUuid}, and {@see CborUuid}.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @see https://www.iso.org/standard/73604.html ISO/IEC 19566-5 (JUMBF)
 */
class JUMBFDescription
{
	/** The toggle bit marking the content requestable from outside. */
	public const RequestableToggle = 0x01;

	/** The toggle bit marking a label present. */
	public const LabelToggle = 0x02;

	/** The toggle bit marking a four-byte id present. */
	public const IdToggle = 0x04;

	/** The toggle bit marking a 32-byte signature present. */
	public const SignatureToggle = 0x08;

	/** The trailing twelve bytes shared by the reserved content-type UUIDs. */
	public const UuidSuffix = "\x00\x11\x00\x10\x80\x00\x00\xAA\x00\x38\x9B\x71";

	/** The Exif annotation content-type UUID (CIPA DC-008). */
	public const ExifUuid = 'Exif' . self::UuidSuffix;

	/** The XML content-type UUID. */
	public const XmlUuid = 'xml ' . self::UuidSuffix;

	/** The JSON (and JSON-LD) content-type UUID. */
	public const JsonUuid = 'json' . self::UuidSuffix;

	/** The CBOR content-type UUID. */
	public const CborUuid = 'cbor' . self::UuidSuffix;

	/** @var string The 16-byte content-type UUID. */
	private string $_uuid;

	/** @var int The toggles byte. */
	private int $_toggles;

	/** @var ?string The label identifying the content, when present. */
	private ?string $_label;

	/** @var ?int The four-byte id, when present. */
	private ?int $_id;

	/** @var ?string The 32-byte signature, when present. */
	private ?string $_signature;

	/**
	 * Constructs a description.
	 * @param string $uuid The 16-byte content-type UUID.
	 * @param ?string $label The label, or null for none.
	 * @param ?int $toggles The toggles byte; null derives it from the other arguments
	 *   (requestable, plus label/id/signature when given).
	 * @param ?int $id The four-byte id, or null for none.
	 * @param ?string $signature The 32-byte signature, or null for none.
	 */
	final public function __construct(string $uuid, ?string $label = null, ?int $toggles = null, ?int $id = null, ?string $signature = null)
	{
		$this->_uuid = substr(str_pad($uuid, 16, "\0"), 0, 16);
		$this->_label = $label;
		$this->_id = $id;
		$this->_signature = $signature;
		$this->_toggles = $toggles ?? (self::RequestableToggle
			| ($label !== null ? self::LabelToggle : 0)
			| ($id !== null ? self::IdToggle : 0)
			| ($signature !== null ? self::SignatureToggle : 0));
	}

	/**
	 * Builds a reserved content-type UUID from a four-character box type.
	 * @param string $type The box type (e.g. 'xml ', 'json', 'Exif').
	 * @return string The 16-byte UUID.
	 */
	public static function typeUuid(string $type): string
	{
		return substr(str_pad($type, 4), 0, 4) . self::UuidSuffix;
	}

	/**
	 * Parses a description box payload.
	 * @param string $payload The `jumd` box payload.
	 * @return false|static The description, or false when the payload is too short.
	 */
	public static function parse(string $payload): false|static
	{
		if (strlen($payload) < 17) {
			return false;
		}
		$uuid = substr($payload, 0, 16);
		$toggles = ord($payload[16]);
		$pos = 17;
		$label = null;
		if ($toggles & self::LabelToggle) {
			$end = strpos($payload, "\0", $pos);
			$label = $end === false ? substr($payload, $pos) : substr($payload, $pos, $end - $pos);
			$pos = $end === false ? strlen($payload) : $end + 1;
		}
		$id = null;
		if (($toggles & self::IdToggle) && strlen($payload) >= $pos + 4) {
			$id = unpack('N', substr($payload, $pos, 4))[1];
			$pos += 4;
		}
		$signature = null;
		if (($toggles & self::SignatureToggle) && strlen($payload) >= $pos + 32) {
			$signature = substr($payload, $pos, 32);
		}
		return new static($uuid, $label, $toggles, $id, $signature);
	}

	/**
	 * Returns the 16-byte content-type UUID.
	 * @return string The UUID.
	 */
	public function getUuid(): string
	{
		return $this->_uuid;
	}

	/**
	 * Sets the 16-byte content-type UUID.
	 * @param string $value The UUID (padded or truncated to 16 bytes).
	 */
	public function setUuid(string $value): void
	{
		$this->_uuid = substr(str_pad($value, 16, "\0"), 0, 16);
	}

	/**
	 * Returns the four-character type the reserved UUID pattern encodes.
	 * @return ?string The box type (e.g. 'xml '), or null for a non-reserved UUID.
	 */
	public function getUuidType(): ?string
	{
		return substr($this->_uuid, 4) === self::UuidSuffix ? substr($this->_uuid, 0, 4) : null;
	}

	/**
	 * Returns the toggles byte.
	 * @return int The toggles.
	 */
	public function getToggles(): int
	{
		return $this->_toggles;
	}

	/**
	 * Sets the toggles byte.
	 * @param int $value The toggles.
	 */
	public function setToggles(int $value): void
	{
		$this->_toggles = $value & 0xFF;
	}

	/**
	 * Returns the content label.
	 * @return ?string The label, or null when absent.
	 */
	public function getLabel(): ?string
	{
		return $this->_label;
	}

	/**
	 * Sets (or removes, when null) the content label, updating the toggles.
	 * @param ?string $value The label, or null.
	 */
	public function setLabel(?string $value): void
	{
		$this->_label = $value;
		$this->_toggles = $value === null
			? $this->_toggles & ~self::LabelToggle
			: $this->_toggles | self::LabelToggle;
	}

	/**
	 * Returns the four-byte id.
	 * @return ?int The id, or null when absent.
	 */
	public function getId(): ?int
	{
		return $this->_id;
	}

	/**
	 * Sets (or removes, when null) the four-byte id, updating the toggles.
	 * @param ?int $value The id, or null.
	 */
	public function setId(?int $value): void
	{
		$this->_id = $value;
		$this->_toggles = $value === null
			? $this->_toggles & ~self::IdToggle
			: $this->_toggles | self::IdToggle;
	}

	/**
	 * Returns the 32-byte signature.
	 * @return ?string The signature, or null when absent.
	 */
	public function getSignature(): ?string
	{
		return $this->_signature;
	}

	/**
	 * Sets (or removes, when null) the 32-byte signature, updating the toggles.
	 * @param ?string $value The signature, or null.
	 */
	public function setSignature(?string $value): void
	{
		$this->_signature = $value;
		$this->_toggles = $value === null
			? $this->_toggles & ~self::SignatureToggle
			: $this->_toggles | self::SignatureToggle;
	}

	/**
	 * Packs the description back to a `jumd` box payload.
	 * @return string The payload bytes.
	 */
	public function toBinary(): string
	{
		$out = $this->_uuid . chr($this->_toggles);
		if (($this->_toggles & self::LabelToggle) && $this->_label !== null) {
			$out .= $this->_label . "\0";
		}
		if (($this->_toggles & self::IdToggle) && $this->_id !== null) {
			$out .= pack('N', $this->_id);
		}
		if (($this->_toggles & self::SignatureToggle) && $this->_signature !== null) {
			$out .= substr(str_pad($this->_signature, 32, "\0"), 0, 32);
		}
		return $out;
	}
}
