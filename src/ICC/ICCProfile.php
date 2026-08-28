<?php

/**
 * ICCProfile class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\ICC;

/**
 * ICCProfile class.
 *
 * Reads, edits, and rewrites an ICC profile (ICC.1) — the color-space description that
 * rides in a JPEG APP2 `ICC_PROFILE` segment, a PNG `iCCP` chunk, a TIFF tag 34675, or a
 * Photoshop IRB resource.  The containers carry the profile as bytes; this class decodes
 * the 128-byte header and the tag table so a profile can be inspected and changed rather
 * than only swapped whole.
 *
 * ```php
 * $profile = ICCProfile::parse($jpeg->getICCProfile());
 * $profile->getDescription();                       // 'sRGB IEC61966-2.1'
 * $profile->getColorSpace();                        // 'RGB '
 * $profile->setRenderingIntent(ICCProfile::IntentPerceptual);
 * $jpeg->setICCProfile($profile->toBinary());       // recomposed, offsets recomputed
 * ```
 *
 * The color math needed to convert pixels between two profiles is exposed as
 * {@see getMatrix()} (the D50-relative primaries) and {@see getToneCurves()} (the
 * per-channel curves), which {@see ICCTransform} consumes.  Those are
 * only present on a **matrix/TRC** profile ({@see getIsMatrixShaper()}) — the form
 * sRGB, Adobe RGB, and Display P3 use.  A profile whose conversion lives in
 * multi-dimensional lookup tables ({@see getIsLutBased()}, the usual form for CMYK and
 * printer profiles) is read and rewritten faithfully, but this class does not evaluate
 * its tables.
 *
 * Editing a tag clears the stored profile id, which the ICC specification permits to be
 * all zero when it has not been computed, rather than leaving a digest that no longer
 * matches the bytes.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @see https://www.color.org/icc_specs2.xalter
 */
class ICCProfile
{
	use \Belisoful\Image\StreamIOTrait;

	/** @var int The fixed size of the profile header, in bytes. */
	public const HeaderSize = 128;

	public const ClassInput = 'scnr';
	public const ClassDisplay = 'mntr';
	public const ClassOutput = 'prtr';
	public const ClassLink = 'link';
	public const ClassColorSpace = 'spac';
	public const ClassAbstract = 'abst';
	public const ClassNamedColor = 'nmcl';

	public const SpaceXyz = 'XYZ ';
	public const SpaceLab = 'Lab ';
	public const SpaceRgb = 'RGB ';
	public const SpaceGray = 'GRAY';
	public const SpaceCmyk = 'CMYK';

	public const IntentPerceptual = 0;
	public const IntentRelativeColorimetric = 1;
	public const IntentSaturation = 2;
	public const IntentAbsoluteColorimetric = 3;

	/** @var array<int, int> The parameter count of each `parametricCurveType` function. */
	public const ParametricParameterCounts = [0 => 1, 1 => 3, 2 => 4, 3 => 5, 4 => 7];

	/** @var string[] The tag signatures whose content is a multi-dimensional lookup table. */
	public const LutTags = ['A2B0', 'A2B1', 'A2B2', 'B2A0', 'B2A1', 'B2A2', 'gamt', 'pre0', 'pre1', 'pre2'];

	/** @var string The 128-byte header. */
	private string $_header;

	/** @var array<string, string> The tag content, by signature, in tag-table order. */
	private array $_tags = [];

	/** @var bool Whether a tag has been added, replaced, or removed since parsing. */
	private bool $_edited = false;

	/**
	 * A profile is built by {@see parse()} or {@see fromStream()}; the constructor is
	 * final so those factories can answer `new static()` in a subclass.
	 */
	final public function __construct()
	{
	}

	/**
	 * Parses an ICC profile.  The header must carry the `acsp` signature and a size that
	 * covers the tag table; a profile whose declared size exceeds the data is parsed from
	 * what is present, since containers occasionally pad or truncate the trailing bytes.
	 * @param string $bytes The profile bytes.
	 * @return ?static The profile, or null when the bytes are not an ICC profile.
	 */
	public static function parse(string $bytes): ?static
	{
		if (strlen($bytes) < self::HeaderSize + 4 || substr($bytes, 36, 4) !== 'acsp') {
			return null;
		}
		$profile = new static();
		$profile->_header = substr($bytes, 0, self::HeaderSize);

		$count = unpack('N', substr($bytes, self::HeaderSize, 4))[1];
		if ($count > 0xFFFF || strlen($bytes) < self::HeaderSize + 4 + $count * 12) {
			return null;
		}
		for ($i = 0; $i < $count; $i++) {
			$entry = substr($bytes, self::HeaderSize + 4 + $i * 12, 12);
			$signature = substr($entry, 0, 4);
			$offset = unpack('N', substr($entry, 4, 4))[1];
			$size = unpack('N', substr($entry, 8, 4))[1];
			if ($offset + $size > strlen($bytes)) {
				continue;   // a truncated tag is dropped rather than failing the profile
			}
			$profile->_tags[$signature] = substr($bytes, $offset, $size);
		}
		return $profile;
	}

	/**
	 * Builds a profile from a byte source.
	 * @param mixed $source A string, {@see \Psr\Http\Message\StreamInterface}, or stream resource.
	 * @return ?static The profile, or null when the bytes are not an ICC profile.
	 */
	public static function fromStream(mixed $source): ?static
	{
		return static::parse(static::sourceBytes($source));
	}

