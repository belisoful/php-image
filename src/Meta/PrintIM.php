<?php

/**
 * PrintIM class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\Meta;

/**
 * PrintIM class.
 *
 * A Print Image Matching block (EXIF tag 50341, or a makernote's 0x0E00): the
 * `PrintIM` signature, the version string, and the tag entries — each a 16-bit tag
 * number with four data bytes.  The entry meanings are not publicly documented (every
 * PIM-aware reader reports them numerically), so entries are exposed as raw tag/data
 * pairs with {@see getEntryValue()} reading a datum as an unsigned 32-bit integer.
 *
 * {@see parse()} is byte-order aware and works around the extra NUL byte Panasonic
 * cameras write after the version; {@see toBinary()} re-packs the block.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class PrintIM
{
	use \Belisoful\Image\StreamIOTrait;

	/** The PrintIM block signature. */
	public const Signature = "PrintIM\x00";

	/** @var string The version string (e.g. '0300'). */
	private string $_version = '0300';

	/** @var array<int, array{tag: int, data: string}> The entries, in block order. */
	private array $_entries = [];

	/** @var bool Whether the block packs big-endian. */
	private bool $_bigEndian = true;

	/**
	 * Indicates whether data is a PrintIM block.
	 * @param string $data The candidate bytes.
	 * @return bool Whether the signature matches.
	 */
	public static function isPIM(string $data): bool
	{
		return str_starts_with($data, self::Signature);
	}

	/**
	 * Parses a PrintIM block.
	 * @param string $data The block bytes.
	 * @param bool $bigEndian The byte order of the surrounding EXIF block. Default true.
	 * @return false|PrintIM The parsed block, or false when the signature is absent.
	 */
	public static function parse(string $data, bool $bigEndian = true): false|PrintIM
	{
		if (!self::isPIM($data)) {
			return false;
		}
		$pim = new self();
		$pim->_bigEndian = $bigEndian;
		$len = strlen($data);
		$versionEnd = strpos($data, "\0", 8);
		if ($versionEnd === false) {
			return $pim;
		}
		$pim->_version = substr($data, 8, $versionEnd - 8);
		// Panasonic writes an extra NUL after the version: prefer the count position
		// whose entries fill the block exactly, then any position that fits.
		$countPos = null;
		$count = null;
		foreach ([0, 1] as $exact) {
			foreach ([$versionEnd + 1, $versionEnd + 2] as $candidate) {
				$read = $pim->readCount($data, $candidate);
				if ($read === null) {
					continue;
				}
				$end = $candidate + 2 + $read * 6;
				if (($exact === 0 && $end === $len) || ($exact === 1 && $end <= $len)) {
					$countPos = $candidate;
					$count = $read;
					break 2;
				}
			}
		}
		if ($countPos === null) {
			return $pim;
		}
		for ($i = 0; $i < $count; $i++) {
			$entry = $countPos + 2 + $i * 6;
			$pim->_entries[] = [
				'tag' => unpack($bigEndian ? 'n' : 'v', substr($data, $entry, 2))[1],
				'data' => substr($data, $entry + 2, 4),
			];
		}
		return $pim;
	}

	/**
	 * Parses a PrintIM block from a PSR-7 stream or stream resource, reading from the
	 * current position to the end.
	 * @param mixed $stream The {@see \Psr\Http\Message\StreamInterface} or PHP stream resource.
	 * @param bool $bigEndian The byte order of the surrounding EXIF block. Default true.
	 * @return false|PrintIM The parsed block, or false when the signature is absent.
	 */
	public static function fromStream(mixed $stream, bool $bigEndian = true): false|PrintIM
	{
		return static::parse(static::sourceBytes($stream), $bigEndian);
	}

	/**
	 * Reads a plausible entry count.
	 * @param string $data The block bytes.
	 * @param int $pos The count position.
	 * @return ?int The count, or null when unreadable.
	 */
	protected function readCount(string $data, int $pos): ?int
	{
		if ($pos + 2 > strlen($data)) {
			return null;
		}
		return unpack($this->_bigEndian ? 'n' : 'v', substr($data, $pos, 2))[1];
	}

	/**
	 * Returns the version string.
	 * @return string The version (e.g. '0300').
	 */
	public function getVersion(): string
	{
		return $this->_version;
	}

	/**
	 * Sets the version string.
	 * @param string $value The version.
	 */
	public function setVersion(string $value): void
	{
		$this->_version = $value;
	}

	/**
	 * Returns the entries in block order.
	 * @return array<int, array{tag: int, data: string}> The tag/data entries.
	 */
	public function getEntries(): array
	{
		return $this->_entries;
	}

	/**
	 * Returns the first entry's data for a tag number.
	 * @param int $tag The PIM tag number.
	 * @return ?string The four data bytes, or null when absent.
	 */
	public function getEntry(int $tag): ?string
	{
		foreach ($this->_entries as $entry) {
			if ($entry['tag'] === $tag) {
				return $entry['data'];
			}
		}
		return null;
	}

	/**
	 * Returns an entry's data as an unsigned 32-bit integer.
	 * @param int $tag The PIM tag number.
	 * @return ?int The value, or null when absent.
	 */
	public function getEntryValue(int $tag): ?int
	{
		$data = $this->getEntry($tag);
		return $data === null || strlen($data) < 4 ? null : unpack($this->_bigEndian ? 'N' : 'V', $data)[1];
	}

	/**
	 * Adds or replaces an entry.
	 * @param int $tag The PIM tag number.
	 * @param int|string $data The four data bytes, or an integer packed in block order.
	 */
	public function setEntry(int $tag, int|string $data): void
	{
		$bytes = is_int($data) ? pack($this->_bigEndian ? 'N' : 'V', $data) : str_pad(substr($data, 0, 4), 4, "\0");
		foreach ($this->_entries as $i => $entry) {
			if ($entry['tag'] === $tag) {
				$this->_entries[$i]['data'] = $bytes;
				return;
			}
		}
		$this->_entries[] = ['tag' => $tag, 'data' => $bytes];
	}

	/**
	 * Packs the block back to bytes.
	 * @return string The PrintIM block.
	 */
	public function toBinary(): string
	{
		$out = self::Signature . $this->_version . "\0" . pack($this->_bigEndian ? 'n' : 'v', count($this->_entries));
		foreach ($this->_entries as $entry) {
			$out .= pack($this->_bigEndian ? 'n' : 'v', $entry['tag']) . str_pad(substr($entry['data'], 0, 4), 4, "\0");
		}
		return $out;
	}
}
