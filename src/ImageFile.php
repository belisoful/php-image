<?php

/**
 * ImageFile class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image;

use Belisoful\Image\Meta\EXIF;
use Belisoful\Image\Meta\IPTC;
use Belisoful\Image\Meta\XMP;
use Belisoful\Image\Stream\StreamIO;
use Psr\Http\Message\StreamInterface;

/**
 * ImageFile class.
 *
 * Serves as the abstract base for the image-container readers ({@see JPEGImage},
 * {@see PNGImage}, {@see WebPImage}).  It loads the whole image into memory, then a subclass
 * {@see parse()} walks the format's segments or chunks to fill in the pixel dimensions
 * and any embedded metadata.
 *
 * The readers report the canvas {@see getWidth() width} and {@see getHeight() height},
 * the {@see getFormat() format} name, and where present the {@see getEXIF() EXIF},
 * {@see getXMP() XMP}, {@see getIPTC() IPTC} record set and {@see getICCProfile() ICC
 * profile} — the metadata common across formats, reached the same way whatever the
 * container.  They read the image without re-encoding it.
 *
 * Calling a factory on the base class itself detects the format from the bytes and opens
 * the matching container, so a caller that does not know the format up front can still
 * read one polymorphically:
 *
 * ```php
 * $image = ImageFile::fromFile('photo.unknown');   // sniffs JPEG/PNG/GIF/WebP/TIFF
 * [$image->getFormat(), $image->getWidth(), $image->hasEXIF(), $image->getXMP()];
 * ```
 *
 * A factory called on a concrete container ({@see JPEGImage::fromFile()}) stays bound to
 * that format and rejects bytes of any other.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
abstract class ImageFile implements PrivacyScrubbableInterface
{
	use StreamIOTrait;

	/**
	 * Final so the static parse factories can safely instantiate any subclass with
	 * `new static()`; construction state comes from load(), never constructor arguments.
	 */
	final public function __construct()
	{
	}

	/** @var string The raw image bytes. */
	private string $_bytes = '';

	/** @var ?int The pixel width, or null when not yet parsed. */
	private ?int $_width = null;

	/** @var ?int The pixel height, or null when not yet parsed. */
	private ?int $_height = null;

	/** @var ?IPTC The parsed IPTC record set, or null when absent. */
	private ?IPTC $_iptc = null;

	/** @var ?string The embedded ICC color profile, or null when absent. */
	private ?string $_iccProfile = null;

	/**
	 * Creates a reader from a raw byte string.
	 * @param string $bytes The image bytes.
	 * @return static The parsed image reader.
	 */
	public static function fromString(string $bytes): static
	{
		if (static::class === self::class) {
			// Called on the abstract base: detect the container from the bytes and open it.
			$container = self::detect($bytes);
			/** @var static $image (a detected container is-a ImageFile, which static is here) */
			$image = $container::fromString($bytes);
			return $image;
		}
		$image = new static();
		$image->load($bytes);
		return $image;
	}

	/**
	 * Resolves the container class for an image from its leading bytes.
	 * @param string $bytes The image bytes.
	 * @throws \UnexpectedValueException When the bytes are not a recognized format.
	 * @return class-string<ImageFile> The matching container class.
	 */
	protected static function detect(string $bytes): string
	{
		return match (true) {
			JPEGImage::isJPEG($bytes) => JPEGImage::class,
			PNGImage::isPNG($bytes) => PNGImage::class,
			GIFImage::isGIF($bytes) => GIFImage::class,
			WebPImage::isWebP($bytes) => WebPImage::class,
			TIFFImage::isTIFF($bytes) => TIFFImage::class,
			default => throw new \UnexpectedValueException('The bytes are not a recognized image format (JPEG, PNG, GIF, WebP, or TIFF).'),
		};
	}

	/**
	 * Creates a reader from a PSR-7 stream or stream resource, reading it in full
	 * (a seekable stream is rewound first).
	 * @param mixed $stream The image {@see StreamInterface} or PHP stream resource.
	 * @return static The parsed image reader.
	 */
	public static function fromStream(mixed $stream): static
	{
		StreamIO::rewind($stream);
		return static::fromString(static::sourceBytes($stream));
	}

	/**
	 * Creates a reader from a file path.
	 * @param string $path The file path.
	 * @throws \RuntimeException When the file cannot be read.
	 * @return static The parsed image reader.
	 */
	public static function fromFile(string $path): static
	{
		$bytes = @file_get_contents($path);
		if ($bytes === false) {
			throw new \RuntimeException(sprintf('Image file \'%s\' cannot be read.', $path));
		}
		return static::fromString($bytes);
	}

	/**
	 * Stores the bytes and parses the container.
	 * @param string $bytes The image bytes.
	 */
	protected function load(string $bytes): void
	{
		$this->setBytesDirect($bytes);
		$this->parse();
	}

	//
	// ─── Protected raw accessors (self-encapsulation for subclasses) ─────────
	//

	/** @return string The raw image bytes. */
	protected function getBytesDirect(): string
	{
		return $this->_bytes;
	}

	/** @param string $value The raw image bytes. */
	protected function setBytesDirect(string $value): void
	{
		$this->_bytes = $value;
	}

	/** @return ?int The raw pixel width. */
	protected function getWidthDirect(): ?int
	{
		return $this->_width;
	}

	/** @param ?int $value The raw pixel width. */
	protected function setWidthDirect(?int $value): void
	{
		$this->_width = $value;
	}

	/** @return ?int The raw pixel height. */
	protected function getHeightDirect(): ?int
	{
		return $this->_height;
	}

	/** @param ?int $value The raw pixel height. */
	protected function setHeightDirect(?int $value): void
	{
		$this->_height = $value;
	}

	/** @return ?IPTC The raw IPTC record set. */
	protected function getIptcDirect(): ?IPTC
	{
		return $this->_iptc;
	}

	/** @param ?IPTC $value The raw IPTC record set. */
	protected function setIptcDirect(?IPTC $value): void
	{
		$this->_iptc = $value;
	}

	/** @return ?string The raw ICC profile. */
	protected function getICCProfileDirect(): ?string
	{
		return $this->_iccProfile;
	}

	/** @param ?string $value The raw ICC profile. */
	protected function setICCProfileDirect(?string $value): void
	{
		$this->_iccProfile = $value;
	}

	/**
	 * Walks the container to populate dimensions and metadata.
	 */
	abstract protected function parse(): void;

	/**
	 * Rebuilds the file bytes from the parsed structure, reflecting any edits.
	 * @return string The composed image bytes.
	 */
	abstract protected function compose(): string;

	/**
	 * Returns the format name (e.g. 'JPEG', 'PNG', 'WebP').
	 * @return string The format name.
	 */
	abstract public function getFormat(): string;

	/**
	 * Returns the rebuilt image bytes, reflecting any metadata or structural edits.
	 * @return string The composed image bytes.
	 */
	public function toBinary(): string
	{
		return $this->compose();
	}

	/**
	 * Returns the rebuilt image bytes.
	 * @return string The composed image bytes.
	 */
	public function __toString(): string
	{
		return $this->compose();
	}

	/**
	 * Returns the rebuilt image as a rewound in-memory stream resource.  The library
	 * consumes PSR-7 streams but does not implement one, so the readable handle it hands
	 * back is a plain PHP resource; wrap it in the PSR-7 implementation of your choice
	 * when you need a {@see StreamInterface}.
	 * @return resource The composed image as a stream resource, positioned at the start.
	 */
	public function toStream()
	{
		return StreamIO::temp($this->compose());
	}

	/**
	 * Writes the rebuilt image to a target, streaming any large payload in bounded memory
	 * where the container parsed lazily (its payloads kept as
	 * {@see \Belisoful\Image\Stream\SourceRange} references, via `fromStreamLazy()`), so a
	 * file too large to hold can still be rewritten around a metadata edit; a fully loaded
	 * container writes the same bytes {@see toBinary()} would.
	 * @param mixed $target A writable {@see StreamInterface} or PHP stream resource.
	 * @throws \InvalidArgumentException When the target is neither.
	 * @throws \RuntimeException When the target stops accepting bytes.
	 * @return int The number of bytes written.
	 */
	abstract public function streamTo(mixed $target): int;

	/**
	 * Writes the rebuilt image to a file.
	 * @param string $path The destination file path.
	 * @throws \RuntimeException When the file cannot be written.
	 * @return int The number of bytes written.
	 */
	public function save(string $path): int
	{
		$written = @file_put_contents($path, $this->compose());
		if ($written === false) {
			throw new \RuntimeException(sprintf('Image file \'%s\' cannot be written.', $path));
		}
		return $written;
	}

	/**
	 * Returns the pixel width.
	 * @return ?int The width in pixels, or null when unknown.
	 */
	public function getWidth(): ?int
	{
		return $this->getWidthDirect();
	}

	/**
	 * Returns the pixel height.
	 * @return ?int The height in pixels, or null when unknown.
	 */
	public function getHeight(): ?int
	{
		return $this->getHeightDirect();
	}

	/**
	 * Indicates whether the image carries EXIF metadata.
	 * @return bool Whether EXIF is present.
	 */
	public function hasEXIF(): bool
	{
		return $this->getEXIF() !== null;   // via the accessor, which a container overrides
	}

	/**
	 * Returns the parsed EXIF metadata.  A format with no EXIF carrier (GIF) has none, so
	 * the base returns null; a container that carries EXIF overrides this.
	 * @return ?EXIF The EXIF metadata, or null when absent.
	 */
	public function getEXIF(): ?EXIF
	{
		return null;
	}

	/**
	 * Sets (or clears, when null) the EXIF metadata.  A container that carries EXIF
	 * overrides this; on a format with no writable EXIF carrier, setting a non-null value
	 * throws rather than silently dropping it (clearing null is a no-op).
	 * @param ?EXIF $exif The EXIF metadata, or null to drop it.
	 * @throws \RuntimeException When the format has no writable EXIF carrier.
	 */
	public function setEXIF(?EXIF $exif): void
	{
		if ($exif !== null) {
			throw new \RuntimeException(sprintf('A %s image has no writable EXIF carrier.', $this->getFormat()));
		}
	}

	/**
	 * Indicates whether the image carries XMP metadata.
	 * @return bool Whether an XMP packet is present.
	 */
	public function hasXMP(): bool
	{
		return $this->getXMP() !== null;   // via the accessor every container implements
	}

	/**
	 * Returns the parsed XMP packet.
	 * @return ?XMP The XMP packet, or null when absent.
	 */
	abstract public function getXMP(): ?XMP;

	/**
	 * Sets (or clears, when null) the XMP packet written back on compose.
	 * @param ?XMP $xmp The XMP packet, or null to drop it.
	 */
	abstract public function setXMP(?XMP $xmp): void;

	/**
	 * Indicates whether the image carries IPTC metadata.
	 * @return bool Whether an IPTC record set is present.
	 */
	public function hasIPTC(): bool
	{
		return $this->getIPTC() !== null;   // via the accessor, which a container may override
	}

	/**
	 * Returns the parsed IPTC record set.
	 * @return ?IPTC The IPTC record set, or null when absent.
	 */
	public function getIPTC(): ?IPTC
	{
		return $this->getIptcDirect();
	}

	/**
	 * Sets (or clears, when null) the IPTC record set written back on {@see save()}.
	 * @param ?IPTC $iptc The IPTC record set, or null to drop it.
	 */
	public function setIPTC(?IPTC $iptc): void
	{
		$this->setIptcDirect($iptc);
	}

	/**
	 * Indicates whether the image carries an embedded ICC color profile.
	 * @return bool Whether an ICC profile is present.
	 */
	public function hasICCProfile(): bool
	{
		return $this->getICCProfile() !== null;   // via the accessor, which a container may override
	}

	/**
	 * Returns the embedded ICC color profile.
	 * @return ?string The ICC profile bytes, or null when absent.
	 */
	public function getICCProfile(): ?string
	{
		return $this->getICCProfileDirect();
	}

	/**
	 * Sets (or clears, when null) the ICC color profile written back on {@see save()}.
	 * @param ?string $profile The ICC profile bytes, or null to drop it.
	 */
	public function setICCProfile(?string $profile): void
	{
		$this->setICCProfileDirect($profile);
	}

	/**
	 * Returns the raw image bytes.
	 * @return string The image bytes.
	 */
	public function getBytes(): string
	{
		return $this->getBytesDirect();
	}

	//
	// ─── Privacy ─────────────────────────────────────────────────────────────
	//

	/**
	 * Removes identifying information from the whole file by category: every metadata
	 * carrier this container holds — EXIF, XMP, IPTC, the Photoshop IRB, and, per format,
	 * the Kodak Meta block, JFIF/JFXX thumbnails, comments, and text chunks — is scrubbed
	 * with the same {@see PrivacyCategory} flags in one call, so a photo can leave a
	 * user's control without disclosing where, when, by whom, or with what it was taken.
	 * The default clears everything.
	 *
	 * ```php
	 * $jpeg = JPEGImage::fromFile('photo.jpg');
	 * $jpeg->clearPrivateData();                            // the safe default
	 * $jpeg->save('photo-shareable.jpg');
	 * $png->clearPrivateData(PrivacyCategory::Location);   // just where it was taken
	 * ```
	 *
	 * Each carrier is scrubbed in place and written back through its setter, so what
	 * survives is exactly what a re-read of the saved file will show.  A carrier the
	 * container does not have is skipped; the method never fails.  Subclasses extend the
	 * reach through {@see clearFormatPrivateData()} for the fields only their format has.
	 * @param int $types The {@see PrivacyCategory} flags to remove. Default {@see PrivacyCategory::All}.
	 * @return int The number of fields, records, resources, and directories removed across every carrier.
	 */
	public function clearPrivateData(int $types = PrivacyCategory::All): int
	{
		$removed = 0;

		// The carriers most containers share, reached through their own accessors so a
		// container's overrides (PNG's 8BIM text chunk, WebP's RIFF chunk) are honored.
		foreach (['EXIF', 'Meta', 'XMP', 'PhotoshopIRB'] as $carrier) {
			$getter = 'get' . $carrier;
			$setter = 'set' . $carrier;
			if (!method_exists($this, $getter) || !method_exists($this, $setter)) {
				continue;
			}
			$value = call_user_func([$this, $getter]);
			if ($value instanceof PrivacyScrubbableInterface) {
				$count = $value->clearPrivateData($types);
				if ($count > 0) {
					try {
						call_user_func([$this, $setter], $value);
					} catch (\RuntimeException $e) {
						// A read-only carrier (TIFF's EXIF is its live IFD, already scrubbed in
						// place above) refuses the write-back; the scrub still took effect.
					}
					$removed += $count;
				}
			}
		}

		// The IPTC record set, which every container answers (some through the IRB).
		$iptc = $this->getIPTC();
		if ($iptc !== null) {
			$count = $iptc->clearPrivateData($types);
			if ($count > 0) {
				try {
					$this->setIPTC($iptc);
				} catch (\RuntimeException $e) {
					// A container with no IPTC carrier can only have answered null above,
					// so this cannot occur; guarded for a subclass that reads but refuses writes.
				}
				$removed += $count;
			}
		}

		return $removed + $this->clearFormatPrivateData($types);
	}

	/**
	 * Removes the identifying fields only this format has — a JPEG's comment and APP0
	 * thumbnails, a GIF's comment extensions, a PNG's text chunks.  The base
	 * implementation removes nothing; a container overrides it to extend the reach of
	 * {@see clearPrivateData()} to its own carriers.
	 * @param int $types The {@see PrivacyCategory} flags to remove.
	 * @return int The number of fields removed.
	 */
	protected function clearFormatPrivateData(int $types): int
	{
		return 0;
	}
}
