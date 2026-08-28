<?php

/**
 * Makernote class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\Meta\Makernote;

use Belisoful\Image\Meta\EXIF;
use Belisoful\Image\TIFF\TIFFDocument;
use Belisoful\Image\TIFF\TIFFIfd;

/**
 * Makernote class.
 *
 * Decodes the camera-maker makernote (EXIF tag 37500) of thirteen makers, driven by
 * the container facts in {@see MakernoteTags::Headers}: header signatures select the
 * maker variant, and the variant dictates the IFD offset (or Fujifilm's embedded
 * offset pointer), a forced byte order (Casio Type 2 and Ricoh are always big-endian,
 * Fujifilm always little-endian), makernote-relative value addressing, a missing
 * next-IFD pointer (Panasonic, Sony, Kyocera), Nikon Type 3's embedded TIFF header,
 * and the nested Ricoh camera-info sub-IFD.  A recognized-but-undecodable variant
 * (the legacy Minolta signatures, Panasonic `MKED`, an all-zero Ricoh note) is still
 * identified — {@see getIsDecoded()} is simply false.
 *
 * {@see fromExif()} detects and decodes in one step (matching the camera
 * {@see getMaker() make} against the registry, with the maker-independent `FUJIFILM`
 * signature fallback the Nikon Coolpix 775 needs); {@see registerMakerClass()} lets an
 * application supply its own subclass for a maker.  A decoded note answers
 * {@see getIfd()} (plus {@see getSubIfds()}), tag text through {@see getTagText()} /
 * {@see getValues()} using the {@see MakernoteTags} knowledge base, the embedded
 * {@see getThumbnail() thumbnail} of the makers that carry one (Casio, Olympus,
 * Minolta — repairing the stripped leading 0xFF byte), and {@see getText()} for the
 * Ricoh plain-text form.
 *
 * Makernotes are decoded read-only; on an EXIF rewrite the note's bytes are preserved
 * verbatim at their original offset (see {@see \Belisoful\Image\Meta\EXIF}), so the
 * internal absolute pointers this class decodes remain valid.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class Makernote
{
	/** @var array<string, string> The maker => subclass registry for maker-specific decoding. */
	private static array $_makerClasses = [];

	/** @var string The maker name (a {@see MakernoteTags::Headers} key). */
	private string $_maker = '';

	/** @var string The format variant name (e.g. 'Nikon Type 3'). */
	private string $_variant = '';

	/** @var ?string The tag group interpreting the IFD, or null when undecodable. */
	private ?string $_tagGroup = null;

	/** @var ?TIFFIfd The decoded makernote IFD, or null. */
	private ?TIFFIfd $_ifd = null;

	/** @var array<string, TIFFIfd> The decoded nested sub-IFDs, keyed by tag group. */
	private array $_subIfds = [];

	/** @var ?string The plain-text makernote body (Ricoh text form), or null. */
	private ?string $_text = null;

	/** @var string The raw makernote bytes. */
	private string $_note = '';

	/** @var string[] The parse anomalies. */
	private array $_warnings = [];

	/**
	 * Registers (or unregisters, when null) the subclass decoding a maker.
	 * @param string $maker The maker name (a {@see MakernoteTags::Headers} key).
	 * @param ?string $class The {@see Makernote} subclass, or null to restore the default.
	 */
	public static function registerMakerClass(string $maker, ?string $class): void
	{
		if ($class === null) {
			unset(self::$_makerClasses[$maker]);
		} else {
			self::$_makerClasses[$maker] = $class;
		}
	}

	/**
	 * Returns the subclass decoding a maker.
	 * @param string $maker The maker name.
	 * @return string The {@see Makernote} class to instantiate.
	 */
	protected static function makerClass(string $maker): string
	{
		return self::$_makerClasses[$maker] ?? match ($maker) {
			'Canon' => CanonMakernote::class,
			'Konica Minolta' => KonicaMinoltaMakernote::class,
			default => self::class,
		};
	}

	/**
	 * Detects and decodes the makernote of an EXIF block.
	 * @param EXIF $exif The EXIF metadata.
	 * @return ?self The decoded makernote, or null when absent or unrecognized.
	 */
	public static function fromExif(EXIF $exif): ?self
	{
		$tag = $exif->getExifIfd()?->getTag(EXIF::MakerNoteTag);
		$note = $exif->getMakernoteData();
		if ($tag === null || $note === null || $note === '') {
			return null;
		}
		return static::fromNote(
			$note,
			$exif->getMake() ?? '',
			$exif->getRawTiff() ?? '',
			$tag->getOffset() ?? 0,
			$exif->getTiff()->getIsBigEndian(),
		);
	}

	/**
	 * Detects and decodes a raw makernote block.
	 * @param string $note The makernote bytes.
	 * @param string $make The camera make (IFD0 tag 271), guiding maker detection.
	 * @param string $tiffBytes The surrounding TIFF bytes ('' when unavailable; offsets
	 *   are then resolved within the note itself).
	 * @param int $noteOffset The note's offset within the TIFF bytes.
	 * @param bool $bigEndian The TIFF byte order.
	 * @return ?self The decoded makernote, or null when unrecognized.
	 */
	public static function fromNote(string $note, string $make, string $tiffBytes = '', int $noteOffset = 0, bool $bigEndian = true): ?self
	{
		[$maker, $variantName, $variant] = static::detect($make, $note);
		if ($maker === null) {
			return null;
		}
		$class = static::makerClass($maker);
		/** @var self $parsed */
		$parsed = new $class();
		$parsed->_maker = $maker;
		$parsed->_variant = $variantName;
		$parsed->_note = $note;
		$parsed->_tagGroup = $variant['tagGroup'] ?? null;
		$parsed->parseNote($variant, $note, $tiffBytes, $noteOffset, $bigEndian);
		return $parsed;
	}

	/**
	 * Matches a makernote against the maker registry.
	 * @param string $make The camera make.
	 * @param string $note The makernote bytes.
	 * @return array The [maker, variantName, variant] triple, or [null, '', []].
	 */
	protected static function detect(string $make, string $note): array
	{
		// The Fujifilm signature wins regardless of make (Nikon Coolpix 775).
		if (str_starts_with($note, 'FUJIFILM')) {
			return ['Fujifilm', 'Fujifilm', MakernoteTags::Headers['Fujifilm']['variants']['Fujifilm']];
		}
		foreach (MakernoteTags::Headers as $maker => $info) {
			$matched = false;
			foreach ($info['makeMatch'] as $needle) {
				if (stripos($make, $needle) !== false) {
					$matched = true;
					break;
				}
			}
			if (!$matched) {
				continue;
			}
			foreach ($info['undecodableSignatures'] ?? [] as $signature) {
				if (str_starts_with($note, $signature)) {
					return [$maker, "$maker (undecoded $signature)", ['tagGroup' => null]];
				}
			}
			foreach ($info['variants'] as $variantName => $variant) {
				if (static::variantMatches($variant, $note)) {
					return [$maker, $variantName, $variant];
				}
			}
		}
		return [null, '', []];
	}

	/**
	 * Indicates whether a variant's signature matches a note.  A signature-less variant
	 * matches any note when it is decodable, and only an all-zero note otherwise.
	 * @param array $variant The variant facts.
	 * @param string $note The makernote bytes.
	 * @return bool Whether the variant applies.
	 */
	protected static function variantMatches(array $variant, string $note): bool
	{
		foreach ($variant['signatures'] as $signature) {
			if (str_starts_with($note, $signature)) {
				return true;
			}
		}
		if ($variant['signatures'] !== []) {
			return false;
		}
		if (($variant['tagGroup'] ?? null) === null) {
			return trim($note, "\0") === '';
		}
		return true;
	}

	/**
	 * Decodes the note by its variant facts.  Subclasses extend for maker-specific
	 * post-processing; call the parent first.
	 * @param array $variant The variant facts.
	 * @param string $note The makernote bytes.
	 * @param string $tiffBytes The surrounding TIFF bytes ('' when unavailable).
	 * @param int $noteOffset The note's offset within the TIFF bytes.
	 * @param bool $bigEndian The TIFF byte order.
	 */
	protected function parseNote(array $variant, string $note, string $tiffBytes, int $noteOffset, bool $bigEndian): void
	{
		if (($variant['tagGroup'] ?? null) === null) {
			if (($variant['note'] ?? '') === 'Plain text makernote; fields separated by semicolons'
				|| str_starts_with($note, 'Rv') || str_starts_with($note, 'Rev')) {
				$this->_text = rtrim($note, "\0");
			}
			return;
		}

		$order = match ($variant['byteOrder'] ?? null) {
			'MM' => true,
			'II' => false,
			default => $bigEndian,
		};
		$reader = new TIFFDocument();
		$reader->setIsBigEndian($order);

		if ($variant['embeddedTiffHeader'] ?? false) {
			// Nikon Type 3: a complete TIFF structure follows the signature.
			try {
				$inner = TIFFDocument::fromString(substr($note, $variant['signatureLength']), []);
				$inner->setIsBigEndian($inner->getIsBigEndian());
				$this->_ifd = $inner->getIfd(0);
				$this->_warnings = array_merge($this->_warnings, $inner->getWarnings());
			} catch (\RuntimeException $e) {
				$this->_warnings[] = 'embedded TIFF header did not parse: ' . $e->getMessage();
			}
			return;
		}

		$ifdOffset = $variant['ifdOffset'] ?? null;
		if (isset($variant['ifdOffsetPointer'])) {
			// Fujifilm stores the IFD offset little-endian at a fixed note position.
			$pointer = $variant['ifdOffsetPointer'];
			$ifdOffset = unpack('V', substr($note, $pointer['offset'], 4))[1] ?? null;
		}
		if ($ifdOffset === null) {
			return;
		}

		$relative = ($variant['localOffsets'] ?? false) || ($variant['offsetsRelativeToMakernote'] ?? false);
		if ($relative || $tiffBytes === '') {
			// Offsets resolve within the note itself.
			[$this->_ifd] = $reader->readIfdAt($note, $ifdOffset, 0, $variant['hasNextIfdPointer'] ?? true);
		} else {
			// Standard makernote offsets are absolute within the whole TIFF block.
			[$this->_ifd] = $reader->readIfdAt($tiffBytes, $noteOffset + $ifdOffset, 0, $variant['hasNextIfdPointer'] ?? true);
		}
		$this->_warnings = array_merge($this->_warnings, $reader->getWarnings());

		if (isset($variant['subIfd']) && $this->_ifd !== null) {
			$this->parseSubIfd($variant['subIfd'], $tiffBytes !== '' && !$relative ? $tiffBytes : $note);
		}
	}

	/**
	 * Decodes a nested signature-prefixed sub-IFD (the Ricoh camera-info block).
	 * @param array $spec The sub-IFD facts (tag, signatures, ifdOffset, byteOrder, ...).
	 * @param string $bytes The bytes the parent IFD's offsets address.
	 */
	protected function parseSubIfd(array $spec, string $bytes): void
	{
		$tag = $this->_ifd->getTag($spec['tag']);
		if ($tag === null || $tag->getOffset() === null) {
			return;
		}
		$block = substr($bytes, $tag->getOffset(), $tag->getCount());
		foreach ($spec['signatures'] as $signature) {
			if (str_starts_with($block, $signature)) {
				$reader = new TIFFDocument();
				$reader->setIsBigEndian(($spec['byteOrder'] ?? 'MM') === 'MM');
				[$sub] = $reader->readIfdAt($block, $spec['ifdOffset'], 0, $spec['hasNextIfdPointer'] ?? false);
				if ($sub !== null) {
					$this->_subIfds[$spec['tagGroup']] = $sub;
				}
				return;
			}
		}
	}

	/**
	 * Returns the maker name.
	 * @return string The maker (e.g. 'Canon', 'Konica Minolta').
	 */
	public function getMaker(): string
	{
		return $this->_maker;
	}

	/**
	 * Returns the format variant name.
	 * @return string The variant (e.g. 'Nikon Type 3', 'Casio Type 1').
	 */
	public function getVariant(): string
	{
		return $this->_variant;
	}

	/**
	 * Returns the tag group interpreting the IFD.
	 * @return ?string The {@see MakernoteTags} group, or null when undecodable.
	 */
	public function getTagGroup(): ?string
	{
		return $this->_tagGroup;
	}

	/**
	 * Indicates whether the note decoded to an IFD or text (rather than being merely
	 * recognized).
	 * @return bool Whether decoded content is available.
	 */
	public function getIsDecoded(): bool
	{
		return $this->_ifd !== null || $this->_text !== null;
	}

	/**
	 * Returns the decoded makernote IFD.
	 * @return ?TIFFIfd The IFD, or null.
	 */
	public function getIfd(): ?TIFFIfd
	{
		return $this->_ifd;
	}

	/**
	 * Returns the decoded nested sub-IFDs, keyed by their tag group.
	 * @return array<string, TIFFIfd> The sub-IFDs.
	 */
	public function getSubIfds(): array
	{
		return $this->_subIfds;
	}

	/**
	 * Returns the plain-text makernote body (the Ricoh text form).
	 * @return ?string The text, or null.
	 */
	public function getText(): ?string
	{
		return $this->_text;
	}

	/**
	 * Returns the raw makernote bytes.
	 * @return string The note bytes.
	 */
	public function getNote(): string
	{
		return $this->_note;
	}

	/**
	 * Returns the parse anomalies.
	 * @return string[] The warnings.
	 */
	public function getWarnings(): array
	{
		return $this->_warnings;
	}

	/**
	 * Returns the human-readable text of a makernote tag.
	 * @param int $id The tag id.
	 * @return ?string The interpreted text, or null when absent.
	 */
	public function getTagText(int $id): ?string
	{
		$tag = $this->_ifd?->getTag($id);
		if ($tag === null || $this->_tagGroup === null) {
			return null;
		}
		return MakernoteTags::textValue($tag, $this->_tagGroup);
	}

	/**
	 * Returns every decoded tag as name => interpreted text (unknown tags keyed
	 * 'Tag 0xNNNN').
	 * @return array<string, ?string> The name-to-text map.
	 */
	public function getValues(): array
	{
		$values = [];
		if ($this->_ifd === null || $this->_tagGroup === null) {
			return $values;
		}
		foreach ($this->_ifd->getTags() as $id => $tag) {
			$name = MakernoteTags::nameOf($this->_tagGroup, $id) ?? sprintf('Tag 0x%04X', $id);
			$values[$name] = MakernoteTags::textValue($tag, $this->_tagGroup);
		}
		return $values;
	}

	/**
	 * Extracts the embedded thumbnail of the makers that carry one (Casio tags
	 * 0x0004/0x2000, Olympus/Minolta tags 0x0081/0x0088), repairing the stripped
	 * leading 0xFF byte some cameras write.
	 * @return ?string The thumbnail JPEG bytes, or null when absent.
	 */
	public function getThumbnail(): ?string
	{
		$tags = null;
		foreach (MakernoteTags::Headers[$this->_maker]['variants'] ?? [] as $variant) {
			if (isset($variant['thumbnailTags'])) {
				$tags = $variant['thumbnailTags'];
				break;
			}
		}
		if ($tags === null || $this->_ifd === null) {
			return null;
		}
		foreach ($tags as $id) {
			$tag = $this->_ifd->getTag($id);
			if ($tag === null) {
				continue;
			}
			$data = $tag->getValues();
			$data = is_array($data) ? implode('', array_map('chr', $data)) : (string) $data;
			if (strlen($data) < 4) {
				continue;
			}
			if ($data[0] !== "\xFF" && $data[1] === "\xD8") {
				$data[0] = "\xFF";   // repair the maker-stripped SOI first byte
			}
			if (str_starts_with($data, "\xFF\xD8")) {
				return $data;
			}
		}
		return null;
	}
}