	/**
	 * Recomposes the profile: the header with a corrected size, then the tag table and
	 * the tag content, each tag padded to a four-byte boundary as the specification
	 * requires.  Tags sharing identical content share one data block, the way the
	 * grayscale and RGB tone curves of a real profile do.
	 * @return string The profile bytes.
	 */
	public function toBinary(): string
	{
		$offset = self::HeaderSize + 4 + count($this->_tags) * 12;
		$table = pack('N', count($this->_tags));
		$data = '';
		$placed = [];
		foreach ($this->_tags as $signature => $content) {
			$key = md5($content);
			if (isset($placed[$key])) {
				$table .= $signature . pack('N', $placed[$key][0]) . pack('N', $placed[$key][1]);
				continue;
			}
			$placed[$key] = [$offset, strlen($content)];
			$table .= $signature . pack('N', $offset) . pack('N', strlen($content));
			$padding = (4 - strlen($content) % 4) % 4;
			$data .= $content . str_repeat("\0", $padding);
			$offset += strlen($content) + $padding;
		}
		$body = $table . $data;
		$header = $this->_header;
		if ($this->_edited) {
			// The profile id is an MD5 of the whole profile; the specification allows all
			// zero for "not computed", which is truer than a stale digest.
			$header = substr($header, 0, 84) . str_repeat("\0", 16) . substr($header, 100);
		}
		return pack('N', self::HeaderSize + strlen($body)) . substr($header, 4) . $body;
	}

	//
	// ─── Header fields ───────────────────────────────────────────────────────
	//

	/**
	 * Returns the profile size the header declares, which {@see toBinary()} recomputes.
	 * @return int The size in bytes.
	 */
	public function getSize(): int
	{
		return unpack('N', substr($this->_header, 0, 4))[1];
	}

	/**
	 * Returns the encoded specification version, such as 0x02100000 for version 2.1.
	 * @return int The version.
	 */
	public function getVersion(): int
	{
		return unpack('N', substr($this->_header, 8, 4))[1];
	}

	/**
	 * Returns the specification version as text, such as '4.3.0'.
	 * @return string The dotted version.
	 */
	public function getVersionString(): string
	{
		$version = $this->getVersion();
		return sprintf('%d.%d.%d', ($version >> 24) & 0xFF, ($version >> 20) & 0x0F, ($version >> 16) & 0x0F);
	}

	/**
	 * Returns the device class: one of the `Class*` constants.
	 * @return string The four-character device class.
	 */
	public function getDeviceClass(): string
	{
		return substr($this->_header, 12, 4);
	}

	/**
	 * Returns the data color space: one of the `Space*` constants.
	 * @return string The four-character color space.
	 */
	public function getColorSpace(): string
	{
		return substr($this->_header, 16, 4);
	}

	/**
	 * Returns the profile connection space, `XYZ ` or `Lab ` (a device-link profile may
	 * carry a device space instead).
	 * @return string The four-character connection space.
	 */
	public function getConnectionSpace(): string
	{
		return substr($this->_header, 20, 4);
	}

	/**
	 * Returns the creation date and time from the header, when it carries one.
	 * @return ?string The date as 'YYYY-MM-DD HH:MM:SS', or null when unset.
	 */
	public function getDateTime(): ?string
	{
		$parts = array_values(unpack('n6', substr($this->_header, 24, 12)));
		if ($parts[0] === 0) {
			return null;
		}
		return sprintf('%04d-%02d-%02d %02d:%02d:%02d', ...$parts);
	}

	/**
	 * Returns the primary platform signature, such as 'APPL' or 'MSFT'.
	 * @return string The four-character platform, or four spaces when unset.
	 */
	public function getPlatform(): string
	{
		return substr($this->_header, 40, 4);
	}

	/**
	 * Returns the device manufacturer signature.
	 * @return string The four-character manufacturer.
	 */
	public function getManufacturer(): string
	{
		return substr($this->_header, 48, 4);
	}

	/**
	 * Returns the device model signature.
	 * @return string The four-character model.
	 */
	public function getModel(): string
	{
		return substr($this->_header, 52, 4);
	}

	/**
	 * Returns the profile creator signature.
	 * @return string The four-character creator.
	 */
	public function getCreator(): string
	{
		return substr($this->_header, 80, 4);
	}

	/**
	 * Returns the rendering intent: one of the `Intent*` constants.
	 * @return int The rendering intent.
	 */
	public function getRenderingIntent(): int
	{
		return unpack('N', substr($this->_header, 64, 4))[1];
	}

	/**
	 * Sets the rendering intent.
	 * @param int $value One of the `Intent*` constants.
	 * @throws \InvalidArgumentException When the intent is not 0-3.
	 */
	public function setRenderingIntent(int $value): void
	{
		if ($value < self::IntentPerceptual || $value > self::IntentAbsoluteColorimetric) {
			throw new \InvalidArgumentException(sprintf('\'%s\' is not an ICC rendering intent; use 0 (perceptual), 1 (relative colorimetric), 2 (saturation), or 3 (absolute colorimetric).', $value));
		}
		$this->_header = substr($this->_header, 0, 64) . pack('N', $value) . substr($this->_header, 68);
	}

	/**
	 * Returns the profile id: the MD5 digest of the profile, when it carries one.
	 * @return ?string The 16 digest bytes, or null when not computed.
	 */
	public function getProfileId(): ?string
	{
		$id = substr($this->_header, 84, 16);
		return $id === str_repeat("\0", 16) ? null : $id;
	}

	/**
	 * Returns the header's PCS illuminant, normally the D50 white point.
	 * @return array The [X, Y, Z] tristimulus values.
	 */
	public function getIlluminant(): array
	{
		return static::readXyzNumber(substr($this->_header, 68, 12));
	}

	/**
	 * Writes bytes into the fixed header at an offset, marking the profile edited.
	 * @param int $offset The byte offset within the 128-byte header.
	 * @param string $bytes The bytes to write.
	 */
	protected function writeHeader(int $offset, string $bytes): void
	{
		$this->_header = substr_replace($this->_header, $bytes, $offset, strlen($bytes));
		$this->_edited = true;
	}

