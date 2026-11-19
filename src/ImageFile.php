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

	/**
	 * @var int The default {@see getMaxParts() part ceiling}: 2^16 structural parts before
	 *   a file's own length earns it any more.
	 */
	public const DefaultMaxParts = 0x10000;

	/** @var int The bytes of file length that allow one part beyond the ceiling. */
	public const BytesPerExtraPart = 256;

	/**
	 * @var int The bytes of a {@see setMemoryBudget() memory budget} that allow one part.
	 *   A part measures a few hundred bytes; the rest is room for the file itself.
	 */
	public const BudgetBytesPerPart = 2048;

	/**
	 * @var int The bytes of a memory budget that allow one inflated byte.  Reading a
	 *   hex-encoded raw profile was measured to peak at 4.4 times its inflated length.
	 */
	public const BudgetBytesPerInflatedByte = 8;

	/**
	 * @var int The bytes of a memory budget that allow one decoded pixel.  Decoding a TIFF
	 *   raster to an image was measured to peak at 9 bytes a pixel and a GIF frame at 4.
	 */
	public const BudgetBytesPerPixel = 16;

	/**
	 * @var float The share of PHP's `memory_limit` that is the {@see getMemoryBudget() memory
	 *   budget} until one is set: half, the rest being the application's and the file's own.
	 */
	public const DefaultBudgetShare = 0.5;

	/** @var ?int The part ceiling set at run time, or null for the budget's. */
	private static ?int $_maxParts = null;

	/**
	 * @var float|int The memory budget: a number of bytes as an integer, or a share of
	 *   PHP's `memory_limit` as a fraction.
	 */
	private static float|int $_budget = self::DefaultBudgetShare;

	/** @var ?string The `memory_limit` setting {@see $_limitBytes} was read from. */
	private static ?string $_limitText = null;

	/** @var ?int That setting in bytes, or null when it states no limit. */
	private static ?int $_limitBytes = null;

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
	 * Returns the part ceiling: how many structural parts a file may hold whatever its
	 * length.  A part is what a parser makes one record of: a JPEG segment, a PNG or RIFF
	 * chunk, an ISO base media box, a GIF block or data sub-block, a TIFF directory entry,
	 * a Photoshop resource, an IPTC dataset.
	 * Each costs a few hundred bytes of memory however small it is on disk, so without a
	 * ceiling a file made of nothing but empty parts costs twenty to a hundred times its
	 * own size and exhausts the memory limit: a fatal error no caller can catch.
	 *
	 * A file is allowed this many parts plus one more for every {@see BytesPerExtraPart}
	 * bytes of its length, so a large file of ordinary parts — a long animation, a PNG of
	 * many `IDAT` chunks, a movie's media list — is never refused for being large.
	 * @return int The ceiling, in parts: the one {@see setMaxParts() set}, else the
	 *   {@see getMemoryBudget() memory budget}'s, else {@see DefaultMaxParts}.
	 */
	public static function getMaxParts(): int
	{
		return self::$_maxParts ?? self::getBudgetCeiling(self::BudgetBytesPerPart) ?? self::DefaultMaxParts;
	}

	/**
	 * Sets the part ceiling.  Raise it, with the memory limit, to open a file that really
	 * does hold that many small parts.
	 * @param ?int $parts The ceiling in parts, or null to leave it to the
	 *   {@see getMemoryBudget() memory budget} again.
	 */
	public static function setMaxParts(?int $parts): void
	{
		self::$_maxParts = $parts;
	}

	/**
	 * Returns the memory budget: the memory the library sizes its three ceilings on
	 * untrusted input to.  PHP cannot catch running out of memory, so the library refuses,
	 * with an exception, what would not fit -- and the budget is what says how much that is:
	 *
	 * - the {@see getMaxParts() part ceiling} is one part for every
	 *   {@see BudgetBytesPerPart} bytes,
	 * - the {@see PNGImage::getMaxInflatedSize() inflation ceiling} one byte for every
	 *   {@see BudgetBytesPerInflatedByte}, and
	 * - the {@see ImageGraphics::getMaxPixels() pixel ceiling} one pixel for every
	 *   {@see BudgetBytesPerPixel}.
	 *
	 * Until one is {@see setMemoryBudget() set} the budget is {@see DefaultBudgetShare half}
	 * of PHP's `memory_limit`, read each time it is asked for, so the ceilings follow the
	 * limit the process really runs under.  A process with no memory limit has no budget,
	 * and the three ceilings are then their fixed defaults ({@see DefaultMaxParts},
	 * {@see PNGImage::MaxInflatedSize}, {@see ImageGraphics::DefaultMaxPixels}).
	 *
	 * The ratios are measured peaks with room to spare, not a meter: the limit is
	 * configuration and nothing watches memory while a file is read, so the same file opens
	 * or is refused the same way whatever else the application holds.  What no ceiling
	 * covers is the length of the file itself.  Reading one whole holds it and the rebuilt
	 * copy in memory, and only the caller decides what to pass in.
	 * @return ?int The budget in bytes, or null when there is none.
	 */
	public static function getMemoryBudget(): ?int
	{
		if (is_int(self::$_budget)) {
			return self::$_budget;
		}
		$limit = self::memoryLimit();
		return $limit === null ? null : (int) ($limit * self::$_budget);
	}

	/**
	 * Sets the {@see getMemoryBudget() memory budget}, and with it every ceiling that has
	 * not been set on its own.  A ceiling given to its own setter keeps that value,
	 * whichever of the two calls came first, until the setter is handed null.
	 * @param null|int|string $budget The budget as a number of bytes, as a size in PHP's
	 *   own shorthand (`'512K'`, `'256M'`, `'1G'`), or as a share of PHP's `memory_limit`
	 *   (`'25%'`, which is no budget when the process has no limit); null restores the
	 *   default of half the limit.
	 * @throws \InvalidArgumentException When the budget is negative, over 100%, or not one
	 *   of those forms.
	 */
	public static function setMemoryBudget(null|int|string $budget): void
	{
		$value = match (true) {
			$budget === null => self::DefaultBudgetShare,
			is_int($budget) => $budget,
			default => self::parseBudget($budget),
		};
		if ($value === null || $value < 0 || (is_float($value) && $value > 1)) {
			throw new \InvalidArgumentException(sprintf('Memory budget \'%s\' is not a number of bytes, a size such as \'256M\', or a share of the memory limit up to \'100%%\'.', $budget));
		}
		self::$_budget = $value;
	}

	/**
	 * Returns what a ceiling is under the {@see getMemoryBudget() memory budget}.
	 * @param int $bytesPerUnit The bytes of budget that allow one of what the ceiling counts.
	 * @return ?int The ceiling, or null when there is no budget.
	 */
	public static function getBudgetCeiling(int $bytesPerUnit): ?int
	{
		$budget = self::getMemoryBudget();
		return $budget === null ? null : intdiv($budget, $bytesPerUnit);
	}

	/**
	 * Reads a budget written as text.
	 * @param string $text A share (`'25%'`) or a size (`'256M'`).
	 * @return null|float|int The share as a fraction, the size in bytes, or null when the
	 *   text is neither.
	 */
	private static function parseBudget(string $text): null|float|int
	{
		if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*%\s*$/', $text, $match) === 1) {
			return (float) $match[1] / 100;
		}
		return self::parseSize($text);
	}

	/**
	 * Reads a size in the shorthand PHP's own settings use: a whole number of bytes,
	 * optionally followed by `K`, `M` or `G` for the powers of 1024.
	 * @param string $text The size.
	 * @return ?int The bytes, or null when the text is not a size -- which is what `-1`,
	 *   PHP's way of stating no limit, reads as.
	 */
	private static function parseSize(string $text): ?int
	{
		if (preg_match('/^\s*(\d+)\s*([KMG]?)\s*$/i', $text, $match) !== 1) {
			return null;
		}
		$unit = 1 << ['' => 0, 'K' => 10, 'M' => 20, 'G' => 30][strtoupper($match[2])];
		// More than an integer holds is simply as much as one holds.
		return min((int) $match[1], intdiv(PHP_INT_MAX, $unit)) * $unit;
	}

	/**
	 * Returns PHP's `memory_limit`, parsed once for each value the setting takes.
	 * @return ?int The limit in bytes, or null when the process has none.
	 */
	private static function memoryLimit(): ?int
	{
		$text = (string) ini_get('memory_limit');
		if ($text !== self::$_limitText) {
			self::$_limitText = $text;
			self::$_limitBytes = self::parseSize($text);
		}
		return self::$_limitBytes;
	}

	/**
	 * Indicates whether a count of parts is within what the {@see getMaxParts() ceiling} and
	 * a length allow.  The metadata parsers ask this rather than {@see assertPartCount()},
	 * because metadata over the ceiling reads as absent instead of refusing the file.
	 * @param int $count The parts counted so far.
	 * @param int $bytes The length of the data they were counted in; zero when unknown.
	 * @return bool Whether the count is allowed.
	 */
	public static function allowsPartCount(int $count, int $bytes = 0): bool
	{
		return $count <= self::getMaxParts() + intdiv($bytes, self::BytesPerExtraPart);
	}

	/**
	 * Refuses a structure holding more parts than the {@see getMaxParts() ceiling} and its
	 * length allow.  A parser calls this as it counts, before it records the part.
	 * @param int $count The parts counted so far, this one included.
	 * @param int $bytes The length of the data they were counted in; zero when unknown.
	 * @throws \UnexpectedValueException When the structure is over the ceiling.
	 */
	public static function assertPartCount(int $count, int $bytes = 0): void
	{
		if (!self::allowsPartCount($count, $bytes)) {
			throw new \UnexpectedValueException(sprintf('The data holds more than the %d parts its ceiling and length allow; raise it with ImageFile::setMaxParts().', self::getMaxParts() + intdiv($bytes, self::BytesPerExtraPart)));
		}
	}

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
			AVIImage::isAVI($bytes) => AVIImage::class,
			JXLImage::isJXL($bytes) => JXLImage::class,
			BMFFFile::isBMFF($bytes) => BMFFFile::class,
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
	 * Writes the rebuilt image to a file.  An existing file is replaced whole or left as
	 * it was -- see {@see writeFile()} -- so saving over the file that was read cannot
	 * leave half of one behind.
	 * @param string $path The destination file path.
	 * @throws \RuntimeException When the file cannot be written.
	 * @return int The number of bytes written.
	 */
	public function save(string $path): int
	{
		return $this->writeFile($path, $this->compose());
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
						// A container that reads a carrier but refuses to write it back (none
						// built in does) keeps the scrub only where the value was its live object.
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
