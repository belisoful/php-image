<?php

/**
 * GIFImage class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image;

use Belisoful\Image\GIF\GIFExtension;
use Belisoful\Image\GIF\GIFBlockType;
use Belisoful\Image\GIF\GIFFrame;
use Belisoful\Image\Meta\IPTC;

/**
 * GIFImage class.
 *
 * Reads and writes a GIF image — both the 1987 `GIF87a` and the 1989 `GIF89a` versions
 * — at the block level: the Logical Screen Descriptor, the Global Color Table, then the
 * ordered stream of frames ({@see GIFFrame}) and extensions ({@see GIFExtension}) up
 * to the trailer.
 *
 * Frames are *authored*, not composited.  Each keeps the sub-rectangle, interlace flag,
 * local color table, delay, disposal method, and transparent index the file actually
 * stores, and the block order is preserved, so a parse/compose cycle reproduces the file
 * byte for byte — including the sub-block framing and the exact case of application
 * extension identities such as `XMP DataXMP` and `ICCRGBG1012`.
 *
 * ```php
 * $gif = GIFImage::fromFile('animation.gif');
 * $gif->getFrameCount();                       // 12
 * $gif->getLoopCount();                        // 0 = forever, null = play once
 * $frame = $gif->getFrame(0);
 * $frame->getDelayTime();                      // in hundredths of a second
 * $frame->setDelayTime(10);
 * $image = $gif->getFrameImage(0);             // a \GdImage or \Imagick
 * $gif->save('animation-out.gif');
 * ```
 *
 * The metadata carriers a GIF actually defines are read and written: the XMP packet of
 * the `XMP DataXMP` application extension (stored verbatim behind the magic trailer, not
 * in sub-blocks), the ICC profile of `ICCRGBG1012`, the comment extensions, and the
 * NETSCAPE2.0 loop count.  There is no IPTC carrier in the format, so {@see setIPTC()}
 * throws rather than silently dropping the records.
 *
 * The pixel data rides the GIF-flavor LZW codec ({@see \Belisoful\Image\Compression\GIFLZWCompressor}),
 * and raster conversion goes through {@see ImageGraphics} — including
 * {@see ImageGraphics::paletteQuantize()} when an arbitrary image is assigned to a frame.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class GIFImage extends ImageFile
{
	/** @var string The 1987 version signature. */
	public const Signature87a = 'GIF87a';

	/** @var string The 1989 version signature. */
	public const Signature89a = 'GIF89a';

	/** @var string The application identity carrying the animation loop count. */
	public const NetscapeIdentity = 'NETSCAPE2.0';

	/** @var string The application identity carrying an XMP packet. */
	public const XmpIdentity = 'XMP DataXMP';

	/** @var string The application identity carrying an ICC profile. */
	public const ICCIdentity = 'ICCRGBG1012';

	/** @var string The version signature. */
	private string $_version = self::Signature89a;

	/** @var int The color resolution bits of the screen descriptor's packed field. */
	private int $_colorResolution = 7;

	/** @var bool Whether the global color table is sorted by importance. */
	private bool $_globalSorted = false;

	/** @var ?string The global color table as RGB triplets, or null when absent. */
	private ?string $_globalColorTable = null;

	/** @var int The background color index. */
	private int $_backgroundIndex = 0;

	/** @var int The pixel aspect ratio field. */
	private int $_aspectRatio = 0;

	/** @var array<int, GIFExtension|GIFFrame> The block stream, in file order. */
	private array $_blocks = [];

	/** @var string Any bytes following the trailer, preserved verbatim. */
	private string $_trailingBytes = '';

	/**
	 * Returns the format name.
	 * @return string Always 'GIF'.
	 */
	public function getFormat(): string
	{
		return 'GIF';
	}

	/**
	 * Indicates whether data opens with a GIF signature.
	 * @param string $bytes The candidate bytes.
	 * @return bool Whether the bytes are a GIF87a or GIF89a file.
	 */
	public static function isGIF(string $bytes): bool
	{
		return str_starts_with($bytes, self::Signature87a) || str_starts_with($bytes, self::Signature89a);
	}

	//
	// ─── Header ──────────────────────────────────────────────────────────────
	//

	/** @return string The version signature ('GIF87a' or 'GIF89a'). */
	public function getVersion(): string
	{
		return $this->_version;
	}

	/**
	 * Sets the version signature.
	 * @param string $value 'GIF87a' or 'GIF89a'.
	 * @throws \InvalidArgumentException When the version is neither.
	 */
	public function setVersion(string $value): void
	{
		if ($value !== self::Signature87a && $value !== self::Signature89a) {
			throw new \InvalidArgumentException(sprintf('\'%s\' is not a GIF version signature; use GIF87a or GIF89a.', $value));
		}
		$this->_version = $value;
	}

	/** @return int The color resolution bits (0-7). */
	public function getColorResolution(): int
	{
		return $this->_colorResolution;
	}

	/** @param int $value The color resolution bits (0-7). */
	public function setColorResolution(int $value): void
	{
		$this->_colorResolution = $value & 0x07;
	}

	/** @return bool Whether the global color table is sorted by importance. */
	public function getGlobalSorted(): bool
	{
		return $this->_globalSorted;
	}

	/** @param bool $value Whether the global color table is sorted. */
	public function setGlobalSorted(bool $value): void
	{
		$this->_globalSorted = $value;
	}

	/**
	 * Returns the global color table.
	 * @return ?string The table as RGB triplets, or null when absent.
	 */
	public function getGlobalColorTable(): ?string
	{
		return $this->_globalColorTable;
	}

	/**
	 * Sets (or clears, when null) the global color table, padded to the next
	 * power-of-two entry count.
	 * @param ?string $value The table as RGB triplets, or null to drop it.
	 */
	public function setGlobalColorTable(?string $value): void
	{
		$this->_globalColorTable = $value === null ? null : GIFFrame::normalizeColorTable($value);
	}

	/** @return int The background color index. */
	public function getBackgroundIndex(): int
	{
		return $this->_backgroundIndex;
	}

	/** @param int $value The background color index (0-255). */
	public function setBackgroundIndex(int $value): void
	{
		$this->_backgroundIndex = $value & 0xFF;
	}

	/** @return int The pixel aspect ratio field. */
	public function getAspectRatio(): int
	{
		return $this->_aspectRatio;
	}

	/** @param int $value The pixel aspect ratio field (0-255). */
	public function setAspectRatio(int $value): void
	{
		$this->_aspectRatio = $value & 0xFF;
	}

	/**
	 * Sets the logical screen size, the canvas every frame is placed on.
	 * @param int $width The screen width in pixels.
	 * @param int $height The screen height in pixels.
	 */
	public function setScreenSize(int $width, int $height): void
	{
		$this->setWidthDirect($width & 0xFFFF);
		$this->setHeightDirect($height & 0xFFFF);
	}

	//
	// ─── Blocks, frames, and extensions ──────────────────────────────────────
	//

	/**
	 * Returns the whole block stream in file order.
	 * @return array<int, GIFExtension|GIFFrame> The frames and extensions.
	 */
	public function getBlocks(): array
	{
		return $this->_blocks;
	}

	/**
	 * Replaces the whole block stream.
	 * @param array<int, GIFExtension|GIFFrame> $value The frames and extensions, in order.
	 */
	public function setBlocks(array $value): void
	{
		$this->_blocks = array_values($value);
	}

	/**
	 * Returns the frames in file order.
	 * @return array<int, GIFFrame> The frames.
	 */
	public function getFrames(): array
	{
		return array_values(array_filter($this->_blocks, fn ($b) => $b instanceof GIFFrame));
	}

	/**
	 * Returns the frame count.
	 * @return int The number of frames.
	 */
	public function getFrameCount(): int
	{
		return count($this->getFrames());
	}

	/**
	 * Indicates whether the file holds more than one frame.
	 * @return bool Whether the GIF is animated.
	 */
	public function getIsAnimated(): bool
	{
		return $this->getFrameCount() > 1;
	}

	/**
	 * Returns one frame.
	 * @param int $index The zero-based frame index.
	 * @return ?GIFFrame The frame, or null when the index is out of range.
	 */
	public function getFrame(int $index): ?GIFFrame
	{
		return $this->getFrames()[$index] ?? null;
	}

	/**
	 * Appends a frame to the block stream.
	 * @param GIFFrame $frame The frame.
	 */
	public function addFrame(GIFFrame $frame): void
	{
		$this->_blocks[] = $frame;
	}

	/**
	 * Removes one frame.
	 * @param int $index The zero-based frame index.
	 * @return bool Whether a frame was removed.
	 */
	public function removeFrame(int $index): bool
	{
		$frame = $this->getFrame($index);
		if ($frame === null) {
			return false;
		}
		$at = array_search($frame, $this->_blocks, true);
		unset($this->_blocks[$at]);
		$this->_blocks = array_values($this->_blocks);
		return true;
	}

	/**
	 * Appends an extension to the block stream.
	 * @param GIFExtension $extension The extension.
	 */
	public function addExtension(GIFExtension $extension): void
	{
		$this->_blocks[] = $extension;
	}

	/**
	 * Returns the extensions in file order, optionally of one label.
	 * @param ?int $label The extension label to filter by, or null for all.
	 * @return array<int, GIFExtension> The extensions.
	 */
	public function getExtensions(?int $label = null): array
	{
		return array_values(array_filter(
			$this->_blocks,
			fn ($b) => $b instanceof GIFExtension && ($label === null || $b->getLabel() === $label)
		));
	}

	/**
	 * Returns the first application extension of an identity.
	 * @param string $identity The 11-byte identity (e.g. {@see XmpIdentity}), case-sensitive.
	 * @return ?GIFExtension The extension, or null when absent.
	 */
	public function getApplicationExtension(string $identity): ?GIFExtension
	{
		foreach ($this->getExtensions(GIFBlockType::ApplicationLabel) as $extension) {
			if ($extension->getApplicationIdentifier() === $identity) {
				return $extension;
			}
		}
		return null;
	}

	//
	// ─── Comments and the loop count ─────────────────────────────────────────
	//

	/**
	 * Returns every comment extension's text, in file order.
	 * @return array<int, string> The comments.
	 */
	public function getComments(): array
	{
		return array_map(
			fn (GIFExtension $e) => $e->getData(),
			$this->getExtensions(GIFBlockType::CommentLabel)
		);
	}

	/**
	 * Appends a comment extension.
	 * @param string $text The comment text.
	 */
	public function addComment(string $text): void
	{
		$this->_blocks[] = GIFExtension::comment($text);
	}

	/**
	 * Extends {@see clearPrivateData()} to the carrier only a GIF has: the comment
	 * extensions, free text that often names people and places
	 * ({@see PrivacyCategory::Description}).  The XMP and ICC application extensions are
	 * reached through the shared carriers; the loop count and graphic controls describe the
	 * animation, not a person, and stay.
	 * @param int $types The {@see PrivacyCategory} flags to remove.
	 * @return int The number of comment extensions removed.
	 */
	protected function clearFormatPrivateData(int $types): int
	{
		if (($types & PrivacyCategory::Description) === 0) {
			return 0;
		}
		$before = count($this->_blocks);
		$this->_blocks = array_values(array_filter(
			$this->_blocks,
			fn ($block): bool => !($block instanceof GIFExtension && $block->getLabel() === GIFBlockType::CommentLabel),
		));
		return $before - count($this->_blocks);
	}

	/**
	 * Returns the animation loop count from the NETSCAPE2.0 application extension.
	 * @return ?int The loop count (0 meaning forever), or null when the file plays once.
	 */
	public function getLoopCount(): ?int
	{
		$extension = $this->getApplicationExtension(self::NetscapeIdentity);
		if ($extension === null) {
			return null;
		}
		$data = $extension->getApplicationData();
		// The sub-block is a 1-byte id (1) then the little-endian loop count.
		return strlen($data) >= 3 ? unpack('v', substr($data, 1, 2))[1] : null;
	}

	/**
	 * Sets (or removes, when null) the animation loop count, written as the
	 * NETSCAPE2.0 application extension ahead of the first frame.
	 * @param ?int $value The loop count (0 meaning forever), or null to play once.
	 */
	public function setLoopCount(?int $value): void
	{
		$existing = $this->getApplicationExtension(self::NetscapeIdentity);
		if ($value === null) {
			if ($existing !== null) {
				$at = array_search($existing, $this->_blocks, true);
				unset($this->_blocks[$at]);
				$this->_blocks = array_values($this->_blocks);
			}
			return;
		}
		$data = "\x01" . pack('v', $value & 0xFFFF);
		if ($existing !== null) {
			$existing->setSubBlocks([self::NetscapeIdentity, $data]);
			return;
		}
		$extension = GIFExtension::application(self::NetscapeIdentity, $data);
		// The loop block belongs before the first frame.
		$at = count($this->_blocks);
		foreach ($this->_blocks as $index => $block) {
			if ($block instanceof GIFFrame) {
				$at = $index;
				break;
			}
		}
		array_splice($this->_blocks, $at, 0, [$extension]);
	}

	//
	// ─── Raster conversion ───────────────────────────────────────────────────
	//

	/**
	 * Returns the color table a frame renders against: its own when it has one,
	 * otherwise the file's global table.
	 * @param int $index The zero-based frame index.
	 * @return ?string The table as RGB triplets, or null when neither table exists.
	 */
	public function getFramePalette(int $index): ?string
	{
		$frame = $this->getFrame($index);
		return $frame?->getLocalColorTable() ?? $this->_globalColorTable;
	}

	/**
	 * Renders one frame.
	 * @param int $index The zero-based frame index.
	 * @param ?string $mode The graphics library name, or null for the default.
	 * @throws \InvalidArgumentException When the frame or a color table is missing.
	 * @return \GdImage|\Imagick The rendered frame.
	 */
	public function getFrameImage(int $index, ?string $mode = null): \GdImage|\Imagick
	{
		$frame = $this->getFrame($index);
		if ($frame === null) {
			throw new \InvalidArgumentException(sprintf('GIF frame \'%s\' is not present in the image.', $index));
		}
		$palette = $this->getFramePalette($index);
		if ($palette === null) {
			throw new \InvalidArgumentException(sprintf('GIF frame \'%s\' has neither a local color table nor a global one to render against.', $index));
		}
		return $frame->getImage($palette, $mode);
	}

	/**
	 * Returns the first frame, the still image a non-animated GIF holds.
	 * @param ?string $mode The graphics library name, or null for the default.
	 * @return \GdImage|\Imagick The rendered image.
	 */
	public function getImage(?string $mode = null): \GdImage|\Imagick
	{
		return $this->getFrameImage(0, $mode);
	}

	/**
	 * Replaces the image with a single quantized frame, keeping every extension — the
	 * comments, the loop count, the XMP packet, and the ICC profile — and the logical
	 * screen's own fields.
	 * @param \GdImage|\Imagick $image The source image.
	 */
	public function setImage(\GdImage|\Imagick $image): void
	{
		[$width, $height] = ImageGraphics::getSize($image);
		[$palette, $pixels] = ImageGraphics::paletteQuantize($image);

		$this->setScreenSize($width, $height);
		$this->setGlobalColorTable($palette);

		$frame = new GIFFrame();
		$frame->setWidth($width);
		$frame->setHeight($height);
		$frame->setMinCodeSize(GIFFrame::minCodeSizeFor($palette));
		$frame->setPixels($pixels);

		// The extensions stay, in order; every frame is replaced by the one new frame.
		$blocks = array_values(array_filter($this->_blocks, fn ($block): bool => $block instanceof GIFExtension));
		$blocks[] = $frame;
		$this->_blocks = $blocks;
		$this->setBytesDirect($this->compose());
	}

	//
	// ─── Metadata carriers ───────────────────────────────────────────────────
	//

	/**
	 * Returns the XMP packet text of the `XMP DataXMP` application extension.
	 * @return ?string The packet text, or null when absent.
	 */
	public function getXmpText(): ?string
	{
		$data = $this->getApplicationExtension(self::XmpIdentity)?->getApplicationData();
		return $data === '' ? null : $data;
	}

	/**
	 * Sets (or removes, when null) the XMP packet, written as the `XMP DataXMP`
	 * application extension: the packet verbatim, ended by the magic trailer that lets a
	 * reader which knows nothing of XMP skip the block.
	 * @param ?string $xmp The packet text, or null to drop the extension.
	 */
	public function setXmpText(?string $xmp): void
	{
		$this->replaceApplicationExtension(
			self::XmpIdentity,
			$xmp === null ? null : GIFExtension::rawApplication(self::XmpIdentity, $xmp),
		);
	}

	/**
	 * Returns the XMP packet parsed as a {@see \Belisoful\Image\Meta\XMP} DOM.
	 * @return ?\Belisoful\Image\Meta\XMP The XMP, or null when absent or unparsable.
	 */
	public function getXMP(): ?\Belisoful\Image\Meta\XMP
	{
		$text = $this->getXmpText();
		if ($text === null) {
			return null;
		}
		$xmp = \Belisoful\Image\Meta\XMP::parse($text);
		return $xmp === false ? null : $xmp;
	}

	/**
	 * Sets (or removes, when null) the XMP packet.
	 * @param ?\Belisoful\Image\Meta\XMP $xmp The XMP, or null to drop the extension.
	 */
	public function setXMP(?\Belisoful\Image\Meta\XMP $xmp): void
	{
		$this->setXmpText($xmp?->toPacketText());
	}

	/**
	 * Returns the ICC color profile of the `ICCRGBG1012` application extension.
	 * @return ?string The ICC profile bytes, or null when absent.
	 */
	public function getICCProfile(): ?string
	{
		$data = $this->getApplicationExtension(self::ICCIdentity)?->getApplicationData();
		return $data === '' ? null : $data;
	}

	/**
	 * Sets (or removes, when null) the ICC color profile, written as the `ICCRGBG1012`
	 * application extension in ordinary data sub-blocks.
	 * @param ?string $profile The ICC profile bytes, or null to drop the extension.
	 */
	public function setICCProfile(?string $profile): void
	{
		$this->replaceApplicationExtension(
			self::ICCIdentity,
			$profile === null ? null : GIFExtension::application(self::ICCIdentity, $profile),
		);
	}

	/**
	 * Returns the IPTC record set, which a GIF cannot carry.
	 * @return ?IPTC Always null.
	 */
	public function getIPTC(): ?IPTC
	{
		return null;
	}

	/**
	 * Refuses an IPTC record set: GIF89a defines the Comment, Plain Text, Graphic Control,
	 * and Application extensions, and no application identity carries IIM records.  Rather
	 * than accept data it would drop on {@see save()}, this throws — put the equivalent
	 * properties in {@see setXMP() XMP}, which a GIF does carry.
	 * @param ?IPTC $iptc The IPTC record set; only null is accepted.
	 * @throws \RuntimeException When an IPTC record set is given.
	 */
	public function setIPTC(?IPTC $iptc): void
	{
		if ($iptc !== null) {
			throw new \RuntimeException('A GIF has no IPTC carrier; put the equivalent properties in XMP.');
		}
	}

	/**
	 * Replaces (or removes, when null) the application extension of an identity, in place
	 * when one is already present so the block order is left alone, and otherwise before
	 * the first frame where a metadata extension belongs.
	 * @param string $identity The 11-byte application identity.
	 * @param ?GIFExtension $extension The replacement, or null to remove.
	 */
	protected function replaceApplicationExtension(string $identity, ?GIFExtension $extension): void
	{
		$existing = $this->getApplicationExtension($identity);
		if ($existing !== null) {
			$at = (int) array_search($existing, $this->_blocks, true);
			if ($extension === null) {
				unset($this->_blocks[$at]);
				$this->_blocks = array_values($this->_blocks);
			} else {
				$this->_blocks[$at] = $extension;
			}
			return;
		}
		if ($extension === null) {
			return;
		}
		foreach ($this->_blocks as $index => $block) {
			if ($block instanceof GIFFrame) {
				array_splice($this->_blocks, $index, 0, [$extension]);
				return;
			}
		}
		$this->_blocks[] = $extension;
	}

	/**
	 * Builds a single-frame GIF from an image, quantizing it to a global color table.
	 * @param \GdImage|\Imagick $image The source image.
	 * @return static The GIF.
	 */
	public static function fromImage(\GdImage|\Imagick $image): static
	{
		[$width, $height] = ImageGraphics::getSize($image);
		[$palette, $pixels] = ImageGraphics::paletteQuantize($image);

		$gif = static::fromString(static::blankBytes($width, $height, $palette));
		$frame = new GIFFrame();
		$frame->setWidth($width);
		$frame->setHeight($height);
		$frame->setMinCodeSize(GIFFrame::minCodeSizeFor($palette));
		$frame->setPixels($pixels);
		$gif->addFrame($frame);
		return $gif;
	}

	/**
	 * Builds the bytes of an empty GIF89a with a global color table and no frames.
	 * @param int $width The logical screen width.
	 * @param int $height The logical screen height.
	 * @param string $palette The global color table as RGB triplets.
	 * @return string The GIF bytes.
	 */
	protected static function blankBytes(int $width, int $height, string $palette): string
	{
		$table = GIFFrame::normalizeColorTable($palette);
		$packed = 0x80 | (7 << 4) | (GIFFrame::tableSizeBits($table) & 0x07);
		return self::Signature89a . pack('vv', $width, $height) . chr($packed) . "\x00\x00" . $table . chr(GIFBlockType::Trailer);
	}

	//
	// ─── Parsing ─────────────────────────────────────────────────────────────
	//

	/**
	 * Walks the GIF block stream.
	 * @throws \UnexpectedValueException When the bytes are not a well-formed GIF.
	 */
	protected function parse(): void
	{
		$bytes = $this->getBytesDirect();
		$len = strlen($bytes);
		if ($len < 13 || !static::isGIF($bytes)) {
			throw new \UnexpectedValueException(sprintf('GIF data is invalid: %s.', 'missing GIF87a or GIF89a signature'));
		}
		$this->_version = substr($bytes, 0, 6);
		$this->setWidthDirect(unpack('v', substr($bytes, 6, 2))[1]);
		$this->setHeightDirect(unpack('v', substr($bytes, 8, 2))[1]);
		$packed = ord($bytes[10]);
		$this->_colorResolution = ($packed >> 4) & 0x07;
		$this->_globalSorted = ($packed & 0x08) !== 0;
		$this->_backgroundIndex = ord($bytes[11]);
		$this->_aspectRatio = ord($bytes[12]);

		$i = 13;
		if ($packed & 0x80) {
			$size = 3 * (2 << ($packed & 0x07));
			if ($i + $size > $len) {
				throw new \UnexpectedValueException(sprintf('GIF data is invalid: %s.', 'the global color table runs past the end of the data'));
			}
			$this->_globalColorTable = substr($bytes, $i, $size);
			$i += $size;
		}

		$pending = null;
		while ($i < $len) {
			$marker = ord($bytes[$i]);
			if ($marker === GIFBlockType::Trailer) {
				$i++;
				break;
			}
			if ($marker === GIFBlockType::ExtensionIntroducer) {
				$i = $this->parseExtension($bytes, $i, $pending);
				continue;
			}
			if ($marker === GIFBlockType::ImageSeparator) {
				$i = $this->parseFrame($bytes, $i, $pending);
				continue;
			}
			throw new \UnexpectedValueException(sprintf('GIF data is invalid: %s.', sprintf("unexpected block marker 0x%02X at offset %d", $marker, $i)));
		}
		$this->_trailingBytes = substr($bytes, $i);
	}

	/**
	 * Parses one extension block, holding a Graphic Control Extension back for the
	 * frame it introduces.
	 * @param string $bytes The GIF bytes.
	 * @param int $i The offset of the introducer.
	 * @param ?array $pending The held Graphic Control Extension fields, by reference.
	 * @throws \UnexpectedValueException When the block is truncated.
	 * @return int The offset just past the block.
	 */
	private function parseExtension(string $bytes, int $i, ?array &$pending): int
	{
		$len = strlen($bytes);
		if ($i + 2 > $len) {
			throw new \UnexpectedValueException(sprintf('GIF data is invalid: %s.', 'an extension block is truncated'));
		}
		$label = ord($bytes[$i + 1]);
		$i += 2;
		if ($label === GIFBlockType::ApplicationLabel && ($raw = $this->parseRawApplication($bytes, $i)) !== null) {
			$this->_blocks[] = $raw;
			return $i;
		}
		$subBlocks = $this->readSubBlocks($bytes, $i);
		if ($label === GIFBlockType::GraphicControlLabel) {
			$data = implode('', $subBlocks);
			if (strlen($data) < 4) {
				throw new \UnexpectedValueException(sprintf('GIF data is invalid: %s.', 'a graphic control extension is too short'));
			}
			$packed = ord($data[0]);
			$pending = [
				'reserved' => ($packed >> 5) & 0x07,
				'disposal' => ($packed >> 2) & 0x07,
				'userInput' => ($packed & 0x02) !== 0,
				'transparent' => ($packed & 0x01) !== 0 ? ord($data[3]) : null,
				'delay' => unpack('v', substr($data, 1, 2))[1],
			];
			return $i;
		}
		$this->_blocks[] = new GIFExtension($label, $subBlocks);
		return $i;
	}

	/**
	 * Parses one Image Descriptor and its pixel data.
	 * @param string $bytes The GIF bytes.
	 * @param int $i The offset of the separator.
	 * @param ?array $pending The held Graphic Control Extension fields, by reference;
	 *   the frame consumes them, so it is always null on return.
	 * @param-out null $pending
	 * @throws \UnexpectedValueException When the descriptor is truncated.
	 * @return int The offset just past the frame.
	 */
	private function parseFrame(string $bytes, int $i, ?array &$pending): int
	{
		$len = strlen($bytes);
		if ($i + 10 > $len) {
			throw new \UnexpectedValueException(sprintf('GIF data is invalid: %s.', 'an image descriptor is truncated'));
		}
		$frame = new GIFFrame();
		$d = unpack('vleft/vtop/vwidth/vheight', substr($bytes, $i + 1, 8));
		$frame->setLeft($d['left']);
		$frame->setTop($d['top']);
		$frame->setWidth($d['width']);
		$frame->setHeight($d['height']);
		$packed = ord($bytes[$i + 9]);
		$frame->setInterlaced(($packed & 0x40) !== 0);
		$frame->setSorted(($packed & 0x20) !== 0);
		$i += 10;
		if ($packed & 0x80) {
			$size = 3 * (2 << ($packed & 0x07));
			if ($i + $size > $len) {
				throw new \UnexpectedValueException(sprintf('GIF data is invalid: %s.', 'a local color table runs past the end of the data'));
			}
			$frame->setLocalColorTable(substr($bytes, $i, $size));
			$i += $size;
		}
		if ($i >= $len) {
			throw new \UnexpectedValueException(sprintf('GIF data is invalid: %s.', 'the image data is missing its minimum code size'));
		}
		$frame->setMinCodeSize(max(2, ord($bytes[$i])));
		$i++;
		$frame->setDataSubBlocks($this->readSubBlocks($bytes, $i));

		if ($pending !== null) {
			$frame->setHasGraphicControl(true);
			$frame->setGraphicControlReserved($pending['reserved']);
			$frame->setDisposalMethod($pending['disposal']);
			$frame->setUserInput($pending['userInput']);
			$frame->setDelayTime($pending['delay']);
			$frame->setTransparentIndex($pending['transparent']);
			$pending = null;
		}
		$this->_blocks[] = $frame;
		return $i;
	}

	/**
	 * Reads a chain of data sub-blocks, preserving the framing.
	 * @param string $bytes The GIF bytes.
	 * @param int $i The offset of the first length byte, advanced past the terminator.
	 * @return array<int, string> The sub-block payloads.
	 */
	/**
	 * Reads an application extension whose payload is stored verbatim and ended by the
	 * magic trailer instead of in data sub-blocks — how the XMP packet of the
	 * `XMP DataXMP` extension is written, since the trailer lets a reader that knows
	 * nothing of XMP walk past it.  Sub-block framing would otherwise swallow one byte of
	 * the packet for every 255 as a length.
	 *
	 * A writer that framed the payload normally is left to {@see readSubBlocks()}: this
	 * returns null unless the identity is one that uses the trailer and the trailer is
	 * really there.
	 * @param string $bytes The GIF bytes.
	 * @param int &$i The offset just past the label, advanced past the block when read.
	 * @return ?GIFExtension The extension, or null when this is not a raw one.
	 */
	private function parseRawApplication(string $bytes, int &$i): ?GIFExtension
	{
		if (ord($bytes[$i] ?? "\0") !== 11) {
			return null;
		}
		$identity = substr($bytes, $i + 1, 11);
		if ($identity !== self::XmpIdentity) {
			return null;
		}
		$start = $i + 12;
		$trailer = strpos($bytes, GIFExtension::MagicTrailer, $start);
		if ($trailer === false) {
			return null;
		}
		$extension = GIFExtension::rawApplication($identity, substr($bytes, $start, $trailer - $start));
		$i = $trailer + strlen(GIFExtension::MagicTrailer);
		return $extension;
	}

	private function readSubBlocks(string $bytes, int &$i): array
	{
		$len = strlen($bytes);
		$blocks = [];
		while ($i < $len) {
			$size = ord($bytes[$i]);
			if ($size === 0) {
				$i++;
				return $blocks;
			}
			if ($i + 1 + $size > $len) {
				throw new \UnexpectedValueException(sprintf('GIF data is invalid: %s.', 'a data sub-block runs past the end of the data'));
			}
			$blocks[] = substr($bytes, $i + 1, $size);
			$i += 1 + $size;
		}
		throw new \UnexpectedValueException(sprintf('GIF data is invalid: %s.', 'a data sub-block chain is unterminated'));
	}

	//
	// ─── Composition ─────────────────────────────────────────────────────────
	//

	/**
	 * Rebuilds the GIF from its header, color table, and block stream.
	 * @return string The composed GIF bytes.
	 */
	protected function compose(): string
	{
		$packed = ($this->_globalColorTable !== null ? 0x80 : 0)
			| (($this->_colorResolution & 0x07) << 4)
			| ($this->_globalSorted ? 0x08 : 0)
			| ($this->_globalColorTable !== null ? (GIFFrame::tableSizeBits($this->_globalColorTable) & 0x07) : 0);

		$out = $this->_version
			. pack('vv', $this->getWidthDirect() ?? 0, $this->getHeightDirect() ?? 0)
			. chr($packed) . chr($this->_backgroundIndex) . chr($this->_aspectRatio);
		if ($this->_globalColorTable !== null) {
			$out .= $this->_globalColorTable;
		}
		foreach ($this->_blocks as $block) {
			$out .= $block->toBinary();
		}
		return $out . chr(GIFBlockType::Trailer) . $this->_trailingBytes;
	}
}