	/**
	 * Returns the preferred CMM type signature.
	 * @return string The four-character CMM signature.
	 */
	public function getCMMType(): string
	{
		return substr($this->_header, 4, 4);
	}

	/**
	 * Sets the preferred CMM type signature.
	 * @param string $value The signature, padded to four characters.
	 */
	public function setCMMType(string $value): void
	{
		$this->writeHeader(4, static::padSignature($value));
	}

	/**
	 * Sets the encoded specification version.
	 * @param int $value The version, such as 0x04300000 for version 4.3.0.
	 */
	public function setVersion(int $value): void
	{
		$this->writeHeader(8, pack('N', $value & 0xFFFFFFFF));
	}

	/**
	 * Sets the specification version from its dotted form; the minor and bug-fix parts
	 * are optional.
	 * @param string $value The dotted version, such as '4.3.0' or '2'.
	 * @throws \InvalidArgumentException When the version is not dotted decimal.
	 */
	public function setVersionString(string $value): void
	{
		if (!preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?$/', trim($value), $match)) {
			throw new \InvalidArgumentException(sprintf('\'%s\' is not a dotted ICC version such as \'4.3.0\'.', $value));
		}
		$this->setVersion((((int) $match[1] & 0xFF) << 24)
			| (((int) ($match[2] ?? 0) & 0x0F) << 20)
			| (((int) ($match[3] ?? 0) & 0x0F) << 16));
	}

	/**
	 * Returns the major specification version.
	 * @return int The major version, such as 4.
	 */
	public function getVersionMajor(): int
	{
		return ($this->getVersion() >> 24) & 0xFF;
	}

	/**
	 * Returns the minor specification version.
	 * @return int The minor version.
	 */
	public function getVersionMinor(): int
	{
		return ($this->getVersion() >> 20) & 0x0F;
	}

	/**
	 * Returns the bug-fix specification version.
	 * @return int The bug-fix version.
	 */
	public function getVersionBugFix(): int
	{
		return ($this->getVersion() >> 16) & 0x0F;
	}

	/**
	 * Sets the device class.
	 * @param string $value One of the `Class*` constants.
	 */
	public function setDeviceClass(string $value): void
	{
		$this->writeHeader(12, static::padSignature($value));
	}

	/**
	 * Sets the data color space.
	 * @param string $value One of the `Space*` constants.
	 */
	public function setColorSpace(string $value): void
	{
		$this->writeHeader(16, static::padSignature($value));
	}

	/**
	 * Sets the profile connection space.
	 * @param string $value {@see SpaceXyz} or {@see SpaceLab}.
	 */
	public function setConnectionSpace(string $value): void
	{
		$this->writeHeader(20, static::padSignature($value));
	}

	/**
	 * Sets (or zeroes, when null) the creation date, which the header stores as UTC.
	 * @param null|\DateTimeInterface|string $value The date, a string any
	 *   {@see \DateTimeImmutable} accepts, or null to zero the field.
	 * @throws \InvalidArgumentException When a string is not a date.
	 */
	public function setDateTime(null|string|\DateTimeInterface $value): void
	{
		if ($value === null) {
			$this->writeHeader(24, str_repeat("\0", 12));
			return;
		}
		if (is_string($value)) {
			try {
				$value = new \DateTimeImmutable($value);
			} catch (\Exception $e) {
				throw new \InvalidArgumentException(sprintf('\'%s\' is not a date an ICC creation-date field can hold.', $value));
			}
		}
		$utc = \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'));
		$this->writeHeader(24, pack(
			'n6',
			(int) $utc->format('Y'),
			(int) $utc->format('n'),
			(int) $utc->format('j'),
			(int) $utc->format('G'),
			(int) $utc->format('i'),
			(int) $utc->format('s'),
		));
	}

	/**
	 * Sets the primary platform signature.
	 * @param string $value The signature, such as 'APPL' or 'MSFT'.
	 */
	public function setPlatform(string $value): void
	{
		$this->writeHeader(40, static::padSignature($value));
	}

	/**
	 * Returns the profile flags field, whose low bits mark the profile embedded and its
	 * use restricted to the embedding file.
	 * @return int The flags.
	 */
	public function getFlags(): int
	{
		return unpack('N', substr($this->_header, 44, 4))[1];
	}

	/**
	 * Sets the profile flags field.
	 * @param int $value The flags.
	 */
	public function setFlags(int $value): void
	{
		$this->writeHeader(44, pack('N', $value & 0xFFFFFFFF));
	}

	/**
	 * Sets the device manufacturer signature.
	 * @param string $value The signature.
	 */
	public function setManufacturer(string $value): void
	{
		$this->writeHeader(48, static::padSignature($value));
	}

	/**
	 * Sets the device model signature.
	 * @param string $value The signature.
	 */
	public function setModel(string $value): void
	{
		$this->writeHeader(52, static::padSignature($value));
	}

	/**
	 * Returns the 64-bit device attributes field (reflective or transparent, glossy or
	 * matte, and the media polarity and colour).
	 * @return int The attributes.
	 */
	public function getAttributes(): int
	{
		return unpack('J', substr($this->_header, 56, 8))[1];
	}

	/**
	 * Sets the 64-bit device attributes field.
	 * @param int $value The attributes.
	 */
	public function setAttributes(int $value): void
	{
		$this->writeHeader(56, pack('J', $value));
	}

	/**
	 * Sets the PCS illuminant, which the specification fixes at D50 for every profile
	 * the connection space is defined against.
	 * @param array $value The [X, Y, Z] tristimulus values.
	 */
	public function setIlluminant(array $value): void
	{
		$this->writeHeader(68, static::packXyzNumber($value));
	}

