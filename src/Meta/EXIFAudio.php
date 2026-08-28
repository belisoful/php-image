<?php

/**
 * EXIFAudio class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\Meta;

use Belisoful\Image\ImageChunk;
use Belisoful\Image\RIFFContainer;
use Belisoful\Image\StreamIOTrait;

/**
 * EXIFAudio class.
 *
 * An Exif audio file: the WAVE form audio file of the Exif standard, whose
 * Exif-specific attributes live in a `LIST` chunk of the dedicated `exif` list type so
 * the file stays a plain WAVE for any other reader.  The camera that records a voice
 * memo beside a photograph writes this, and {@see getRelatedImage() erel} carries the
 * name of the image it belongs to — the audio half of the
 * {@see EXIF::getValueByName() RelatedSoundFile} link.
 *
 * The attribute chunks are the spec's seven: the mandatory {@see getVersion() ever}
 * version, {@see getRelatedImage() erel}, the {@see getRecordingTime() etim} start of
 * recording, {@see getManufacturer() ecor}, {@see getModel() emdl}, the
 * {@see getMakerNote() emnt} maker block, and the {@see getUserComment() eucm} user
 * comment with its eight-byte character-code prefix.  Editing any of them and calling
 * {@see toBinary()} rewrites only the `exif` list: the `fmt ` and `data` chunks — the
 * audio itself — are carried through untouched.
 *
 * ```php
 * $audio = EXIFAudio::fromFile('SND00001.WAV');
 * $audio->getRelatedImage();                       // 'DSC00001.JPG'
 * $audio->getDurationSeconds();                    // 4.5
 * $audio->setUserComment('Voice memo at the summit');
 * $audio->save('SND00001.WAV');
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class EXIFAudio
{
	use StreamIOTrait;

	/** The RIFF form type of a WAVE audio file. */
	public const FormType = 'WAVE';

	/** The list type of the Exif attribute list. */
	public const ExifListType = 'exif';

	/** The version chunk (four ASCII digits, no NUL); mandatory. */
	public const VersionChunk = 'ever';

	/** The related image file chunk (NUL-terminated ASCII). */
	public const RelatedImageChunk = 'erel';

	/** The recording-time chunk (NUL-terminated `hh:mm:ss.sub` ASCII). */
	public const TimeChunk = 'etim';

	/** The manufacturer chunk (NUL-terminated ASCII). */
	public const ManufacturerChunk = 'ecor';

	/** The model chunk (NUL-terminated ASCII). */
	public const ModelChunk = 'emdl';

	/** The maker-note chunk (opaque bytes). */
	public const MakerNoteChunk = 'emnt';

	/** The user-comment chunk (eight-byte character code then the comment). */
	public const UserCommentChunk = 'eucm';

	/** @var RIFFContainer The underlying RIFF container. */
	private RIFFContainer $_riff;

	/** @var array<string, string> The Exif list sub-chunks, keyed by id, in order. */
	private array $_attributes = [];

	/**
	 * Constructs an Exif audio over a RIFF container (an empty WAVE by default).
	 * @param ?RIFFContainer $riff The container, or null to start a new WAVE.
	 */
	final public function __construct(?RIFFContainer $riff = null)
	{
		if ($riff === null) {
			$riff = new RIFFContainer();
			$riff->setFormType(self::FormType);
		}
		$this->_riff = $riff;
		$this->readAttributes();
	}

	/**
	 * Indicates whether bytes are a WAVE audio file.
	 * @param string $bytes The candidate bytes.
	 * @return bool Whether the RIFF form type is WAVE.
	 */
	public static function isWave(string $bytes): bool
	{
		return strlen($bytes) >= 12 && strncmp($bytes, 'RIFF', 4) === 0 && substr($bytes, 8, 4) === self::FormType;
	}

	/**
	 * Indicates whether bytes are a WAVE file carrying the Exif attribute list.
	 * @param string $bytes The candidate bytes.
	 * @return bool Whether an Exif audio file.
	 */
	public static function isExifAudio(string $bytes): bool
	{
		if (!self::isWave($bytes)) {
			return false;
		}
		$audio = static::fromString($bytes);
		return $audio->getHasExifList();
	}

	/**
	 * Parses an Exif audio file from bytes.
	 * @param string $bytes The WAVE bytes.
	 * @throws \UnexpectedValueException When the bytes are not a WAVE RIFF container.
	 * @return static The parsed audio.
	 */
	public static function fromString(string $bytes): static
	{
		$riff = RIFFContainer::fromString($bytes);
		if ($riff->getFormType() !== self::FormType) {
			throw new \UnexpectedValueException(sprintf('An Exif audio file must be a RIFF WAVE container; the form type is \'%s\'.', $riff->getFormType()));
		}
		return new static($riff);
	}

	/**
	 * Parses an Exif audio file from a PSR-7 stream or stream resource.
	 * @param mixed $stream The {@see \Psr\Http\Message\StreamInterface} or PHP stream resource.
	 * @return static The parsed audio.
	 */
	public static function fromStream(mixed $stream): static
	{
		return static::fromString(static::sourceBytes($stream));
	}

	/**
	 * Parses an Exif audio file from disk.
	 * @param string $path The file path.
	 * @throws \RuntimeException When the file cannot be read or is not a WAVE.
	 * @return static The parsed audio.
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
	 * Reads the Exif list's sub-chunks from the container.
	 */
	protected function readAttributes(): void
	{
		$this->_attributes = [];
		$list = $this->findExifList();
		if ($list === null) {
			return;
		}
		$data = $list->getData();
		$len = strlen($data);
		$pos = 4;   // past the 'exif' list type
		while ($pos + 8 <= $len) {
			$id = substr($data, $pos, 4);
			$size = unpack('V', substr($data, $pos + 4, 4))[1];
			$this->_attributes[$id] = substr($data, $pos + 8, $size);
			$pos += 8 + $size + ($size & 1);
		}
	}

	/**
	 * Returns the LIST chunk whose list type is `exif`.
	 * @return ?ImageChunk The chunk, or null when absent.
	 */
	protected function findExifList(): ?ImageChunk
	{
		foreach ($this->_riff->getChunks() as $chunk) {
			if ($chunk->getType() === 'LIST' && str_starts_with($chunk->getData(), self::ExifListType)) {
				return $chunk;
			}
		}
		return null;
	}

	/**
	 * Returns the underlying RIFF container.
	 * @return RIFFContainer The container.
	 */
	public function getRiff(): RIFFContainer
	{
		return $this->_riff;
	}

	/**
	 * Indicates whether the file carries the Exif attribute list.
	 * @return bool Whether an `exif` LIST chunk is present.
	 */
	public function getHasExifList(): bool
	{
		return $this->findExifList() !== null;
	}

	/**
	 * Returns every Exif attribute chunk, keyed by id.
	 * @return array<string, string> The raw chunk payloads.
	 */
	public function getAttributes(): array
	{
		return $this->_attributes;
	}

	/**
	 * Returns one Exif attribute chunk's raw payload.
	 * @param string $id The four-character chunk id.
	 * @return ?string The payload, or null when absent.
	 */
	public function getAttribute(string $id): ?string
	{
		return $this->_attributes[$id] ?? null;
	}

	/**
	 * Sets (or removes, when null) one Exif attribute chunk's raw payload.
	 * @param string $id The four-character chunk id.
	 * @param ?string $value The payload, or null to remove the chunk.
	 */
	public function setAttribute(string $id, ?string $value): void
	{
		if ($value === null) {
			unset($this->_attributes[$id]);
		} else {
			$this->_attributes[$id] = $value;
		}
	}

	/**
	 * Returns a NUL-terminated ASCII attribute's text.
	 * @param string $id The chunk id.
	 * @return ?string The text, or null when absent.
	 */
	protected function getTextAttribute(string $id): ?string
	{
		$value = $this->_attributes[$id] ?? null;
		return $value === null ? null : rtrim($value, "\0");
	}

	/**
	 * Sets (or removes, when null) a NUL-terminated ASCII attribute.
	 * @param string $id The chunk id.
	 * @param ?string $value The text, or null to remove the chunk.
	 */
	protected function setTextAttribute(string $id, ?string $value): void
	{
		$this->setAttribute($id, $value === null ? null : rtrim($value, "\0") . "\0");
	}

	/**
	 * Returns the Exif audio version (e.g. '0300' for version 3.0).
	 * @return ?string The four-digit version, or null when absent.
	 */
	public function getVersion(): ?string
	{
		return $this->_attributes[self::VersionChunk] ?? null;
	}

	/**
	 * Sets the Exif audio version, recorded as four ASCII digits without termination.
	 * @param string $value The version (e.g. '0300').
	 */
	public function setVersion(string $value): void
	{
		$this->setAttribute(self::VersionChunk, substr(str_pad($value, 4, '0', STR_PAD_LEFT), 0, 4));
	}

	/**
	 * Returns the related Exif image file name (8.3, no path).
	 * @return ?string The file name, or null when absent.
	 */
	public function getRelatedImage(): ?string
	{
		return $this->getTextAttribute(self::RelatedImageChunk);
	}

	/**
	 * Sets (or removes, when null) the related Exif image file name.
	 * @param ?string $value The 8.3 file name, or null.
	 */
	public function setRelatedImage(?string $value): void
	{
		$this->setTextAttribute(self::RelatedImageChunk, $value);
	}

	/**
	 * Returns the time the recording started, as the spec's `hh:mm:ss.sub` text.
	 * @return ?string The time, or null when absent.
	 */
	public function getRecordingTime(): ?string
	{
		return $this->getTextAttribute(self::TimeChunk);
	}

	/**
	 * Sets (or removes, when null) the time the recording started.
	 * @param null|\DateTimeInterface|string $value The `hh:mm:ss.sub` text, a
	 *   {@see \DateTimeInterface} to format, or null to remove the chunk.
	 */
	public function setRecordingTime(null|string|\DateTimeInterface $value): void
	{
		if ($value instanceof \DateTimeInterface) {
			$value = $value->format('H:i:s.v');
		}
		$this->setTextAttribute(self::TimeChunk, $value);
	}

	/**
	 * Returns the recording equipment's manufacturer.
	 * @return ?string The manufacturer, or null when absent.
	 */
	public function getManufacturer(): ?string
	{
		return $this->getTextAttribute(self::ManufacturerChunk);
	}

	/**
	 * Sets (or removes, when null) the recording equipment's manufacturer.
	 * @param ?string $value The manufacturer, or null.
	 */
	public function setManufacturer(?string $value): void
	{
		$this->setTextAttribute(self::ManufacturerChunk, $value);
	}

	/**
	 * Returns the recording equipment's model name.
	 * @return ?string The model, or null when absent.
	 */
	public function getModel(): ?string
	{
		return $this->getTextAttribute(self::ModelChunk);
	}

	/**
	 * Sets (or removes, when null) the recording equipment's model name.
	 * @param ?string $value The model, or null.
	 */
	public function setModel(?string $value): void
	{
		$this->setTextAttribute(self::ModelChunk, $value);
	}

	/**
	 * Returns the maker-specific block.
	 * @return ?string The maker note bytes, or null when absent.
	 */
	public function getMakerNote(): ?string
	{
		return $this->_attributes[self::MakerNoteChunk] ?? null;
	}

	/**
	 * Sets (or removes, when null) the maker-specific block.
	 * @param ?string $value The maker note bytes, or null.
	 */
	public function setMakerNote(?string $value): void
	{
		$this->setAttribute(self::MakerNoteChunk, $value);
	}

	/**
	 * Returns the user comment, decoded through its eight-byte character-code prefix.
	 * @return ?string The comment text, or null when absent.
	 */
	public function getUserComment(): ?string
	{
		$value = $this->_attributes[self::UserCommentChunk] ?? null;
		return $value === null ? null : EXIFTags::decodeCodedString($value);
	}

	/**
	 * Sets (or removes, when null) the user comment, prefixing the character code
	 * (ASCII when the text is plain, UNICODE otherwise).  The comment itself is not
	 * NUL-terminated, per the spec.
	 * @param ?string $value The comment text, or null to remove the chunk.
	 * @param ?string $charset The character code to force ('ASCII', 'UNICODE', 'JIS'),
	 *   or null to choose by content.
	 */
	public function setUserComment(?string $value, ?string $charset = null): void
	{
		$this->setAttribute(self::UserCommentChunk, $value === null ? null : EXIFTags::encodeCodedString($value, $charset));
	}

	/**
	 * Returns the character code of the user comment.
	 * @return ?string The charset name ('ASCII', 'UNICODE', 'JIS', or '' when
	 *   undefined), or null when there is no comment.
	 */
	public function getUserCommentCharset(): ?string
	{
		$value = $this->_attributes[self::UserCommentChunk] ?? null;
		return $value === null ? null : rtrim(substr($value, 0, 8), "\0");
	}

	/**
	 * Returns the audio format fields of the `fmt ` chunk.
	 * @return ?array The format/channels/sampleRate/byteRate/blockAlign/bitsPerSample
	 *   set, or null when the chunk is absent or short.
	 */
	public function getFormat(): ?array
	{
		$chunk = $this->_riff->getChunk('fmt ');
		if ($chunk === null || strlen($chunk->getData()) < 16) {
			return null;
		}
		$fields = unpack('vformat/vchannels/VsampleRate/VbyteRate/vblockAlign/vbits', $chunk->getData());
		return [
			'format' => $fields['format'],
			'channels' => $fields['channels'],
			'sampleRate' => $fields['sampleRate'],
			'byteRate' => $fields['byteRate'],
			'blockAlign' => $fields['blockAlign'],
			'bitsPerSample' => $fields['bits'],
		];
	}

	/**
	 * Returns the playing time of the audio data.
	 * @return ?float The duration in seconds, or null when it cannot be computed.
	 */
	public function getDurationSeconds(): ?float
	{
		$format = $this->getFormat();
		$data = $this->_riff->getChunk('data');
		if ($format === null || $data === null || ($format['byteRate'] ?? 0) < 1) {
			return null;
		}
		return strlen($data->getData()) / $format['byteRate'];
	}

	/**
	 * Writes the rebuilt WAVE file to disk.
	 * @param string $path The destination path.
	 * @throws \RuntimeException When the file cannot be written.
	 * @return int The number of bytes written.
	 */
	public function save(string $path): int
	{
		$written = @file_put_contents($path, $this->toBinary());
		if ($written === false) {
			throw new \RuntimeException(sprintf('Image file \'%s\' cannot be written.', $path));
		}
		return $written;
	}

	/**
	 * Rebuilds the WAVE file, rewriting the Exif attribute list and carrying every
	 * other chunk (the format and audio data included) through untouched.
	 * @return string The composed WAVE bytes.
	 */
	public function toBinary(): string
	{
		$list = $this->findExifList();
		if ($this->_attributes === []) {
			if ($list !== null) {
				$this->_riff->removeChunk('LIST');
			}
			return $this->_riff->toBinary();
		}
		$data = self::ExifListType;
		foreach ($this->_attributes as $id => $payload) {
			$data .= substr(str_pad($id, 4), 0, 4) . pack('V', strlen($payload)) . $payload;
			if (strlen($payload) & 1) {
				$data .= "\0";   // chunks pad to an even length
			}
		}
		if ($list === null) {
			$this->_riff->addChunk(new ImageChunk('LIST', strlen($data), 0, $data));
		} else {
			$list->setData($data);
		}
		return $this->_riff->toBinary();
	}
}
