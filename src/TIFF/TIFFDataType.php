<?php

/**
 * TIFFDataType class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\TIFF;

/**
 * TIFFDataType class.
 *
 * Enumerates the twelve TIFF 6.0 field data types — including the Float (11) and
 * Double (12) types — plus the EXIF 3.0 {@see Utf8} string type (129), and packs or
 * unpacks each of them in either byte order.  A value set is an array: integers for
 * the integer types, floats for Float/Double, and two-element
 * `[numerator, denominator]` arrays for the rational types; the Ascii, Undefined, and
 * Utf8 types carry a byte string instead.
 *
 * {@see getSize()} answers the per-element byte size, {@see pack()} encodes a value set,
 * and {@see unpack()} decodes one.  Signed types are converted through two's complement,
 * so the full negative range round-trips in both byte orders.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TIFFDataType
{
	public const UByte = 1;
	public const Ascii = 2;
	public const UShort = 3;
	public const ULong = 4;
	public const URational = 5;
	public const SByte = 6;
	public const Undefined = 7;
	public const SShort = 8;
	public const SLong = 9;
	public const SRational = 10;
	public const Float = 11;
	public const Double = 12;

	/** The EXIF 3.0 UTF-8 string type (a NUL-terminated byte string, like Ascii). */
	public const Utf8 = 129;

	/** @var array<int, int> The per-element byte size of each data type. */
	public const Sizes = [
		self::UByte => 1,
		self::Ascii => 1,
		self::UShort => 2,
		self::ULong => 4,
		self::URational => 8,
		self::SByte => 1,
		self::Undefined => 1,
		self::SShort => 2,
		self::SLong => 4,
		self::SRational => 8,
		self::Float => 4,
		self::Double => 8,
		self::Utf8 => 1,
	];

	/**
	 * Returns the per-element byte size of a data type.
	 * @param int $type The data type.
	 * @throws \InvalidArgumentException When the type is not a TIFF data type.
	 * @return int The element size in bytes.
	 */
	public static function getSize(int $type): int
	{
		if (!isset(self::Sizes[$type])) {
			throw new \InvalidArgumentException(sprintf('\'%s\' is not a TIFF data type (1-12).', $type));
		}
		return self::Sizes[$type];
	}

	/**
	 * Indicates whether a value is a TIFF data type.
	 * @param int $type The candidate type.
	 * @return bool Whether the type is a TIFF data type.
	 */
	public static function isValid(int $type): bool
	{
		return isset(self::Sizes[$type]);
	}

	/**
	 * Returns the element count of a value set: the byte length for Ascii and
	 * Undefined, otherwise the number of array elements.
	 * @param int $type The data type.
	 * @param array|string $values The value set.
	 * @return int The element count.
	 */
	public static function countOf(int $type, array|string $values): int
	{
		if ($type === self::Ascii || $type === self::Undefined || $type === self::Utf8) {
			return strlen((string) $values);
		}
		return count((array) $values);
	}

	/**
	 * Packs a value set into TIFF field bytes.
	 * @param int $type The data type.
	 * @param array|string $values The value set (a byte string for Ascii/Undefined).
	 * @param bool $bigEndian Whether to pack big-endian (MM) rather than little-endian (II).
	 * @return string The packed bytes.
	 */
	public static function pack(int $type, array|string $values, bool $bigEndian): string
	{
		static::getSize($type);
		if ($type === self::Ascii || $type === self::Undefined || $type === self::Utf8) {
			return (string) $values;
		}
		$data = '';
		foreach ((array) $values as $value) {
			$data .= match ($type) {
				self::UByte => chr($value & 0xFF),
				self::SByte => chr($value < 0 ? $value + 0x100 : $value),
				self::UShort => \pack($bigEndian ? 'n' : 'v', $value & 0xFFFF),
				self::SShort => \pack($bigEndian ? 'n' : 'v', ($value < 0 ? $value + 0x10000 : $value) & 0xFFFF),
				self::ULong => \pack($bigEndian ? 'N' : 'V', $value & 0xFFFFFFFF),
				self::SLong => \pack($bigEndian ? 'N' : 'V', ($value < 0 ? $value + 0x100000000 : $value) & 0xFFFFFFFF),
				self::URational => \pack($bigEndian ? 'N2' : 'V2', $value[0] & 0xFFFFFFFF, $value[1] & 0xFFFFFFFF),
				self::SRational => \pack(
					$bigEndian ? 'N2' : 'V2',
					($value[0] < 0 ? $value[0] + 0x100000000 : $value[0]) & 0xFFFFFFFF,
					($value[1] < 0 ? $value[1] + 0x100000000 : $value[1]) & 0xFFFFFFFF,
				),
				self::Float => \pack($bigEndian ? 'G' : 'g', $value),
				self::Double => \pack($bigEndian ? 'E' : 'e', $value),
			};
		}
		return $data;
	}

	/**
	 * Unpacks TIFF field bytes into a value set.
	 * @param int $type The data type.
	 * @param string $data The packed bytes.
	 * @param bool $bigEndian Whether the bytes are big-endian (MM) rather than little-endian (II).
	 * @return array|string The value set (a byte string for Ascii/Undefined).
	 */
	public static function unpack(int $type, string $data, bool $bigEndian): array|string
	{
		$size = static::getSize($type);
		if ($type === self::Ascii || $type === self::Undefined || $type === self::Utf8) {
			return $data;
		}
		$values = [];
		for ($i = 0, $len = strlen($data) - $size + 1; $i < $len; $i += $size) {
			$chunk = substr($data, $i, $size);
			$values[] = match ($type) {
				self::UByte => ord($chunk),
				self::SByte => ($v = ord($chunk)) > 0x7F ? $v - 0x100 : $v,
				self::UShort => \unpack($bigEndian ? 'n' : 'v', $chunk)[1],
				self::SShort => ($v = \unpack($bigEndian ? 'n' : 'v', $chunk)[1]) > 0x7FFF ? $v - 0x10000 : $v,
				self::ULong => \unpack($bigEndian ? 'N' : 'V', $chunk)[1],
				self::SLong => ($v = \unpack($bigEndian ? 'N' : 'V', $chunk)[1]) > 0x7FFFFFFF ? $v - 0x100000000 : $v,
				self::URational => array_values(\unpack($bigEndian ? 'N2' : 'V2', $chunk)),
				self::SRational => array_map(
					fn ($v) => $v > 0x7FFFFFFF ? $v - 0x100000000 : $v,
					array_values(\unpack($bigEndian ? 'N2' : 'V2', $chunk)),
				),
				self::Float => \unpack($bigEndian ? 'G' : 'g', $chunk)[1],
				self::Double => \unpack($bigEndian ? 'E' : 'e', $chunk)[1],
			};
		}
		return $values;
	}
}