	/**
	 * Sets the profile creator signature.
	 * @param string $value The signature.
	 */
	public function setCreator(string $value): void
	{
		$this->writeHeader(80, static::padSignature($value));
	}

	/**
	 * Computes the profile id and stores it in the header: the MD5 of the composed
	 * profile with the flags, rendering intent, and profile id fields zeroed, as the
	 * specification defines.  A profile is otherwise left with the id it was parsed with,
	 * or with none once edited, rather than being silently re-digested on every write.
	 * @return string The 16 digest bytes.
	 */
	public function computeProfileId(): string
	{
		$this->writeHeader(84, str_repeat("\0", 16));
		$bytes = $this->toBinary();
		$bytes = substr_replace($bytes, "\0\0\0\0", 44, 4);
		$bytes = substr_replace($bytes, "\0\0\0\0", 64, 4);
		$digest = md5($bytes, true);
		$this->_header = substr_replace($this->_header, $digest, 84, 16);
		$this->_edited = false;   // the stored digest now matches the bytes
		return $digest;
	}

	//
	// ─── Tags ────────────────────────────────────────────────────────────────
	//

	/**
	 * Returns the tag signatures the profile carries, in tag-table order.
	 * @return string[] The four-character signatures.
	 */
	public function getTagSignatures(): array
	{
		return array_keys($this->_tags);
	}

	/**
	 * Indicates whether the profile carries a tag.
	 * @param string $signature The four-character tag signature.
	 * @return bool Whether the tag is present.
	 */
	public function hasTag(string $signature): bool
	{
		return isset($this->_tags[$signature]);
	}

	/**
	 * Returns a tag's content, including its leading four-character type signature.
	 * @param string $signature The four-character tag signature.
	 * @return ?string The tag content, or null when absent.
	 */
	public function getTag(string $signature): ?string
	{
		return $this->_tags[$signature] ?? null;
	}

	/**
	 * Sets (or removes, when null) a tag's content.  This is what makes a profile
	 * editable rather than replaceable: the tag table and offsets are recomputed by
	 * {@see toBinary()}.
	 * @param string $signature The four-character tag signature.
	 * @param ?string $content The tag content with its type signature, or null to remove.
	 */
	public function setTag(string $signature, ?string $content): void
	{
		$signature = static::padSignature($signature);
		if ($content === null) {
			unset($this->_tags[$signature]);
		} else {
			$this->_tags[$signature] = $content;
		}
		$this->_edited = true;
	}

	/**
	 * Returns a tag's type signature, the first four bytes of its content.
	 * @param string $signature The four-character tag signature.
	 * @return ?string The type signature, or null when the tag is absent.
	 */
	public function getTagType(string $signature): ?string
	{
		$content = $this->_tags[$signature] ?? null;
		return $content === null || strlen($content) < 4 ? null : substr($content, 0, 4);
	}

	/**
	 * Returns every tag's content, by signature, in tag-table order.
	 * @return array<string, string> The tag content.
	 */
	public function getTags(): array
	{
		return $this->_tags;
	}

	/**
	 * Removes a tag.
	 * @param string $signature The four-character tag signature.
	 * @return bool Whether the tag was present.
	 */
	public function removeTag(string $signature): bool
	{
		$signature = static::padSignature($signature);
		if (!isset($this->_tags[$signature])) {
			return false;
		}
		unset($this->_tags[$signature]);
		$this->_edited = true;
		return true;
	}

	/**
	 * Points a tag at another tag's content, so the two share one data element the way a
	 * real profile's identical red, green, and blue tone curves do.  {@see toBinary()}
	 * writes shared content once and points both table entries at it.
	 * @param string $signature The tag to link.
	 * @param string $target The tag whose content is shared.
	 * @throws \InvalidArgumentException When the target tag is absent.
	 */
	public function aliasTag(string $signature, string $target): void
	{
		$target = static::padSignature($target);
		if (!isset($this->_tags[$target])) {
			throw new \InvalidArgumentException(sprintf('ICC tag \'%s\' is not present in the profile.', $target));
		}
		$this->setTag($signature, $this->_tags[$target]);
	}

	/**
	 * Indicates whether two tags hold the same content, and so share one data element
	 * when written.
	 * @param string $signatureA The first tag signature.
	 * @param string $signatureB The second tag signature.
	 * @return bool Whether both are present and identical.
	 */
	public function sharesData(string $signatureA, string $signatureB): bool
	{
		$signatureA = static::padSignature($signatureA);
		$signatureB = static::padSignature($signatureB);
		return isset($this->_tags[$signatureA], $this->_tags[$signatureB])
			&& $this->_tags[$signatureA] === $this->_tags[$signatureB];
	}

	/**
	 * Returns the profile description from the `desc` tag, decoding either the version 2
	 * `textDescriptionType` or the version 4 `multiLocalizedUnicodeType`.
	 * @return ?string The description, or null when absent or undecodable.
	 */
	public function getDescription(): ?string
	{
		return $this->getTagText('desc');
	}

	/**
	 * Returns the copyright from the `cprt` tag.
	 * @return ?string The copyright, or null when absent or undecodable.
	 */
	public function getCopyright(): ?string
	{
		return $this->getTagText('cprt');
	}

