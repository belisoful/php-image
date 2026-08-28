<?php

/**
 * PictureInfo class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\Meta;

/**
 * PictureInfo class.
 *
 * The legacy text camera metadata of a JPEG APP12 "Picture Info" segment, written by
 * early Olympus, Epson, Agfa, Sanyo, and HP cameras: a vendor header followed by
 * `Key=Value` text lines, terminated by an `[end]` marker on some models.
 * {@see parse()} recognizes the known vendor signatures; beyond the raw
 * {@see getText() text}, {@see getFields()} answers the parsed key-to-value map.
 * {@see toBinary()} reassembles the segment payload.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class PictureInfo
{
	use \Belisoful\Image\StreamIOTrait;

	/** @var string[] The known vendor header signatures. */
	public const Signatures = [
		"\x0a\x09\x09\x09\x09[picture info]",
		'[picture info]',
		"SEIKO EPSON CORP.  \x00",
		"Agfa Gevaert   \x00",
		"SanyoElectricDSC\x00",
		'OLYMPUS OPTICAL CO.,LTD.',
		'Type=',
	];

	/** @var string The vendor header the payload started with ('' for the HP form). */
	private string $_header = '';

	/** @var string The picture-info text. */
	private string $_text = '';

	/**
	 * Indicates whether an APP12 payload is picture-info text.
	 * @param string $payload The candidate payload.
	 * @return bool Whether a vendor signature (or the HP byte pattern) matches.
	 */
	public static function isPictureInfo(string $payload): bool
	{
		foreach (self::Signatures as $signature) {
			if (str_starts_with($payload, $signature)) {
				return true;
			}
		}
		// The HP form: a single vendor byte followed by three NUL bytes.
		return strlen($payload) > 4 && substr($payload, 1, 3) === "\x00\x00\x00";
	}

	/**
	 * Parses an APP12 picture-info payload.
	 * @param string $payload The segment payload.
	 * @return false|PictureInfo The parsed info, or false when not picture-info.
	 */
	public static function parse(string $payload): false|PictureInfo
	{
		if (!self::isPictureInfo($payload)) {
			return false;
		}
		$info = new self();
		foreach (self::Signatures as $signature) {
			if (str_starts_with($payload, $signature) && $signature !== 'Type=') {
				$info->_header = $signature;
				$payload = substr($payload, strlen($signature));
				break;
			}
		}
		if ($info->_header === '' && !str_starts_with($payload, 'Type=') && substr($payload, 1, 3) === "\x00\x00\x00") {
			$info->_header = substr($payload, 0, 4);
			$payload = substr($payload, 4);
		}
		$end = strpos($payload, '[end]');
		$info->_text = $end === false ? $payload : substr($payload, 0, $end + 5);
		return $info;
	}

	/**
	 * Parses an APP12 picture-info payload from a PSR-7 stream or stream resource,
	 * reading from the current position to the end.
	 * @param mixed $stream The {@see \Psr\Http\Message\StreamInterface} or PHP stream resource.
	 * @return false|PictureInfo The parsed info, or false when not picture-info.
	 */
	public static function fromStream(mixed $stream): false|PictureInfo
	{
		return static::parse(static::sourceBytes($stream));
	}

	/**
	 * Returns the vendor header.
	 * @return string The header bytes ('' when none).
	 */
	public function getHeader(): string
	{
		return $this->_header;
	}

	/**
	 * Sets the vendor header.
	 * @param string $value The header bytes.
	 */
	public function setHeader(string $value): void
	{
		$this->_header = $value;
	}

	/**
	 * Returns the picture-info text.
	 * @return string The text.
	 */
	public function getText(): string
	{
		return $this->_text;
	}

	/**
	 * Sets the picture-info text.
	 * @param string $value The text.
	 */
	public function setText(string $value): void
	{
		$this->_text = $value;
	}

	/**
	 * Parses the text's `Key=Value` lines into a map, skipping section markers.
	 * @return array<string, string> The fields.
	 */
	public function getFields(): array
	{
		$fields = [];
		foreach (preg_split('/\r\n|\r|\n/', $this->_text) as $line) {
			$line = trim($line);
			if ($line === '' || $line[0] === '[' || !str_contains($line, '=')) {
				continue;
			}
			[$key, $value] = explode('=', $line, 2);
			$fields[trim($key)] = trim($value);
		}
		return $fields;
	}

	/**
	 * Reassembles the APP12 payload.
	 * @return string The header and text.
	 */
	public function toBinary(): string
	{
		return $this->_header . $this->_text;
	}
}
