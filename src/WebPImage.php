<?php

/**
 * WebPImage class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image;

use Belisoful\Image\Meta\IPTC;

/**
 * WebPImage class.
 *
 * Reads a WebP image, which is a {@see RIFFContainer} container with the `WEBP` form type.  It
 * reports the pixel dimensions from whichever bitstream chunk is present:
 *
 * | Chunk  | Variant   | Dimension source                                       |
 * |--------|-----------|--------------------------------------------------------|
 * | VP8&nbsp;  | lossy     | 14-bit width and height after the 0x9d012a start code. |
 * | VP8L   | lossless  | 14-bit width-1 and height-1 packed after the 0x2f sig. |
 * | VP8X   | extended  | 24-bit canvas width-1 and height-1.                    |
 *
 * The container is read-write: the `ICCP`, `EXIF`, and `XMP ` chunks are read and written
 * through {@see getICCProfile()}/{@see setICCProfile()}, {@see getEXIF()}/{@see setEXIF()},
 * and {@see getXMP()}/{@see setXMP()}, each placed by {@see ChunkOrder} with its `VP8X`
 * feature flag kept in step (a simple file gains the `VP8X` header the metadata requires).
 * The bitstream itself round-trips through {@see getImage()}/{@see setImage()}, which carry
 * the metadata onto the re-encoded pixels.  WebP defines no IPTC carrier, so
 * {@see setIPTC()} throws rather than silently dropping the records.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class WebPImage extends ImageFile
{
	/** The VP8X flag bit marking an XMP chunk present. */
	public const Vp8xXmpFlag = 0x04;

	/** The VP8X flag bit marking an EXIF chunk present. */
	public const Vp8xExifFlag = 0x08;

	/** The VP8X flag bit marking an ICC profile chunk present. */
	public const Vp8xICCFlag = 0x20;

	/**
	 * @var string[] The chunk order the WebP container specification defines: the
	 *   extended header, then the color profile, then the animation and image data, then
	 *   the metadata.  A new chunk is placed by this ranking rather than appended.
	 */
	public const ChunkOrder = [RIFFChunkType::Vp8Extended, RIFFChunkType::ICCProfile, RIFFChunkType::Animation, RIFFChunkType::AnimationFrame, RIFFChunkType::Alpha, RIFFChunkType::Vp8, RIFFChunkType::Vp8Lossless, RIFFChunkType::Exif, RIFFChunkType::Xmp];

	/** @var array<string, int> The metadata chunk ids and the `VP8X` flag each one sets. */
	public const MetaChunkFlags = [
		RIFFChunkType::ICCProfile => self::Vp8xICCFlag,
		RIFFChunkType::Exif => self::Vp8xExifFlag,
		RIFFChunkType::Xmp => self::Vp8xXmpFlag,
	];

	/** @var ?RIFFContainer The parsed RIFF container backing the WebP. */
	private ?RIFFContainer $_riff = null;

	/**
	 * Returns the format name.
	 * @return string Always 'WebP'.
	 */
	public function getFormat(): string
	{
		return 'WebP';
	}

	/**
	 * Returns the RIFF container backing the WebP.
	 * @return ?RIFFContainer The RIFF container, or null before parsing.
	 */
	public function getRIFF(): ?RIFFContainer
	{
		return $this->_riff;
	}

	/**
	 * Returns the XMP packet text of the `XMP ` chunk.
	 * @return ?string The packet text, or null when absent.
	 */
	public function getXmpText(): ?string
	{
		return $this->_riff?->getChunk(RIFFChunkType::Xmp)?->getData();
	}

	/**
	 * Sets (or removes, when null) the XMP packet.  WebP carries metadata only in the
	 * extended format, so a simple file gains a `VP8X` header (with the canvas size and
	 * the XMP flag) as the specification requires.
	 * @param ?string $xmp The packet text, or null to drop the chunk.
	 */
	public function setXmpText(?string $xmp): void
	{
		$this->setMetaChunk(RIFFChunkType::Xmp, self::Vp8xXmpFlag, $xmp);
	}

	/**
	 * Stores (or removes, when null) a metadata chunk and keeps the matching `VP8X`
	 * feature flag in step.  WebP carries metadata only in the extended format, so a
	 * simple file gains a `VP8X` header with its canvas size as the specification
	 * requires, and the chunk is placed by {@see ChunkOrder} rather than appended.
	 * @param string $id The four-character chunk id.
	 * @param int $flag The `VP8X` feature flag bit for that chunk.
	 * @param ?string $data The chunk payload, or null to remove the chunk.
	 */
	protected function setMetaChunk(string $id, int $flag, ?string $data): void
	{
		if ($this->_riff === null) {
			return;
		}
		if ($data === null) {
			$this->_riff->removeChunk($id);
			$this->setVp8xFlag($flag, false);
			return;
		}
		$this->ensureVp8x();
		$this->_riff->setChunkInOrder(new ImageChunk($id, strlen($data), 0, $data), self::ChunkOrder);
		$this->setVp8xFlag($flag, true);
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
	 * @param ?\Belisoful\Image\Meta\XMP $xmp The XMP, or null to drop the chunk.
	 */
	public function setXMP(?\Belisoful\Image\Meta\XMP $xmp): void
	{
		$this->setXmpText($xmp?->toPacketText());
	}

	/**
	 * Returns the EXIF metadata of the `EXIF` chunk, whose payload is bare TIFF bytes
	 * (WebP carries no `Exif\0\0` segment signature).
	 * @return ?\Belisoful\Image\Meta\EXIF The EXIF, or null when absent or unparsable.
	 */
	public function getEXIF(): ?\Belisoful\Image\Meta\EXIF
	{
		$data = $this->_riff?->getChunk(RIFFChunkType::Exif)?->getData();
		if ($data === null || $data === '') {
			return null;
		}
		try {
			$exif = \Belisoful\Image\Meta\EXIF::fromTiffString($data);
		} catch (\RuntimeException $e) {
			return null;
		}
		$exif->setSignature('');
		return $exif;
	}

	/**
	 * Sets (or removes, when null) the EXIF metadata, writing the bare TIFF bytes the
	 * `EXIF` chunk carries and flagging the feature in `VP8X`.
	 * @param ?\Belisoful\Image\Meta\EXIF $exif The EXIF, or null to drop the chunk.
	 */
	public function setEXIF(?\Belisoful\Image\Meta\EXIF $exif): void
	{
		if ($exif !== null) {
			$exif->setSignature('');   // the chunk holds no segment signature
		}
		$this->setMetaChunk(RIFFChunkType::Exif, self::Vp8xExifFlag, $exif?->toBinary());
	}

	/**
	 * Returns the ICC color profile of the `ICCP` chunk.
	 * @return ?string The ICC profile bytes, or null when absent.
	 */
	public function getICCProfile(): ?string
	{
		$data = $this->_riff?->getChunk(RIFFChunkType::ICCProfile)?->getData();
		return $data === '' ? null : $data;
	}

	/**
	 * Sets (or removes, when null) the ICC color profile, writing the `ICCP` chunk and
	 * flagging the feature in `VP8X`.
	 * @param ?string $profile The ICC profile bytes, or null to drop the chunk.
	 */
	public function setICCProfile(?string $profile): void
	{
		$this->setMetaChunk(RIFFChunkType::ICCProfile, self::Vp8xICCFlag, $profile);
	}

	/**
	 * Returns the IPTC record set, which a WebP container cannot carry.
	 * @return ?IPTC Always null.
	 */
	public function getIPTC(): ?IPTC
	{
		return null;
	}

	/**
	 * Refuses an IPTC record set: the WebP container specification defines chunks for
	 * EXIF, XMP, and an ICC profile only, and there is no established convention for IIM
	 * records.  Rather than accept data it would drop on {@see save()}, this throws — put
	 * the equivalent properties in {@see setXMP() XMP}, which WebP does carry.
	 * @param ?IPTC $iptc The IPTC record set; only null is accepted.
	 * @throws \RuntimeException When an IPTC record set is given.
	 */
	public function setIPTC(?IPTC $iptc): void
	{
		if ($iptc !== null) {
			throw new \RuntimeException('A WebP container has no IPTC carrier; put the equivalent properties in XMP.');
		}
	}

	/**
	 * Decodes the WebP bitstream into a graphics-library image.
	 * @param ?string $mode The {@see ImageGraphicsMode} to decode in; null for the default.
	 * @return false|\GdImage|\Imagick The image, or false when the build cannot read WebP.
	 */
	public function getImage(?string $mode = null): false|\GdImage|\Imagick
	{
		return ImageGraphics::decode($this->getBytesDirect(), $mode);
	}

	/**
	 * Re-encodes the WebP from a graphics-library image, carrying the existing ICC
	 * profile and EXIF/XMP metadata onto the new bitstream so an edit does not strip it.
	 * @param \GdImage|\Imagick $image The source image.
	 * @param int $quality The WebP quality. Default 80.
	 * @throws \RuntimeException When the image's graphics library cannot write WebP.
	 */
	public function setImage(\GdImage|\Imagick $image, int $quality = 80): void
	{
		$bytes = ImageGraphics::encode($image, ImageGraphicsLibraryInterface::FormatWebP, $quality);
		if ($bytes === false) {
			throw new \RuntimeException(sprintf('The %s graphics library on this installation cannot write WebP.', ImageGraphics::getModeOf($image)));
		}
		$carried = [];
		foreach (self::MetaChunkFlags as $id => $flag) {
			$data = $this->_riff?->getChunk($id)?->getData();
			if ($data !== null) {
				$carried[$id] = $data;
			}
		}
		$this->load($bytes);
		foreach ($carried as $id => $data) {
			$this->setMetaChunk($id, self::MetaChunkFlags[$id], $data);
		}
		$this->setBytesDirect($this->compose());
	}

	/**
	 * Creates a WebP from a graphics-library image.
	 * @param \GdImage|\Imagick $image The source image.
	 * @param int $quality The WebP quality. Default 80.
	 * @throws \RuntimeException When the image's graphics library cannot write WebP.
	 * @return static The new WebP.
	 */
	public static function fromImage(\GdImage|\Imagick $image, int $quality = 80): static
	{
		$bytes = ImageGraphics::encode($image, ImageGraphicsLibraryInterface::FormatWebP, $quality);
		if ($bytes === false) {
			throw new \RuntimeException(sprintf('The %s graphics library on this installation cannot write WebP.', ImageGraphics::getModeOf($image)));
		}
		return static::fromString($bytes);
	}

	/**
	 * Adds the extended-format `VP8X` header when the file lacks one, carrying the
	 * canvas size the simple bitstream declares.
	 */
	protected function ensureVp8x(): void
	{
		if ($this->_riff === null || $this->_riff->getChunk(RIFFChunkType::Vp8Extended) !== null) {
			return;
		}
		$width = max(1, (int) $this->getWidth());
		$height = max(1, (int) $this->getHeight());
		$payload = "\x00\x00\x00\x00"
			. substr(pack('V', $width - 1), 0, 3)
			. substr(pack('V', $height - 1), 0, 3);
		$this->_riff->prependChunk(new ImageChunk(RIFFChunkType::Vp8Extended, strlen($payload), 0, $payload));
	}

	/**
	 * Sets or clears a `VP8X` feature flag.
	 * @param int $flag The flag bit.
	 * @param bool $on Whether the feature is present.
	 */
	protected function setVp8xFlag(int $flag, bool $on): void
	{
		$vp8x = $this->_riff?->getChunk(RIFFChunkType::Vp8Extended);
		if ($vp8x === null || strlen($vp8x->getData()) < 10) {
			return;
		}
		$data = $vp8x->getData();
		$flags = ord($data[0]);
		$data[0] = chr($on ? ($flags | $flag) : ($flags & ~$flag));
		$vp8x->setData($data);
	}

	/**
	 * Rebuilds the WebP from its RIFF container.
	 * @return string The composed WebP bytes.
	 */
	protected function compose(): string
	{
		return $this->_riff === null ? $this->getBytesDirect() : $this->_riff->toBinary();
	}

	/**
	 * Parses the RIFF/WEBP container and reads the dimensions from its bitstream chunk.
	 * @throws \UnexpectedValueException When the bytes are not a RIFF/WEBP container.
	 */
	protected function parse(): void
	{
		$riff = RIFFContainer::fromString($this->getBytesDirect());
		if ($riff->getFormType() !== 'WEBP') {
			throw new \UnexpectedValueException(sprintf('WebP data is invalid: %s.', 'RIFF form type is not WEBP'));
		}
		$this->_riff = $riff;
		if (($vp8x = $riff->getChunk(RIFFChunkType::Vp8Extended)) !== null) {
			$this->readVp8x($vp8x->getData());
		} elseif (($vp8l = $riff->getChunk(RIFFChunkType::Vp8Lossless)) !== null) {
			$this->readVp8l($vp8l->getData());
		} elseif (($vp8 = $riff->getChunk(RIFFChunkType::Vp8)) !== null) {
			$this->readVp8($vp8->getData());
		}
	}

	/**
	 * Reads the canvas dimensions from a VP8X (extended) chunk.
	 * @param string $data The VP8X payload (flags, reserved, then two 24-bit sizes).
	 */
	private function readVp8x(string $data): void
	{
		if (strlen($data) >= 10) {
			$this->setWidthDirect(((ord($data[4]) | (ord($data[5]) << 8) | (ord($data[6]) << 16)) & 0xFFFFFF) + 1);
			$this->setHeightDirect(((ord($data[7]) | (ord($data[8]) << 8) | (ord($data[9]) << 16)) & 0xFFFFFF) + 1);
		}
	}

	/**
	 * Reads the dimensions from a VP8L (lossless) chunk.
	 * @param string $data The VP8L payload (0x2f signature, then packed 14-bit sizes).
	 */
	private function readVp8l(string $data): void
	{
		if (strlen($data) >= 5 && $data[0] === "\x2f") {
			$bits = ord($data[1]) | (ord($data[2]) << 8) | (ord($data[3]) << 16) | (ord($data[4]) << 24);
			$this->setWidthDirect(($bits & 0x3FFF) + 1);
			$this->setHeightDirect((($bits >> 14) & 0x3FFF) + 1);
		}
	}

	/**
	 * Reads the dimensions from a VP8 (lossy) key-frame chunk.
	 * @param string $data The VP8 payload (frame tag, 0x9d012a start code, then sizes).
	 */
	private function readVp8(string $data): void
	{
		if (strlen($data) >= 10 && substr($data, 3, 3) === "\x9d\x01\x2a") {
			$this->setWidthDirect((ord($data[6]) | (ord($data[7]) << 8)) & 0x3FFF);
			$this->setHeightDirect((ord($data[8]) | (ord($data[9]) << 8)) & 0x3FFF);
		}
	}
}