	/**
	 * Returns a text-bearing tag's text, decoding the plain `text` type, the version 2
	 * `desc` (textDescriptionType), or the version 4 `mluc`
	 * (multiLocalizedUnicodeType) — of which the English record is preferred, falling
	 * back to the first.
	 * @param string $signature The four-character tag signature.
	 * @return ?string The text, or null when the tag is absent or of another type.
	 */
	public function getTagText(string $signature): ?string
	{
		$data = $this->getTag($signature);
		if ($data === null || strlen($data) < 8) {
			return null;
		}
		switch (substr($data, 0, 4)) {
			case 'text':
				$text = substr($data, 8);
				$nul = strpos($text, "\0");
				return $nul === false ? $text : substr($text, 0, $nul);
			case 'desc':
				if (strlen($data) < 12) {
					return null;
				}
				$count = unpack('N', substr($data, 8, 4))[1];
				return rtrim(substr($data, 12, max(0, $count)), "\0");
			case 'mluc':
				$records = static::decodeMluc($data);
				if ($records === null || $records === []) {
					return null;
				}
				foreach ($records as $locale => $text) {
					if (str_starts_with($locale, 'en')) {
						return $text;
					}
				}
				return reset($records);
			default:
				return null;
		}
	}

	/**
	 * Sets a tag's text.  An existing tag keeps whichever text type it already uses; a
	 * new one is written as `mluc` for a version 4 profile and `desc` otherwise, unless a
	 * type is named.
	 * @param string $signature The four-character tag signature (e.g. 'desc', 'cprt').
	 * @param string $text The UTF-8 text.
	 * @param ?string $type The forced type: 'text', 'desc', or 'mluc'; null to keep or pick.
	 * @throws \InvalidArgumentException When the named type is not a text type.
	 */
	public function setTagText(string $signature, string $text, ?string $type = null): void
	{
		if ($type !== null && !in_array($type, ['text', 'desc', 'mluc'], true)) {
			throw new \InvalidArgumentException(sprintf('\'%s\' is not an ICC text tag type; use \'text\', \'desc\', or \'mluc\'.', $type));
		}
		if ($type === null) {
			$current = $this->getTagType($signature);
			$type = in_array($current, ['text', 'desc', 'mluc'], true)
				? $current
				: ($this->getVersionMajor() >= 4 ? 'mluc' : 'desc');
		}
		$this->setTag($signature, match ($type) {
			'text' => 'text' . "\0\0\0\0" . $text . "\0",
			'desc' => static::encodeTextDescription($text),
			default => static::encodeMluc(['enUS' => $text]),
		});
	}

	/**
	 * Returns every localized record of a `mluc` tag.
	 * @param string $signature The four-character tag signature.
	 * @return ?array<string, string> The locale ('enUS') to UTF-8 text map, or null when
	 *   the tag is absent, not `mluc`, or malformed.
	 */
	public function getLocalizedTexts(string $signature): ?array
	{
		$data = $this->getTag($signature);
		return $data === null || substr($data, 0, 4) !== 'mluc' ? null : static::decodeMluc($data);
	}

	/**
	 * Writes a tag as a `mluc` with one record per locale.
	 * @param string $signature The four-character tag signature.
	 * @param array<string, string> $texts The locale ('enUS') to UTF-8 text map.
	 */
	public function setLocalizedTexts(string $signature, array $texts): void
	{
		$this->setTag($signature, static::encodeMluc($texts));
	}

	/**
	 * Sets the profile description (the `desc` tag).
	 * @param string $text The description.
	 */
	public function setDescription(string $text): void
	{
		$this->setTagText('desc', $text);
	}

	/**
	 * Sets the profile copyright (the `cprt` tag).
	 * @param string $text The copyright.
	 */
	public function setCopyright(string $text): void
	{
		$this->setTagText('cprt', $text);
	}

	//
	// ─── Color math ──────────────────────────────────────────────────────────
	//

	/**
	 * Indicates whether the profile converts through a matrix and three tone curves —
	 * the form {@see ICCTransform} can evaluate.
	 * @return bool Whether the profile is an RGB matrix/TRC profile.
	 */
	public function getIsMatrixShaper(): bool
	{
		if ($this->getColorSpace() !== self::SpaceRgb || $this->getConnectionSpace() !== self::SpaceXyz) {
			return false;
		}
		foreach (['rXYZ', 'gXYZ', 'bXYZ', 'rTRC', 'gTRC', 'bTRC'] as $signature) {
			if (!isset($this->_tags[$signature])) {
				return false;
			}
		}
		return $this->getMatrix() !== null && $this->getToneCurves() !== null;
	}

