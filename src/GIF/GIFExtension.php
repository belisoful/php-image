<?php

/**
 * GIFExtension class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\GIF;

/**
 * GIFExtension class.
 *
 * One GIF89a extension block: the `0x21` introducer, a label naming the extension, and
 * a chain of data sub-blocks terminated by a zero-length block.
 *
 * The sub-block *framing* is kept as read, not just the bytes it carries, so a block a
 * writer chose to split at unusual boundaries is re-emitted exactly as it arrived.
 * {@see getData()} joins the sub-blocks for reading and {@see setData()} re-splits at
 * the 255-byte maximum for writing.
 *
 * An application extension's first sub-block is its 11-byte identity — an 8-byte
 * identifier and a 3-byte authentication code — exposed verbatim through
 * {@see getApplicationIdentifier()}.  The case is preserved exactly, which matters
 * because the identities that carry metadata (`XMP DataXMP`, `ICCRGBG1012`) are
 * case-sensitive and are silently lowercased by some other GIF writers.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class GIFExtension
{
	/** @var int The maximum bytes one data sub-block holds. */
	public const MaxSubBlock = 255;

	/**
	 * @var string The 258-byte "magic trailer" that ends a raw application extension: a
	 *   1, then 255 counting down to 1, then two zeros.  A reader that walks sub-block
	 *   lengths through the raw payload lands somewhere in the descending run, and every
	 *   landing point leads to the final zero — which is why the payload can be written
	 *   without sub-block framing and still be skippable.
	 */
	public const MagicTrailer = "\x01\xFF\xFE\xFD\xFC\xFB\xFA\xF9\xF8\xF7\xF6\xF5\xF4\xF3\xF2\xF1"
		. "\xF0\xEF\xEE\xED\xEC\xEB\xEA\xE9\xE8\xE7\xE6\xE5\xE4\xE3\xE2\xE1"
		. "\xE0\xDF\xDE\xDD\xDC\xDB\xDA\xD9\xD8\xD7\xD6\xD5\xD4\xD3\xD2\xD1"
		. "\xD0\xCF\xCE\xCD\xCC\xCB\xCA\xC9\xC8\xC7\xC6\xC5\xC4\xC3\xC2\xC1"
		. "\xC0\xBF\xBE\xBD\xBC\xBB\xBA\xB9\xB8\xB7\xB6\xB5\xB4\xB3\xB2\xB1"
		. "\xB0\xAF\xAE\xAD\xAC\xAB\xAA\xA9\xA8\xA7\xA6\xA5\xA4\xA3\xA2\xA1"
		. "\xA0\x9F\x9E\x9D\x9C\x9B\x9A\x99\x98\x97\x96\x95\x94\x93\x92\x91"
		. "\x90\x8F\x8E\x8D\x8C\x8B\x8A\x89\x88\x87\x86\x85\x84\x83\x82\x81"
		. "\x80\x7F\x7E\x7D\x7C\x7B\x7A\x79\x78\x77\x76\x75\x74\x73\x72\x71"
		. "\x70\x6F\x6E\x6D\x6C\x6B\x6A\x69\x68\x67\x66\x65\x64\x63\x62\x61"
		. "\x60\x5F\x5E\x5D\x5C\x5B\x5A\x59\x58\x57\x56\x55\x54\x53\x52\x51"
		. "\x50\x4F\x4E\x4D\x4C\x4B\x4A\x49\x48\x47\x46\x45\x44\x43\x42\x41"
		. "\x40\x3F\x3E\x3D\x3C\x3B\x3A\x39\x38\x37\x36\x35\x34\x33\x32\x31"
		. "\x30\x2F\x2E\x2D\x2C\x2B\x2A\x29\x28\x27\x26\x25\x24\x23\x22\x21"
		. "\x20\x1F\x1E\x1D\x1C\x1B\x1A\x19\x18\x17\x16\x15\x14\x13\x12\x11"
		. "\x10\x0F\x0E\x0D\x0C\x0B\x0A\x09\x08\x07\x06\x05\x04\x03\x02\x01"
		. "\x00\x00";

	/** @var int The extension label. */
	private int $_label;

	/** @var array<int, string> The data sub-blocks, in the framing they were read with. */
	private array $_subBlocks = [];

	/** @var bool Whether the payload after the identity is written without sub-block framing. */
	private bool $_isRaw = false;

	/**
	 * @param int $label The extension label. Default {@see GIFBlockType::CommentLabel}.
	 * @param array<int, string> $subBlocks The data sub-blocks. Default none.
	 */
	final public function __construct(int $label = GIFBlockType::CommentLabel, array $subBlocks = [])
	{
		$this->_label = $label & 0xFF;
		$this->_subBlocks = array_values($subBlocks);
	}

	/**
	 * Returns the extension label.
	 * @return int The label (e.g. {@see GIFBlockType::CommentLabel}).
	 */
	public function getLabel(): int
	{
		return $this->_label;
	}

	/**
	 * Sets the extension label.
	 * @param int $value The label.
	 */
	public function setLabel(int $value): void
	{
		$this->_label = $value & 0xFF;
	}

	/**
	 * Returns the data sub-blocks in their original framing.
	 * @return array<int, string> The sub-blocks.
	 */
	public function getSubBlocks(): array
	{
		return $this->_subBlocks;
	}

	/**
	 * Replaces the data sub-blocks, keeping the given framing.
	 * @param array<int, string> $value The sub-blocks, each at most 255 bytes.
	 */
	public function setSubBlocks(array $value): void
	{
		$this->_subBlocks = array_values($value);
	}

	/**
	 * Returns the sub-block payloads joined.
	 * @return string The extension data.
	 */
	public function getData(): string
	{
		return implode('', $this->_subBlocks);
	}

	/**
	 * Replaces the data, re-split into maximum-size sub-blocks.
	 * @param string $value The extension data.
	 */
	public function setData(string $value): void
	{
		$this->_subBlocks = $value === '' ? [] : str_split($value, self::MaxSubBlock);
	}

	/**
	 * Indicates whether this is an application extension.
	 * @return bool Whether the label is {@see GIFBlockType::ApplicationLabel}.
	 */
	public function getIsApplication(): bool
	{
		return $this->_label === GIFBlockType::ApplicationLabel;
	}

	/**
	 * Returns an application extension's 11-byte identity (8-byte identifier plus
	 * 3-byte authentication code), exactly as written.
	 * @return ?string The identity, or null when this is not an application extension
	 *   carrying one.
	 */
	public function getApplicationIdentifier(): ?string
	{
		if (!$this->getIsApplication() || !isset($this->_subBlocks[0]) || strlen($this->_subBlocks[0]) < 11) {
			return null;
		}
		return substr($this->_subBlocks[0], 0, 11);
	}

	/**
	 * Returns an application extension's data: every sub-block after the identity.
	 * @return string The application data.
	 */
	public function getApplicationData(): string
	{
		return $this->getIsApplication() ? implode('', array_slice($this->_subBlocks, 1)) : '';
	}

	/**
	 * Builds an application extension.
	 * @param string $identifier The 11-byte identity, or the 8-byte identifier alone.
	 * @param string $data The application data.
	 * @return static The extension.
	 */
	public static function application(string $identifier, string $data): static
	{
		$identity = str_pad(substr($identifier, 0, 11), 11, ' ');
		$blocks = [$identity];
		if ($data !== '') {
			$blocks = array_merge($blocks, str_split($data, self::MaxSubBlock));
		}
		return new static(GIFBlockType::ApplicationLabel, $blocks);
	}

	/**
	 * Builds a comment extension.
	 * @param string $text The comment text.
	 * @return static The extension.
	 */
	public static function comment(string $text): static
	{
		$extension = new static(GIFBlockType::CommentLabel);
		$extension->setData($text);
		return $extension;
	}

	/**
	 * Indicates whether the payload after the identity is written verbatim rather than in
	 * data sub-blocks, ended by the {@see MagicTrailer}.  The XMP packet of the
	 * `XMP DataXMP` extension is stored this way.
	 * @return bool Whether the payload is raw.
	 */
	public function getIsRaw(): bool
	{
		return $this->_isRaw;
	}

	/**
	 * Sets whether the payload after the identity is written verbatim.
	 * @param bool $value Whether the payload is raw.
	 */
	public function setIsRaw(bool $value): void
	{
		$this->_isRaw = $value;
	}

	/**
	 * Builds a raw application extension, whose payload is written without sub-block
	 * framing and ended by the {@see MagicTrailer}.
	 * @param string $identifier The 11-byte identity.
	 * @param string $data The payload.
	 * @return static The extension.
	 */
	public static function rawApplication(string $identifier, string $data): static
	{
		$extension = new static(GIFBlockType::ApplicationLabel, [str_pad(substr($identifier, 0, 11), 11, ' '), $data]);
		$extension->setIsRaw(true);
		return $extension;
	}

	/**
	 * Packs the extension: the introducer, the label, the sub-blocks, and the
	 * zero-length terminator.  A {@see getIsRaw() raw} extension instead writes its
	 * identity as one sub-block and then the payload verbatim, ended by the
	 * {@see MagicTrailer} whose final zero is the terminator.
	 * @return string The extension bytes.
	 */
	public function toBinary(): string
	{
		$out = chr(GIFBlockType::ExtensionIntroducer) . chr($this->_label);
		if ($this->_isRaw) {
			$identity = $this->_subBlocks[0] ?? '';
			return $out . chr(strlen($identity)) . $identity
				. implode('', array_slice($this->_subBlocks, 1)) . self::MagicTrailer;
		}
		foreach ($this->_subBlocks as $block) {
			foreach (str_split($block, self::MaxSubBlock) as $piece) {
				$out .= chr(strlen($piece)) . $piece;
			}
		}
		return $out . "\x00";
	}
}