	/**
	 * Indicates whether the profile's conversion lives in multi-dimensional lookup
	 * tables, the usual form for CMYK and printer profiles.  Such a profile is read and
	 * rewritten faithfully, but its tables are not evaluated here.
	 * @return bool Whether the profile carries a lookup-table tag.
	 */
	public function getIsLutBased(): bool
	{
		foreach (self::LutTags as $signature) {
			if (isset($this->_tags[$signature])) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns the media white point from the `wtpt` tag.
	 * @return ?array The [X, Y, Z] tristimulus values, or null when absent.
	 */
	public function getWhitePoint(): ?array
	{
		$content = $this->_tags['wtpt'] ?? null;
		if ($content === null || strlen($content) < 20 || substr($content, 0, 4) !== 'XYZ ') {
			return null;
		}
		return static::readXyzNumber(substr($content, 8, 12));
	}

	/**
	 * Returns the D50-relative colorant matrix: the red, green, and blue primaries as
	 * columns, so that [X, Y, Z] = matrix x [linear R, G, B].
	 * @return ?array The 3x3 matrix as three [X, Y, Z] rows, or null when incomplete.
	 */
	public function getMatrix(): ?array
	{
		$columns = [];
		foreach (['rXYZ', 'gXYZ', 'bXYZ'] as $signature) {
			$content = $this->_tags[$signature] ?? null;
			if ($content === null || strlen($content) < 20 || substr($content, 0, 4) !== 'XYZ ') {
				return null;
			}
			$columns[] = static::readXyzNumber(substr($content, 8, 12));
		}
		return [
			[$columns[0][0], $columns[1][0], $columns[2][0]],
			[$columns[0][1], $columns[1][1], $columns[2][1]],
			[$columns[0][2], $columns[1][2], $columns[2][2]],
		];
	}

	/**
	 * Returns the three tone reproduction curves, red first, each decoded by
	 * {@see decodeCurve()}.
	 * @return ?array The [red, green, blue] curve descriptions, or null when incomplete.
	 */
	public function getToneCurves(): ?array
	{
		$curves = [];
		foreach (['rTRC', 'gTRC', 'bTRC'] as $signature) {
			$curve = $this->decodeCurve($signature);
			if ($curve === null) {
				return null;
			}
			$curves[] = $curve;
		}
		return $curves;
	}

	/**
	 * Decodes a tone curve tag into a description the transform can evaluate: an
	 * `['type' => 'identity']`, `['type' => 'gamma', 'gamma' => float]`,
	 * `['type' => 'table', 'samples' => float[]]` (a sampled `curv`), or
	 * `['type' => 'parametric', 'function' => int, 'parameters' => float[]]` (a `para`).
	 * @param string $signature The four-character tag signature, such as 'rTRC'.
	 * @return ?array The curve description, or null when absent or unsupported.
	 */
	public function decodeCurve(string $signature): ?array
	{
		$content = $this->_tags[$signature] ?? null;
		if ($content === null || strlen($content) < 12) {
			return null;
		}
		if (substr($content, 0, 4) === 'curv') {
			$count = unpack('N', substr($content, 8, 4))[1];
			if ($count === 0) {
				return ['type' => 'identity'];
			}
			if ($count === 1) {
				// One entry is a u8Fixed8 gamma.
				return ['type' => 'gamma', 'gamma' => unpack('n', substr($content, 12, 2))[1] / 256];
			}
			if (strlen($content) < 12 + $count * 2) {
				return null;
			}
			$samples = array_values(unpack('n' . $count, substr($content, 12, $count * 2)));
			return ['type' => 'table', 'samples' => array_map(fn (int $v): float => $v / 65535, $samples)];
		}
		if (substr($content, 0, 4) === 'para') {
			$function = unpack('n', substr($content, 8, 2))[1];
			$expected = self::ParametricParameterCounts[$function] ?? null;
			if ($expected === null || strlen($content) < 12 + $expected * 4) {
				return null;
			}
			$parameters = [];
			for ($i = 0; $i < $expected; $i++) {
				$parameters[] = static::readS15Fixed16(substr($content, 12 + $i * 4, 4));
			}
			return ['type' => 'parametric', 'function' => $function, 'parameters' => $parameters];
		}
		return null;
	}

	/**
	 * Returns the first XYZNumber of an `XYZ ` tag.
	 * @param string $signature The four-character tag signature.
	 * @return ?array The [X, Y, Z] values, or null when absent or of another type.
	 */
	public function getTagXYZ(string $signature): ?array
	{
		return $this->getTagXYZAll($signature)[0] ?? null;
	}

	/**
	 * Returns every XYZNumber of an `XYZ ` tag.
	 * @param string $signature The four-character tag signature.
	 * @return ?array The list of [X, Y, Z] triplets, or null when absent or of another type.
	 */
	public function getTagXYZAll(string $signature): ?array
	{
		$data = $this->getTag(static::padSignature($signature));
		if ($data === null || strlen($data) < 8 || substr($data, 0, 4) !== 'XYZ ') {
			return null;
		}
		$triplets = [];
		for ($i = 0, $count = intdiv(strlen($data) - 8, 12); $i < $count; $i++) {
			$triplets[] = static::readXyzNumber(substr($data, 8 + $i * 12, 12));
		}
		return $triplets;
	}

	/**
	 * Writes an `XYZ ` tag.
	 * @param string $signature The four-character tag signature (e.g. 'wtpt', 'rXYZ').
	 * @param array $xyz One [X, Y, Z] triplet, or a list of triplets.
	 */
	public function setTagXYZ(string $signature, array $xyz): void
	{
		$triplets = $xyz === [] ? [] : (is_array(reset($xyz)) ? $xyz : [$xyz]);
		$data = 'XYZ ' . "\0\0\0\0";
		foreach ($triplets as $triplet) {
			$data .= static::packXyzNumber((array) $triplet);
		}
		$this->setTag($signature, $data);
	}

	/**
	 * Sets the media white point (the `wtpt` tag).
	 * @param array $xyz The [X, Y, Z] tristimulus values.
	 */
	public function setWhitePoint(array $xyz): void
	{
		$this->setTagXYZ('wtpt', $xyz);
	}

	/**
	 * Writes the D50-relative colorant matrix as the `rXYZ`, `gXYZ`, and `bXYZ` tags,
	 * the inverse of {@see getMatrix()}.
	 * @param array $matrix The 3x3 matrix as three [X, Y, Z] rows.
	 */
	public function setMatrix(array $matrix): void
	{
		foreach (['rXYZ', 'gXYZ', 'bXYZ'] as $column => $signature) {
			$this->setTagXYZ($signature, [$matrix[0][$column], $matrix[1][$column], $matrix[2][$column]]);
		}
	}

	/**
	 * Writes a `curv` tag: a float is a single u8Fixed8 gamma, an empty array the
	 * identity curve, and a list of values a sampled curve of 16-bit points.
	 * @param string $signature The four-character tag signature (e.g. 'rTRC').
	 * @param array|float $curve The gamma, the identity, or the sampled points (0 to 1).
	 */
	public function setTagCurve(string $signature, array|float $curve): void
	{
		if (is_float($curve)) {
			$data = 'curv' . "\0\0\0\0" . pack('Nn', 1, max(0, min(0xFFFF, (int) round($curve * 256))));
		} elseif ($curve === []) {
			$data = 'curv' . "\0\0\0\0" . pack('N', 0);
		} else {
			$values = array_map(
				static fn ($v): int => max(0, min(0xFFFF, (int) round((float) $v * 65535))),
				array_values($curve),
			);
			$data = 'curv' . "\0\0\0\0" . pack('N', count($values)) . pack('n*', ...$values);
		}
		$this->setTag($signature, $data);
	}

	/**
	 * Writes a `para` (parametricCurveType) tag.
	 * @param string $signature The four-character tag signature (e.g. 'rTRC').
	 * @param int $function The function type, 0 to 4.
	 * @param array $parameters The parameters, in specification order.
	 * @throws \RuntimeException When the function type is unknown or the
	 *   parameter count does not match it.
	 */
	public function setTagParametricCurve(string $signature, int $function, array $parameters): void
	{
		$expected = self::ParametricParameterCounts[$function] ?? null;
		if ($expected === null || count($parameters) !== $expected) {
			throw new \InvalidArgumentException(sprintf('ICC parametric curve function \'%s\' takes %s parameters, not %s.', $function, $expected ?? '1, 3, 4, 5, or 7', count($parameters)));
		}
		$data = 'para' . "\0\0\0\0" . pack('nn', $function, 0);
		foreach (array_values($parameters) as $parameter) {
			$data .= static::packS15Fixed16((float) $parameter);
		}
		$this->setTag($signature, $data);
	}

	/**
	 * Writes one tone curve into all three of the `rTRC`, `gTRC`, and `bTRC` tags — the
	 * common case, and the inverse of {@see getToneCurves()} for a profile whose channels
	 * agree.  The three share a single data element, as a real profile's do.
	 *
	 * A curve that differs per channel is written with {@see setTagCurve()} directly: a
	 * list of three values here would be indistinguishable from one three-point sampled
	 * curve, so this method does not guess.
	 * @param array|float $curve The gamma, the identity (an empty array), or the sampled
	 *   points of the curve every channel uses.
	 */
	public function setToneCurves(array|float $curve): void
	{
		$this->setTagCurve('rTRC', $curve);
		$this->aliasTag('gTRC', 'rTRC');
		$this->aliasTag('bTRC', 'rTRC');
	}

	/**
	 * Returns an `sf32` (s15Fixed16ArrayType) tag's values, such as the `chad`
	 * chromatic-adaptation matrix.
	 * @param string $signature The four-character tag signature.
	 * @return ?array The values, or null when absent or of another type.
	 */
	public function getTagS15Fixed16Array(string $signature): ?array
	{
		$data = $this->getTag(static::padSignature($signature));
		if ($data === null || strlen($data) < 8 || substr($data, 0, 4) !== 'sf32') {
			return null;
		}
		$values = [];
		for ($i = 0, $count = intdiv(strlen($data) - 8, 4); $i < $count; $i++) {
			$values[] = static::readS15Fixed16(substr($data, 8 + $i * 4, 4));
		}
		return $values;
	}

	/**
	 * Writes an `sf32` (s15Fixed16ArrayType) tag.
	 * @param string $signature The four-character tag signature.
	 * @param array $values The values.
	 */
	public function setTagS15Fixed16Array(string $signature, array $values): void
	{
		$data = 'sf32' . "\0\0\0\0";
		foreach (array_values($values) as $value) {
			$data .= static::packS15Fixed16((float) $value);
		}
		$this->setTag($signature, $data);
	}

	/**
	 * Pads a signature to the four characters every ICC signature field holds.
	 * @param string $signature The signature.
	 * @throws \InvalidArgumentException When the signature is longer than four characters.
	 * @return string The four-character signature.
	 */
	protected static function padSignature(string $signature): string
	{
		if (strlen($signature) > 4) {
			throw new \InvalidArgumentException(sprintf('\'%s\' is not a four-character ICC tag signature.', $signature));
		}
		return str_pad($signature, 4, ' ');
	}

	/**
	 * Packs an XYZNumber: three s15Fixed16 values, missing components read as zero.
	 * @param array $xyz The [X, Y, Z] values.
	 * @return string The twelve bytes.
	 */
	protected static function packXyzNumber(array $xyz): string
	{
		$values = array_values($xyz);
		return static::packS15Fixed16((float) ($values[0] ?? 0))
			. static::packS15Fixed16((float) ($values[1] ?? 0))
			. static::packS15Fixed16((float) ($values[2] ?? 0));
	}

	/**
	 * Packs a signed 15.16 fixed-point number.
	 * @param float $value The value.
	 * @return string The four bytes.
	 */
	protected static function packS15Fixed16(float $value): string
	{
		return pack('N', ((int) round($value * 65536)) & 0xFFFFFFFF);
	}

	/**
	 * Decodes a `mluc` element's records.
	 * @param string $data The tag content.
	 * @return ?array The locale to UTF-8 text map, or null when malformed.
	 */
	protected static function decodeMluc(string $data): ?array
	{
		if (strlen($data) < 16) {
			return null;
		}
		$count = unpack('N', substr($data, 8, 4))[1];
		$recordSize = unpack('N', substr($data, 12, 4))[1];
		if ($recordSize < 12 || strlen($data) < 16 + $count * $recordSize) {
			return null;
		}
		$records = [];
		for ($i = 0; $i < $count; $i++) {
			$record = 16 + $i * $recordSize;
			$length = unpack('N', substr($data, $record + 4, 4))[1];
			$offset = unpack('N', substr($data, $record + 8, 4))[1];
			if ($offset + $length > strlen($data)) {
				return null;
			}
			$records[substr($data, $record, 4)] = static::utf16BeToUtf8(substr($data, $offset, $length));
		}
		return $records;
	}

	/**
	 * Encodes a `mluc` element.
	 * @param array $texts The locale ('enUS') to UTF-8 text map.
	 * @return string The tag content.
	 */
	protected static function encodeMluc(array $texts): string
	{
		$records = '';
		$strings = '';
		$offset = 16 + count($texts) * 12;
		foreach ($texts as $locale => $text) {
			$encoded = static::utf8ToUtf16Be((string) $text);
			$records .= substr(str_pad((string) $locale, 4, ' '), 0, 4)
				. pack('N2', strlen($encoded), $offset + strlen($strings));
			$strings .= $encoded;
		}
		return 'mluc' . "\0\0\0\0" . pack('N2', count($texts), 12) . $records . $strings;
	}

	/**
	 * Encodes a version 2 `desc` (textDescriptionType) element, whose Unicode and
	 * ScriptCode parts are left empty.
	 * @param string $text The description; its 7-bit ASCII projection is stored.
	 * @return string The tag content.
	 */
	protected static function encodeTextDescription(string $text): string
	{
		// One '?' per character rather than per byte, with the byte-wise pattern as the
		// fallback for text that is not valid UTF-8.
		$ascii = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/u', '?', $text)
			?? preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $text)
			?? '';
		return 'desc' . "\0\0\0\0"
			. pack('N', strlen($ascii) + 1) . $ascii . "\0"
			. pack('N2', 0, 0)
			. pack('nC', 0, 0) . str_repeat("\0", 67);
	}

	/**
	 * Decodes UTF-16BE bytes to UTF-8 without depending on an extension, mapping broken
	 * surrogates to the replacement character.
	 * @param string $bytes The UTF-16BE bytes.
	 * @return string The UTF-8 text.
	 */
	protected static function utf16BeToUtf8(string $bytes): string
	{
		$out = '';
		$length = strlen($bytes) & ~1;
		for ($i = 0; $i < $length; $i += 2) {
			$unit = (ord($bytes[$i]) << 8) | ord($bytes[$i + 1]);
			if ($unit >= 0xD800 && $unit <= 0xDBFF && $i + 3 < $length) {
				$low = (ord($bytes[$i + 2]) << 8) | ord($bytes[$i + 3]);
				if ($low >= 0xDC00 && $low <= 0xDFFF) {
					$unit = 0x10000 + (($unit - 0xD800) << 10) + ($low - 0xDC00);
					$i += 2;
				} else {
					$unit = 0xFFFD;
				}
			} elseif ($unit >= 0xD800 && $unit <= 0xDFFF) {
				$unit = 0xFFFD;
			}
			if ($unit < 0x80) {
				$out .= chr($unit);
			} elseif ($unit < 0x800) {
				$out .= chr(0xC0 | ($unit >> 6)) . chr(0x80 | ($unit & 0x3F));
			} elseif ($unit < 0x10000) {
				$out .= chr(0xE0 | ($unit >> 12)) . chr(0x80 | (($unit >> 6) & 0x3F)) . chr(0x80 | ($unit & 0x3F));
			} else {
				$out .= chr(0xF0 | ($unit >> 18)) . chr(0x80 | (($unit >> 12) & 0x3F))
					. chr(0x80 | (($unit >> 6) & 0x3F)) . chr(0x80 | ($unit & 0x3F));
			}
		}
		return $out;
	}

	/**
	 * Encodes UTF-8 text as UTF-16BE bytes without depending on an extension, mapping
	 * malformed sequences to the replacement character.
	 * @param string $text The UTF-8 text.
	 * @return string The UTF-16BE bytes.
	 */
	protected static function utf8ToUtf16Be(string $text): string
	{
		$out = '';
		$length = strlen($text);
		for ($i = 0; $i < $length; $i++) {
			$byte = ord($text[$i]);
			if ($byte < 0x80) {
				$code = $byte;
				$extra = 0;
			} elseif (($byte & 0xE0) === 0xC0) {
				$code = $byte & 0x1F;
				$extra = 1;
			} elseif (($byte & 0xF0) === 0xE0) {
				$code = $byte & 0x0F;
				$extra = 2;
			} elseif (($byte & 0xF8) === 0xF0) {
				$code = $byte & 0x07;
				$extra = 3;
			} else {
				$code = 0xFFFD;
				$extra = 0;
			}
			for ($j = 0; $j < $extra; $j++) {
				if ($i + 1 >= $length || (ord($text[$i + 1]) & 0xC0) !== 0x80) {
					$code = 0xFFFD;
					break;
				}
				$code = ($code << 6) | (ord($text[++$i]) & 0x3F);
			}
			if ($code > 0x10FFFF || ($code >= 0xD800 && $code <= 0xDFFF)) {
				$code = 0xFFFD;
			}
			if ($code < 0x10000) {
				$out .= chr($code >> 8) . chr($code & 0xFF);
			} else {
				$code -= 0x10000;
				$high = 0xD800 + ($code >> 10);
				$low = 0xDC00 + ($code & 0x3FF);
				$out .= chr($high >> 8) . chr($high & 0xFF) . chr($low >> 8) . chr($low & 0xFF);
			}
		}
		return $out;
	}

	/**
	 * Reads an XYZNumber: three s15Fixed16 values.
	 * @param string $bytes The twelve bytes.
	 * @return array The [X, Y, Z] values.
	 */
	protected static function readXyzNumber(string $bytes): array
	{
		return [
			static::readS15Fixed16(substr($bytes, 0, 4)),
			static::readS15Fixed16(substr($bytes, 4, 4)),
			static::readS15Fixed16(substr($bytes, 8, 4)),
		];
	}

	/**
	 * Reads a signed 15.16 fixed-point number.
	 * @param string $bytes The four bytes.
	 * @return float The value.
	 */
	protected static function readS15Fixed16(string $bytes): float
	{
		$value = unpack('N', $bytes)[1];
		if ($value >= 0x80000000) {
			$value -= 0x100000000;
		}
		return $value / 65536;
	}
}
