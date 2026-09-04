<?php

/**
 * JPEGImage class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image;

use Belisoful\Image\Meta\EXIF;
use Belisoful\Image\Meta\IPTC;
use Belisoful\Image\Meta\JFIF;
use Belisoful\Image\Meta\JFXX;
use Belisoful\Image\Meta\JUMBF\JUMBFBox;
use Belisoful\Image\Meta\PictureInfo;
use Belisoful\Image\Meta\XMP;
use Belisoful\Image\Stream\BinaryReader;
use Belisoful\Image\Stream\SourceRange;
use Belisoful\Image\Stream\StreamIO;

/**
 * JPEGImage class.
 *
 * Reads and rewrites a JFIF/EXIF JPEG at the marker-segment level.  Parsing keeps every
 * segment between the start-of-image and start-of-scan markers in order, parses the
 * APP0 JFIF/JFXX, the APP13 Photoshop IPTC block, and the APP2 ICC profile into editable
 * objects, and reads the pixel dimensions from the start-of-frame.  The entropy-coded
 * scan that follows is preserved verbatim, so the file round-trips and metadata can be
 * edited and saved without touching the image.
 *
 * ```php
 * $jpeg = JPEGImage::fromFile('photo.jpg');
 * $jpeg->getIPTC()[IPTCTags::CaptionAbstract] = 'Edited caption';
 * $jpeg->setComment('Processed by php-image');
 * $jpeg->save('photo-out.jpg');     // image data unchanged, metadata rewritten
 * ```
 *
 * Every JPEG marker is a named byte constant (see the {@see SOF0}/{@see APP0}/{@see COM}
 * family).  {@see markerName()}, {@see markerHasLength()}, and
 * {@see isStartOfFrameMarker()} classify them.  The parse/compose pipeline runs through
 * protected hooks ({@see ingestSegment()}, {@see readStartOfFrame()},
 * {@see composeSegment()}, {@see segmentBytes()}) so a subclass can handle additional
 * markers (e.g. APP1 EXIF/XMP) without reimplementing the walk.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @see https://www.w3.org/Graphics/JPEG/itu-t81.pdf JPEG official spec
 */
class JPEGImage extends ImageFile
{
	/** @var int The byte that introduces every marker. */
	public const MARKER_PREFIX = 0xFF;

	// Standalone markers (no length field, no payload).
	public const TEM = 0x01;   // temporary, arithmetic coding
	public const RES = 0x02;   // reserved (0x02-0xBF)

	// Start-of-frame markers (C0-CF, excluding DHT/JPG/DAC).
	public const SOF0 = 0xC0;  // baseline DCT
	public const SOF1 = 0xC1;  // extended sequential DCT
	public const SOF2 = 0xC2;  // progressive DCT
	public const SOF3 = 0xC3;  // lossless (sequential)
	public const DHT = 0xC4;   // define Huffman table(s)
	public const SOF5 = 0xC5;  // differential sequential DCT
	public const SOF6 = 0xC6;  // differential progressive DCT
	public const SOF7 = 0xC7;  // differential lossless (sequential)
	public const JPG = 0xC8;   // JPEG extensions
	public const SOF9 = 0xC9;  // extended sequential DCT, arithmetic
	public const SOF10 = 0xCA; // progressive DCT, arithmetic
	public const SOF11 = 0xCB; // lossless (sequential), arithmetic
	public const DAC = 0xCC;   // define arithmetic coding conditioning
	public const SOF13 = 0xCD; // differential sequential DCT, arithmetic
	public const SOF14 = 0xCE; // differential progressive DCT, arithmetic
	public const SOF15 = 0xCF; // differential lossless (sequential), arithmetic

	// Restart markers (standalone).
	public const RST0 = 0xD0;
	public const RST1 = 0xD1;
	public const RST2 = 0xD2;
	public const RST3 = 0xD3;
	public const RST4 = 0xD4;
	public const RST5 = 0xD5;
	public const RST6 = 0xD6;
	public const RST7 = 0xD7;

	public const SOI = 0xD8;   // start of image (standalone)
	public const EOI = 0xD9;   // end of image (standalone)
	public const SOS = 0xDA;   // start of scan (entropy data follows)
	public const DQT = 0xDB;   // define quantization table(s)
	public const DNL = 0xDC;   // define number of lines
	public const DRI = 0xDD;   // define restart interval
	public const DHP = 0xDE;   // define hierarchical progression
	public const EXP = 0xDF;   // expand reference component(s)

	// Application segments (APP1 = EXIF/XMP, APP2 = ICC, APP13 = Photoshop/IPTC, APP14 = Adobe).
	public const APP0 = 0xE0;
	public const APP1 = 0xE1;
	public const APP2 = 0xE2;
	public const APP3 = 0xE3;
	public const APP4 = 0xE4;
	public const APP5 = 0xE5;
	public const APP6 = 0xE6;
	public const APP7 = 0xE7;
	public const APP8 = 0xE8;
	public const APP9 = 0xE9;
	public const APP10 = 0xEA;
	public const APP11 = 0xEB;
	public const APP12 = 0xEC;
	public const APP13 = 0xED;
	public const APP14 = 0xEE;
	public const APP15 = 0xEF;

	// JPEG extension markers.
	public const JPG0 = 0xF0;
	public const JPG1 = 0xF1;
	public const JPG2 = 0xF2;
	public const JPG3 = 0xF3;
	public const JPG4 = 0xF4;
	public const JPG5 = 0xF5;
	public const JPG6 = 0xF6;
	public const JPG7 = 0xF7;
	public const JPG8 = 0xF8;
	public const JPG9 = 0xF9;
	public const JPG10 = 0xFA;
	public const JPG11 = 0xFB;
	public const JPG12 = 0xFC;
	public const JPG13 = 0xFD;

	public const COM = 0xFE;   // comment

	/** @var array<int, string> The marker byte to mnemonic name map. */
	public const MARKER_NAMES = [
		self::TEM => 'TEM',
		self::RES => 'RES',
		self::SOF0 => 'SOF0', self::SOF1 => 'SOF1', self::SOF2 => 'SOF2', self::SOF3 => 'SOF3',
		self::DHT => 'DHT', self::SOF5 => 'SOF5', self::SOF6 => 'SOF6', self::SOF7 => 'SOF7',
		self::JPG => 'JPG', self::SOF9 => 'SOF9', self::SOF10 => 'SOF10', self::SOF11 => 'SOF11',
		self::DAC => 'DAC', self::SOF13 => 'SOF13', self::SOF14 => 'SOF14', self::SOF15 => 'SOF15',
		self::RST0 => 'RST0', self::RST1 => 'RST1', self::RST2 => 'RST2', self::RST3 => 'RST3',
		self::RST4 => 'RST4', self::RST5 => 'RST5', self::RST6 => 'RST6', self::RST7 => 'RST7',
		self::SOI => 'SOI', self::EOI => 'EOI', self::SOS => 'SOS', self::DQT => 'DQT',
		self::DNL => 'DNL', self::DRI => 'DRI', self::DHP => 'DHP', self::EXP => 'EXP',
		self::APP0 => 'APP0', self::APP1 => 'APP1', self::APP2 => 'APP2', self::APP3 => 'APP3',
		self::APP4 => 'APP4', self::APP5 => 'APP5', self::APP6 => 'APP6', self::APP7 => 'APP7',
		self::APP8 => 'APP8', self::APP9 => 'APP9', self::APP10 => 'APP10', self::APP11 => 'APP11',
		self::APP12 => 'APP12', self::APP13 => 'APP13', self::APP14 => 'APP14', self::APP15 => 'APP15',
		self::JPG0 => 'JPG0', self::JPG1 => 'JPG1', self::JPG2 => 'JPG2', self::JPG3 => 'JPG3',
		self::JPG4 => 'JPG4', self::JPG5 => 'JPG5', self::JPG6 => 'JPG6', self::JPG7 => 'JPG7',
		self::JPG8 => 'JPG8', self::JPG9 => 'JPG9', self::JPG10 => 'JPG10', self::JPG11 => 'JPG11',
		self::JPG12 => 'JPG12', self::JPG13 => 'JPG13', self::COM => 'COM',
	];

	/** @var int The maximum ICC payload bytes per APP2 segment (65533 - 14-byte header). */
	protected const ICC_CHUNK_SIZE = 65519;

	/** @var string The ICC profile APP2 identifier. */
	protected const ICC_IDENTIFIER = "ICC_PROFILE\x00";

	/** @var string The XMP APP1 identifier. */
	public const XMP_IDENTIFIER = "http://ns.adobe.com/xap/1.0/\x00";

	/** @var string The extended-XMP APP1 identifier, for packets past the segment limit. */
	public const XMP_EXTENSION_IDENTIFIER = "http://ns.adobe.com/xmp/extension/\x00";

	/** @var int The maximum standard XMP packet bytes in one APP1 segment. */
	protected const XMP_CHUNK_SIZE = 65504;

	/**
	 * @var int The maximum extended-XMP bytes per APP1 segment: the segment payload
	 *   less the identifier, the 32-character digest, and the length and offset fields.
	 */
	protected const XMP_EXTENSION_CHUNK_SIZE = 65458;

	/** @var string The APP11 box-format identifier code (JPEG Systems). */
	public const JUMBF_IDENTIFIER = 'JP';

	/**
	 * @var int The maximum JUMBF box-payload bytes per APP11 segment: the 65533-byte
	 *   segment payload less the 'JP', instance, sequence, LBox, and TBox fields.
	 */
	protected const JUMBF_CHUNK_SIZE = 65517;

	/** @var array<int, array{marker: int, kind: string, payload: string}> The ordered segments (kind: raw|jfif|jfxx|iptc|irb|icc|exif|meta|xmp). */
	private array $_segments = [];

	/** @var ?PhotoshopIRB The parsed Photoshop image resources (APP13), or null when absent. */
	private ?PhotoshopIRB $_irb = null;

	/** @var string The preserved entropy-coded scan (from the SOS marker to the end). */
	private string $_scan = '';

	/** @var ?SourceRange The deferred scan range into a still-open source, or null when loaded. */
	private ?SourceRange $_scanRange = null;

	/** @var ?JFIF The parsed JFIF (APP0), or null when absent. */
	private ?JFIF $_jfif = null;

	/** @var ?JFXX The parsed JFXX thumbnail extension (APP0), or null when absent. */
	private ?JFXX $_jfxx = null;

	/** @var ?EXIF The parsed EXIF (APP1), or null when absent. */
	private ?EXIF $_exif = null;

	/** @var ?EXIF The parsed Kodak Meta (APP3), or null when absent. */
	private ?EXIF $_meta = null;

	/** @var ?string The raw XMP packet text (APP1), or null when absent. */
	private ?string $_xmpText = null;

	/** @var ?PictureInfo The parsed legacy picture info (APP12), or null when absent. */
	private ?PictureInfo $_pictureInfo = null;

	/** @var JUMBFBox[] The parsed JUMBF boxes (APP11). */
	private array $_jumbfBoxes = [];

	/**
	 * Indicates whether the bytes begin with the JPEG start-of-image marker.
	 * @param string $data The candidate bytes.
	 * @return bool Whether the data is a JPEG.
	 */
	public static function isJPEG(string $data): bool
	{
		return strlen($data) >= 2 && ord($data[0]) === self::MARKER_PREFIX && ord($data[1]) === self::SOI;
	}

	/**
	 * Returns the mnemonic name of a marker byte.
	 * @param int $marker The marker byte (the second byte after 0xFF).
	 * @return ?string The marker name (e.g. 'SOF0', 'APP1'), or null when unknown.
	 */
	public static function markerName(int $marker): ?string
	{
		return self::MARKER_NAMES[$marker] ?? null;
	}

	/**
	 * Indicates whether a marker carries a two-byte length field and a payload.
	 * The standalone markers (SOI, EOI, TEM, RST0-RST7) do not.
	 * @param int $marker The marker byte.
	 * @return bool Whether the marker has a length-prefixed payload.
	 */
	public static function markerHasLength(int $marker): bool
	{
		if ($marker === self::TEM || $marker === self::SOI || $marker === self::EOI) {
			return false;
		}
		return $marker < self::RST0 || $marker > self::RST7;
	}

	/**
	 * Indicates whether a marker is a start-of-frame marker (which carries the
	 * image dimensions).  SOFn spans C0-CF except DHT, JPG, and DAC.
	 * @param int $marker The marker byte.
	 * @return bool Whether the marker is a start-of-frame marker.
	 */
	public static function isStartOfFrameMarker(int $marker): bool
	{
		return $marker >= self::SOF0 && $marker <= self::SOF15
			&& $marker !== self::DHT && $marker !== self::JPG && $marker !== self::DAC;
	}

	/**
	 * Returns the format name.
	 * @return string Always 'JPEG'.
	 */
	public function getFormat(): string
	{
		return 'JPEG';
	}

	/**
	 * Returns the segments in file order.
	 * @return array<int, array{marker: int, kind: string, payload: string}> The segments.
	 */
	public function getSegments(): array
	{
		return $this->getSegmentsDirect();
	}

	/**
	 * Decodes the JPEG into a graphics-library image.
	 * @param ?string $mode The {@see ImageGraphicsMode} to decode in; null for the default.
	 * @return false|\GdImage|\Imagick The image, or false when undecodable.
	 */
	public function getImage(?string $mode = null): false|\GdImage|\Imagick
	{
		return ImageGraphics::decode($this->getBytesDirect(), $mode);
	}

	/**
	 * Replaces the pixels with a graphics-library image, keeping every metadata carrier.
	 *
	 * Unlike a metadata-only edit — which leaves the entropy-coded scan byte-identical —
	 * this **re-encodes** the image, so it costs a JPEG generation.  Only the frame and
	 * scan segments of the new encoding are taken; the parsed carriers (EXIF, XMP, IPTC,
	 * the ICC profile, the IRB, JFIF/JFXX, JUMBF, Picture Info, the comment) stay on this
	 * object and are re-emitted by {@see compose()}, so nothing needs enumerating here.
	 * @param \GdImage|\Imagick $image The source image.
	 * @param int $quality The JPEG quality. Default 75.
	 * @throws \RuntimeException When the image's graphics library cannot write JPEG.
	 */
	public function setImage(\GdImage|\Imagick $image, int $quality = 75): void
	{
		$bytes = ImageGraphics::encode($image, ImageGraphicsLibraryInterface::FormatJpeg, $quality);
		if ($bytes === false) {
			throw new \RuntimeException(sprintf('The %s graphics library on this installation cannot write JPEG.', ImageGraphics::getModeOf($image)));
		}
		$fresh = static::fromString($bytes);
		// A frame segment (the quantization and Huffman tables, the frame header, the
		// restart interval, the start of scan) belongs to the pixels and is swapped; every
		// other segment stays, which keeps the carriers that live as raw segments — the
		// comment, and any application segment this class does not model — along with the
		// parsed ones.
		$isFrame = static fn (array $segment): bool => $segment['kind'] === 'raw'
			&& ($segment['marker'] < self::APP0 || $segment['marker'] > self::APP15)
			&& $segment['marker'] !== self::COM;
		$this->setSegmentsDirect(array_merge(
			array_values(array_filter($this->getSegmentsDirect(), static fn (array $s): bool => !$isFrame($s))),
			array_values(array_filter($fresh->getSegmentsDirect(), $isFrame)),
		));
		$this->setScanDirect($fresh->getScanDirect());
		$this->setWidthDirect($fresh->getWidth());
		$this->setHeightDirect($fresh->getHeight());
		$this->setBytesDirect($this->compose());
	}

	/**
	 * Creates a JPEG from a graphics-library image.
	 * @param \GdImage|\Imagick $image The source image.
	 * @param int $quality The JPEG quality. Default 75.
	 * @throws \RuntimeException When the image's graphics library cannot write JPEG.
	 * @return static The new JPEG.
	 */
	public static function fromImage(\GdImage|\Imagick $image, int $quality = 75): static
	{
		$bytes = ImageGraphics::encode($image, ImageGraphicsLibraryInterface::FormatJpeg, $quality);
		if ($bytes === false) {
			throw new \RuntimeException(sprintf('The %s graphics library on this installation cannot write JPEG.', ImageGraphics::getModeOf($image)));
		}
		return static::fromString($bytes);
	}

	/**
	 * Returns the raw segment list (protected raw accessor).
	 * @return array<int, array{marker: int, kind: string, payload: string}> The segments.
	 */
	protected function getSegmentsDirect(): array
	{
		return $this->_segments;
	}

	/**
	 * Stores the raw segment list (protected raw accessor).
	 * @param array<int, array{marker: int, kind: string, payload: string}> $segments The segments.
	 */
	protected function setSegmentsDirect(array $segments): void
	{
		$this->_segments = $segments;
	}

	/**
	 * Appends a segment to the list (for parse-time recording and subclass hooks).
	 * @param int $marker The marker byte.
	 * @param string $kind The segment kind (raw|jfif|jfxx|iptc|icc, or a subclass kind).
	 * @param string $payload The payload (empty for metadata kinds regenerated on compose).
	 */
	protected function addSegment(int $marker, string $kind, string $payload = ''): void
	{
		$this->_segments[] = ['marker' => $marker, 'kind' => $kind, 'payload' => $payload];
	}

	/**
	 * Indicates whether a segment of the given kind was recorded during parsing.
	 * @param string $kind The segment kind.
	 * @return bool Whether such a segment is present.
	 */
	protected function hasSegmentKind(string $kind): bool
	{
		foreach ($this->getSegmentsDirect() as $segment) {
			if ($segment['kind'] === $kind) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns the parsed JFIF (APP0) metadata.
	 * @return ?JFIF The JFIF, or null when absent.
	 */
	public function getJFIF(): ?JFIF
	{
		return $this->_jfif;
	}

	/**
	 * Sets (or clears, when null) the JFIF (APP0) metadata written on {@see compose()}.
	 * @param ?JFIF $jfif The JFIF, or null to drop it.
	 */
	public function setJFIF(?JFIF $jfif): void
	{
		$this->_jfif = $jfif;
	}

	/**
	 * Returns the parsed JFXX thumbnail extension (APP0).
	 * @return ?JFXX The JFXX, or null when absent.
	 */
	public function getJFXX(): ?JFXX
	{
		return $this->_jfxx;
	}

	/**
	 * Sets (or clears, when null) the JFXX thumbnail extension written on {@see compose()}.
	 * @param ?JFXX $jfxx The JFXX, or null to drop it.
	 */
	public function setJFXX(?JFXX $jfxx): void
	{
		$this->_jfxx = $jfxx;
	}

	/**
	 * Returns the parsed EXIF (APP1) metadata.
	 * @return ?EXIF The EXIF, or null when absent.
	 */
	public function getEXIF(): ?EXIF
	{
		return $this->_exif;
	}

	/**
	 * Sets (or clears, when null) the EXIF (APP1) metadata written on {@see compose()}.
	 * @param ?EXIF $exif The EXIF, or null to drop it.
	 */
	public function setEXIF(?EXIF $exif): void
	{
		$this->_exif = $exif;
	}

	/**
	 * Returns the parsed Kodak Meta (APP3) metadata.
	 * @return ?EXIF The Meta block, or null when absent.
	 */
	public function getMeta(): ?EXIF
	{
		return $this->_meta;
	}

	/**
	 * Sets (or clears, when null) the Kodak Meta (APP3) metadata written on {@see compose()}.
	 * @param ?EXIF $meta The Meta block, or null to drop it.
	 */
	public function setMeta(?EXIF $meta): void
	{
		$this->_meta = $meta;
	}

	/**
	 * Returns the raw XMP packet text (APP1).
	 * @return ?string The XMP XML, or null when absent.
	 */
	public function getXmpText(): ?string
	{
		return $this->_xmpText;
	}

	/**
	 * Sets (or clears, when null) the raw XMP packet text written on {@see compose()}.
	 * @param ?string $xmp The XMP XML, or null to drop it.
	 */
	public function setXmpText(?string $xmp): void
	{
		$this->_xmpText = $xmp;
	}

	/**
	 * Returns the XMP packet parsed as a {@see XMP} DOM.
	 * @return ?XMP The XMP, or null when absent or unparsable.
	 */
	public function getXMP(): ?XMP
	{
		$text = $this->getXmpText();
		if ($text === null) {
			return null;
		}
		$xmp = XMP::parse($text);
		return $xmp === false ? null : $xmp;
	}

	/**
	 * Sets (or clears, when null) the XMP packet written on {@see compose()}.
	 * @param ?XMP $xmp The XMP, or null to drop the segment.
	 */
	public function setXMP(?XMP $xmp): void
	{
		$this->setXmpText($xmp?->toPacketText());
	}

	/**
	 * Returns the JUMBF boxes carried in the APP11 segments (Exif 3.0 annotation data
	 * and other box-structured metadata), reassembled across segment fragments.
	 * @return JUMBFBox[] The boxes, in file order.
	 */
	public function getJumbfBoxes(): array
	{
		return $this->_jumbfBoxes;
	}

	/**
	 * Sets the JUMBF boxes written as APP11 segments on {@see compose()}, split across
	 * as many segments as each box needs.
	 * @param JUMBFBox[] $boxes The boxes, in order.
	 */
	public function setJumbfBoxes(array $boxes): void
	{
		$this->_jumbfBoxes = array_values($boxes);
	}

	/**
	 * Returns the first JUMBF box whose description carries a label.
	 * @param string $label The content label.
	 * @return ?JUMBFBox The box, or null when absent.
	 */
	public function getJumbfBox(string $label): ?JUMBFBox
	{
		foreach ($this->_jumbfBoxes as $box) {
			if ($box->getLabel() === $label) {
				return $box;
			}
		}
		return null;
	}

	/**
	 * Returns the parsed legacy picture info (APP12).
	 * @return ?PictureInfo The picture info, or null when absent.
	 */
	public function getPictureInfo(): ?PictureInfo
	{
		return $this->_pictureInfo;
	}

	/**
	 * Sets (or clears, when null) the legacy picture info written on {@see compose()}.
	 * @param ?PictureInfo $info The picture info, or null to drop it.
	 */
	public function setPictureInfo(?PictureInfo $info): void
	{
		$this->_pictureInfo = $info;
	}

	/**
	 * Returns the parsed Photoshop image-resource block set (APP13).
	 * @return ?PhotoshopIRB The IRB, or null when absent.
	 */
	public function getPhotoshopIRB(): ?PhotoshopIRB
	{
		return $this->_irb;
	}

	/**
	 * Sets (or clears, when null) the Photoshop image resources written on {@see compose()}.
	 * The IPTC record set travels inside the IRB: on compose {@see getIPTC()} is synced
	 * into resource 0x0404.
	 * @param ?PhotoshopIRB $irb The IRB, or null to drop it.
	 */
	public function setPhotoshopIRB(?PhotoshopIRB $irb): void
	{
		$this->_irb = $irb;
	}

	/**
	 * Returns the preserved entropy-coded scan bytes.
	 * @return string The scan, beginning with the SOS marker.
	 */
	public function getScan(): string
	{
		return $this->getScanDirect();
	}

	/**
	 * Returns the raw scan bytes (protected raw accessor).
	 * @return string The scan.
	 */
	protected function getScanDirect(): string
	{
		if ($this->_scanRange !== null) {
			return $this->_scanRange->read();   // materialize a lazily deferred scan
		}
		return $this->_scan;
	}

	/**
	 * Stores the raw scan bytes (protected raw accessor), loading it (a deferred range is
	 * dropped, since the scan is now held directly).
	 * @param string $scan The scan, beginning with the SOS marker.
	 */
	protected function setScanDirect(string $scan): void
	{
		$this->_scan = $scan;
		$this->_scanRange = null;
	}

	/**
	 * Returns the first JPEG comment (COM segment).
	 * @return ?string The comment, or null when absent.
	 */
	public function getComment(): ?string
	{
		foreach ($this->getSegments() as $segment) {
			if ($segment['marker'] === self::COM && $segment['kind'] === 'raw') {
				return $segment['payload'];
			}
		}
		return null;
	}

	/**
	 * Sets the JPEG comment, replacing the first COM segment or appending one.
	 * @param ?string $comment The comment, or null to remove all comments.
	 */
	public function setComment(?string $comment): void
	{
		$found = false;
		$segments = $this->getSegmentsDirect();
		foreach ($segments as $i => $segment) {
			if ($segment['marker'] !== self::COM || $segment['kind'] !== 'raw') {
				continue;
			}
			if ($comment === null || $found) {
				unset($segments[$i]);
				continue;
			}
			$segments[$i]['payload'] = $comment;
			$found = true;
		}
		$this->setSegmentsDirect(array_values($segments));
		if ($comment !== null && !$found) {
			$this->addSegment(self::COM, 'raw', $comment);
		}
	}

	/**
	 * Extends {@see clearPrivateData()} to the carriers only a JPEG has: the COM comment
	 * (free text, {@see PrivacyCategory::Description}), the JFIF and JFXX APP0
	 * thumbnails ({@see PrivacyCategory::Thumbnail} — the density fields stay), and the
	 * legacy APP12 Picture Info block, whose `Camera=`, `Serial=`, and `TimeDate=` lines
	 * make the whole block identifying ({@see PrivacyCategory::CameraModel},
	 * {@see PrivacyCategory::SerialNumber}, or {@see PrivacyCategory::Timestamp}).
	 * @param int $types The {@see PrivacyCategory} flags to remove.
	 * @return int The number of fields removed.
	 */
	protected function clearFormatPrivateData(int $types): int
	{
		$removed = 0;
		if (($types & PrivacyCategory::Description) && $this->getComment() !== null) {
			$this->setComment(null);
			$removed++;
		}
		if ($types & PrivacyCategory::Thumbnail) {
			$jfif = $this->getJFIF();
			if ($jfif !== null && $jfif->hasImage()) {
				$jfif->setImage(null);   // keeps the density fields, drops only the pixels
				$this->setJFIF($jfif);
				$removed++;
			}
			if ($this->getJFXX() !== null) {
				$this->setJFXX(null);   // JFXX carries nothing but a thumbnail
				$removed++;
			}
		}
		if (($types & (PrivacyCategory::CameraModel | PrivacyCategory::SerialNumber | PrivacyCategory::Timestamp))
			&& $this->getPictureInfo() !== null) {
			$this->setPictureInfo(null);
			$removed++;
		}
		return $removed;
	}

	/**
	 * Walks the JPEG marker segments, parsing JFIF/JFXX/IPTC/ICC and preserving the scan.
	 * @throws \UnexpectedValueException When the bytes are not a JPEG (no SOI marker).
	 */
	protected function parse(): void
	{
		$bytes = $this->getBytesDirect();
		$len = strlen($bytes);
		if (!self::isJPEG($bytes)) {
			throw new \UnexpectedValueException(sprintf('JPEG data is invalid: %s.', 'missing SOI marker'));
		}

		$this->scanDimensions($bytes);

		$chunks = ['icc' => [], 'irb' => [], 'jumbf' => [], 'xmpext' => []];
		$i = 2;
		while ($i + 1 < $len) {
			if (ord($bytes[$i]) !== self::MARKER_PREFIX) {
				$i++;
				continue;
			}
			$marker = ord($bytes[$i + 1]);
			if ($marker === self::EOI || $marker === self::SOS) { // keep the rest as the scan
				$this->setScanDirect(substr($bytes, $i));
				break;
			}
			if (!self::markerHasLength($marker)) {
				$i += 2;
				continue;
			}
			if ($i + 3 >= $len) {
				break;
			}
			$segLen = (ord($bytes[$i + 2]) << 8) | ord($bytes[$i + 3]);
			$payload = substr($bytes, $i + 4, $segLen - 2);
			$this->ingestSegment($marker, $payload, $chunks);
			$i += 2 + $segLen;
		}
		$this->finalizeParsedChunks($chunks);
	}

	/**
	 * Reassembles the multi-segment carriers gathered during a parse into their objects:
	 * the (ordered) ICC profile, the Photoshop IRB and its IPTC, the JUMBF boxes, and the
	 * extended XMP.
	 * @param array{icc: array<int, string>, irb: string[], jumbf: string[], xmpext: string[]} $chunks
	 */
	protected function finalizeParsedChunks(array $chunks): void
	{
		if ($chunks['icc'] !== []) {
			ksort($chunks['icc']);
			$this->setICCProfileDirect(implode('', $chunks['icc']));
		}
		if ($chunks['irb'] !== []) {
			$irb = PhotoshopIRB::parse(implode('', $chunks['irb']));
			if ($irb !== false) {
				$this->setPhotoshopIRB($irb);
				$this->setIptcDirect($irb->getIPTC());
			}
		}
		if ($chunks['jumbf'] !== []) {
			$this->setJumbfBoxes($this->reassembleJumbf($chunks['jumbf']));
		}
		if ($chunks['xmpext'] !== []) {
			$this->mergeExtendedXmp($chunks['xmpext']);
		}
	}

	/**
	 * Lazily reads a JPEG from a seekable stream: every segment before the scan is read,
	 * but the entropy-coded scan (from the `SOS` marker to the end) is kept as a deferred
	 * range into the still-open source rather than loaded, so a JPEG far larger than memory
	 * opens for a metadata edit.  Pair it with {@see streamTo()}; the source must stay open
	 * and seekable until then.  Dimensions come from the frame header; a rare `DNL`-in-scan
	 * height is not resolved without reading the scan.
	 * @param mixed $stream The seekable {@see \Psr\Http\Message\StreamInterface} or PHP stream resource.
	 * @throws \RuntimeException When the stream is not seekable.
	 * @throws \UnexpectedValueException When the bytes lack the SOI marker.
	 * @return static The lazily parsed JPEG.
	 */
	public static function fromStreamLazy(mixed $stream): static
	{
		StreamIO::rewind($stream);
		$image = new static();
		$image->parseStream($stream);
		return $image;
	}

	/**
	 * Walks the segments of a seekable stream, ingesting each and deferring the scan.
	 * @param mixed $stream The seekable stream or resource, positioned at the JPEG start.
	 * @throws \RuntimeException When the stream is not seekable.
	 * @throws \UnexpectedValueException When the bytes lack the SOI marker.
	 */
	protected function parseStream(mixed $stream): void
	{
		$binary = new BinaryReader($stream);
		if (!$binary->isSeekable()) {
			throw new \RuntimeException('Lazily reading a JPEG requires a seekable stream.');
		}
		if (!self::isJPEG($binary->readBytes(2))) {
			throw new \UnexpectedValueException(sprintf('JPEG data is invalid: %s.', 'missing SOI marker'));
		}
		$chunks = ['icc' => [], 'irb' => [], 'jumbf' => [], 'xmpext' => []];
		while (($marker = $this->nextMarker($binary)) !== null) {
			[$m, $markerOffset] = $marker;
			if ($m === self::SOS || $m === self::EOI) {   // the rest of the file is the scan
				$binary->seek(0, SEEK_END);
				$this->_scan = '';
				$this->_scanRange = new SourceRange($stream, $markerOffset, $binary->tell() - $markerOffset);
				break;
			}
			if (!self::markerHasLength($m)) {
				continue;
			}
			$segLen = (int) unpack('n', $binary->readBytes(2))[1];
			$payload = $binary->readBytes($segLen - 2);
			if (self::isStartOfFrameMarker($m) || $m === self::DHP) {
				$this->readStartOfFrame($payload);
			} elseif ($m === self::DNL) {
				$this->readDefineNumberOfLines($payload);
			}
			$this->ingestSegment($m, $payload, $chunks);
		}
		$this->finalizeParsedChunks($chunks);
	}

	/**
	 * Reads the next JPEG marker (the two-byte `0xFF` code) that follows an exactly-sized
	 * segment, so the reader is always positioned on it.
	 * @param BinaryReader $binary The reader.
	 * @return ?array{0: int, 1: int} The marker code and the offset of its leading `0xFF`, or null at end of stream.
	 */
	private function nextMarker(BinaryReader $binary): ?array
	{
		try {
			$marker = $binary->readBytes(2);
		} catch (\RuntimeException $e) {
			return null;   // end of stream
		}
		return [ord($marker[1]), $binary->tell() - 2];
	}

	/**
	 * Writes the JPEG to a target, rebuilding the (loaded or edited) segments and copying
	 * the deferred entropy scan straight from the source in bounded memory, so a JPEG opened
	 * with {@see fromStreamLazy()} is rewritten around a metadata edit without holding its
	 * pixels.  A fully loaded JPEG streams the same bytes {@see toBinary()} would.
	 * @param mixed $target A writable {@see \Psr\Http\Message\StreamInterface} or PHP stream resource.
	 * @throws \InvalidArgumentException When the target is neither.
	 * @throws \RuntimeException When the target stops accepting bytes.
	 * @return int The number of bytes written.
	 */
	public function streamTo(mixed $target): int
	{
		$head = $this->markerBytes(self::SOI) . $this->composeInjectedHead();
		$iccEmitted = false;
		foreach ($this->getSegments() as $segment) {
			$head .= $this->composeSegment($segment, $iccEmitted);
		}
		$written = StreamIO::writeAll($target, $head);
		if ($this->_scanRange !== null) {
			return $written + $this->_scanRange->writeTo($target);
		}
		return $written + StreamIO::writeAll($target, $this->_scan);
	}

	/**
	 * Reassembles the extended-XMP segments and merges their properties into the main
	 * packet, so a packet that outgrew one segment reads back as a single XMP.  Each
	 * segment carries the 32-character digest of the whole extended packet, its total
	 * length, and this chunk's offset.
	 * @param string[] $payloads The extension payloads (identifier already stripped).
	 */
	protected function mergeExtendedXmp(array $payloads): void
	{
		$parts = [];
		foreach ($payloads as $payload) {
			if (strlen($payload) < 40) {
				continue;
			}
			$digest = substr($payload, 0, 32);
			$offset = unpack('N', substr($payload, 36, 4))[1];
			$parts[$digest][$offset] = substr($payload, 40);
		}
		if ($parts === []) {
			return;
		}
		// Prefer the packet the main XMP points at (xmpNote:HasExtendedXMP).
		$wanted = null;
		$main = $this->getXMP();
		if ($main !== null) {
			$note = $main->getProperty(XMP::NS_NOTE, 'HasExtendedXMP');
			$wanted = is_string($note) ? $note : null;
		}
		$digest = $wanted !== null && isset($parts[$wanted]) ? $wanted : array_key_first($parts);

		ksort($parts[$digest]);
		$extended = XMP::parse(implode('', $parts[$digest]));
		if ($extended === false) {
			return;
		}
		if ($main === null) {
			$this->setXMP($extended);
			return;
		}
		$main->merge($extended);
		$main->removeProperty(XMP::NS_NOTE, 'HasExtendedXMP');
		$this->setXmpText($main->toPacketText());
	}

	/**
	 * Emits the XMP packet as APP1 segments: one standard segment when it fits, or a
	 * main packet holding the extended-XMP digest plus the chunked extension segments
	 * when it does not.
	 * @return string The concatenated APP1 segments (empty when there is no XMP).
	 */
	protected function composeXmp(): string
	{
		$text = $this->getXmpText();
		if ($text === null) {
			return '';
		}
		if (strlen($text) <= static::XMP_CHUNK_SIZE) {
			return $this->segmentBytes(self::APP1, self::XMP_IDENTIFIER . $text);
		}

		// Too large for one segment: the whole packet moves to the extension segments
		// and the main packet keeps only the digest that links them.
		$digest = strtoupper(md5($text));
		$main = XMP::blank();
		$main->setProperty(XMP::NS_NOTE, 'HasExtendedXMP', $digest);
		$out = $this->segmentBytes(self::APP1, self::XMP_IDENTIFIER . $main->toPacketText(false));

		$total = strlen($text);
		foreach (str_split($text, static::XMP_EXTENSION_CHUNK_SIZE) as $index => $chunk) {
			$offset = $index * static::XMP_EXTENSION_CHUNK_SIZE;
			$out .= $this->segmentBytes(
				self::APP1,
				self::XMP_EXTENSION_IDENTIFIER . $digest . pack('N', $total) . pack('N', $offset) . $chunk,
			);
		}
		return $out;
	}

	/**
	 * Reassembles JUMBF boxes from APP11 segment payloads: fragments of one box share
	 * a box-instance number and are ordered by their packet-sequence number, with the
	 * length and type fields repeated in every fragment.
	 * @param string[] $payloads The APP11 payloads, in file order.
	 * @return JUMBFBox[] The reassembled boxes.
	 */
	protected function reassembleJumbf(array $payloads): array
	{
		$instances = [];
		foreach ($payloads as $order => $payload) {
			if (strlen($payload) < 16 || !str_starts_with($payload, self::JUMBF_IDENTIFIER)) {
				continue;
			}
			$instance = unpack('n', substr($payload, 2, 2))[1];
			$sequence = unpack('N', substr($payload, 4, 4))[1];
			$instances[$instance] ??= ['order' => $order, 'header' => substr($payload, 8, 8), 'fragments' => []];
			$instances[$instance]['fragments'][$sequence] = substr($payload, 16);
		}
		uasort($instances, fn ($a, $b) => $a['order'] <=> $b['order']);

		$boxes = [];
		foreach ($instances as $instance) {
			ksort($instance['fragments']);
			$box = JUMBFBox::parse($instance['header'] . implode('', $instance['fragments']));
			if ($box !== false) {
				$boxes[] = $box;
			}
		}
		return $boxes;
	}

	/**
	 * Emits the JUMBF boxes as APP11 segments, splitting a box that outgrows one
	 * segment into sequence-numbered fragments.
	 * @return string The concatenated APP11 segments (empty when there are no boxes).
	 */
	protected function composeJumbf(): string
	{
		$out = '';
		foreach ($this->_jumbfBoxes as $index => $box) {
			$bytes = $box->toBinary();
			$header = substr($bytes, 0, 8);
			$payload = substr($bytes, 8);
			$fragments = $payload === '' ? [''] : str_split($payload, static::JUMBF_CHUNK_SIZE);
			foreach ($fragments as $sequence => $fragment) {
				$out .= $this->segmentBytes(
					self::APP11,
					self::JUMBF_IDENTIFIER . pack('n', $index + 1) . pack('N', $sequence + 1) . $header . $fragment,
				);
			}
		}
		return $out;
	}

	/**
	 * Classifies one segment (JFIF, JFXX, EXIF, XMP, Meta, Photoshop IRB/IPTC, ICC, or
	 * raw) and records it in order.  Dimensions are read separately by
	 * {@see scanDimensions()}.  Subclasses override to recognize more markers; call the
	 * parent for the default handling.
	 * @param int $marker The marker byte.
	 * @param string $payload The segment payload.
	 * @param array{icc: array<int, string>, irb: list<string>, jumbf: list<string>, xmpext: list<string>} &$chunks The accumulating multi-segment chunks.
	 */
	protected function ingestSegment(int $marker, string $payload, array &$chunks): void
	{
		if ($marker === self::APP1 && !$this->hasSegmentKind('exif') && str_starts_with($payload, EXIF::ExifSignature)) {
			// A malformed EXIF block is kept as a raw segment so the file still round-trips.
			try {
				$exif = EXIF::fromSegment($payload);
			} catch (\RuntimeException $e) {
				$exif = false;
			}
			$this->setEXIF($exif === false ? null : $exif);
			$this->addSegment($marker, $exif === false ? 'raw' : 'exif', $exif === false ? $payload : '');
			return;
		} elseif ($marker === self::APP1 && !$this->hasSegmentKind('xmp') && str_starts_with($payload, self::XMP_IDENTIFIER)) {
			$this->setXmpText(substr($payload, strlen(self::XMP_IDENTIFIER)));
			$this->addSegment($marker, 'xmp');
			return;
		} elseif ($marker === self::APP1 && str_starts_with($payload, self::XMP_EXTENSION_IDENTIFIER)) {
			$chunks['xmpext'][] = substr($payload, strlen(self::XMP_EXTENSION_IDENTIFIER));
			return;   // reassembled after the walk, then merged into the main packet
		} elseif ($marker === self::APP3 && !$this->hasSegmentKind('meta') && str_starts_with($payload, EXIF::MetaSignature)) {
			try {
				$meta = EXIF::fromSegment($payload);
			} catch (\RuntimeException $e) {
				$meta = false;
			}
			$this->setMeta($meta === false ? null : $meta);
			$this->addSegment($marker, $meta === false ? 'raw' : 'meta', $meta === false ? $payload : '');
			return;
		}
		if ($marker === self::APP0 && !$this->hasSegmentKind('jfif') && JFIF::isJFIF($payload)) {
			$jfif = JFIF::parse($payload);
			$this->setJFIF($jfif === false ? null : $jfif);
			$this->addSegment($marker, 'jfif');
			return;
		} elseif ($marker === self::APP0 && !$this->hasSegmentKind('jfxx') && JFXX::isJFXX($payload)) {
			$jfxx = JFXX::parse($payload);
			$this->setJFXX($jfxx === false ? null : $jfxx);
			$this->addSegment($marker, 'jfxx');
			return;
		} elseif ($marker === self::APP11 && str_starts_with($payload, self::JUMBF_IDENTIFIER)) {
			$chunks['jumbf'][] = $payload;
			if (!$this->hasSegmentKind('jumbf')) {
				$this->addSegment($marker, 'jumbf');
			}
			return;
		} elseif ($marker === self::APP12 && !$this->hasSegmentKind('pictureinfo') && PictureInfo::isPictureInfo($payload)) {
			$info = PictureInfo::parse($payload);
			$this->setPictureInfo($info === false ? null : $info);
			$this->addSegment($marker, $info === false ? 'raw' : 'pictureinfo', $info === false ? $payload : '');
			return;
		} elseif ($marker === self::APP13 && str_starts_with($payload, PhotoshopIRB::Signature)) {
			$chunks['irb'][] = $payload;
			if (!$this->hasSegmentKind('irb')) {
				$this->addSegment($marker, 'irb');
			}
			return;
		} elseif ($marker === self::APP13 && !$this->hasSegmentKind('iptc') && $this->isIptc($payload)) {
			$iptc = IPTC::parse($payload);
			$this->setIptcDirect($iptc === false ? null : $iptc);
			$this->addSegment($marker, 'iptc');
			return;
		} elseif ($marker === self::APP2 && strlen($payload) > 14 && str_starts_with($payload, static::ICC_IDENTIFIER)) {
			$chunks['icc'][ord($payload[12])] = substr($payload, 14);
			if (!$this->hasSegmentKind('icc')) {
				$this->addSegment($marker, 'icc');
			}
			return;
		}
		$this->addSegment($marker, 'raw', $payload);
	}

	/**
	 * Scans the whole file for the image dimensions, crossing every frame.  It reads each
	 * start-of-frame and the hierarchical DHP header, skipping the entropy-coded data
	 * after each scan, and locks the first non-zero height (with its width).  A frame may
	 * declare a height of 0, deferring the true number of lines to a DNL marker that
	 * appears after a scan; the DNL is read as the fallback.
	 * @param string $bytes The full JPEG bytes.
	 */
	protected function scanDimensions(string $bytes): void
	{
		$len = strlen($bytes);
		$i = 2;
		while ($i + 1 < $len) {
			if (ord($bytes[$i]) !== self::MARKER_PREFIX) {
				$i++;
				continue;
			}
			$marker = ord($bytes[$i + 1]);
			if ($marker === self::EOI) {
				break;
			}
			if (!self::markerHasLength($marker)) {
				$i += 2;
				continue;
			}
			if ($i + 3 >= $len) {
				break;
			}
			$segLen = (ord($bytes[$i + 2]) << 8) | ord($bytes[$i + 3]);
			$payload = substr($bytes, $i + 4, $segLen - 2);
			if (self::isStartOfFrameMarker($marker) || $marker === self::DHP) {
				$this->readStartOfFrame($payload);
			} elseif ($marker === self::DNL) {
				$this->readDefineNumberOfLines($payload);
			}
			$i += 2 + $segLen;
			if ($marker === self::SOS) {
				$i = $this->skipEntropyData($bytes, $i);
			}
		}
	}

	/**
	 * Advances past entropy-coded scan data to the next real marker.  Stuffed 0xFF 0x00
	 * bytes and restart markers (RST0-RST7) are part of the scan, not boundaries.
	 * @param string $bytes The full JPEG bytes.
	 * @param int $i The offset just past the start-of-scan header.
	 * @return int The offset of the next marker, or the end of the data.
	 */
	protected function skipEntropyData(string $bytes, int $i): int
	{
		$len = strlen($bytes);
		while ($i + 1 < $len) {
			if (ord($bytes[$i]) === self::MARKER_PREFIX) {
				$next = ord($bytes[$i + 1]);
				if ($next !== 0x00 && !($next >= self::RST0 && $next <= self::RST7)) {
					return $i;
				}
			}
			$i++;
		}
		return $len;
	}

	/**
	 * Reads the height and width from a start-of-frame (or DHP) payload, keeping the
	 * first non-zero height.  Layout: precision (1), height (2), width (2).
	 * @param string $payload The SOFn/DHP segment payload.
	 */
	protected function readStartOfFrame(string $payload): void
	{
		$height = $this->getHeightDirect();
		if (($height === null || $height === 0) && strlen($payload) >= 5) {
			$this->setHeightDirect((ord($payload[1]) << 8) | ord($payload[2]));
			$this->setWidthDirect((ord($payload[3]) << 8) | ord($payload[4]));
		}
	}

	/**
	 * Resolves a deferred height from a DNL (Define Number of Lines) payload, when a
	 * start-of-frame declared a height of 0.  Layout: number of lines (2).
	 * @param string $payload The DNL segment payload.
	 */
	protected function readDefineNumberOfLines(string $payload): void
	{
		$height = $this->getHeightDirect();
		if (($height === null || $height === 0) && strlen($payload) >= 2) {
			$this->setHeightDirect((ord($payload[0]) << 8) | ord($payload[1]));
		}
	}

	/**
	 * Indicates whether an APP13 payload carries an IPTC resource.
	 * @param string $payload The APP13 segment payload.
	 * @return bool Whether the payload is a Photoshop IPTC block.
	 */
	protected function isIptc(string $payload): bool
	{
		$copy = $payload;
		$length = Photoshop8BIM::iptcDecode($copy);
		return $length !== false && $length !== null;
	}

	/**
	 * Rebuilds the JPEG, regenerating the JFIF/JFXX/IPTC/ICC segments and preserving the scan.
	 * @return string The composed JPEG bytes.
	 */
	protected function compose(): string
	{
		$out = $this->markerBytes(self::SOI);
		$out .= $this->composeInjectedHead();
		$iccEmitted = false;
		foreach ($this->getSegments() as $segment) {
			$out .= $this->composeSegment($segment, $iccEmitted);
		}
		return $out . $this->getScanDirect();
	}

	/**
	 * Emits the bytes for one parsed segment, regenerating metadata kinds from their
	 * current objects.  Subclasses override to emit additional kinds.
	 * @param array{marker: int, kind: string, payload: string} $segment The segment.
	 * @param bool &$iccEmitted Whether the (possibly multi-chunk) ICC profile was already emitted.
	 * @return string The segment bytes (may be empty when the metadata was dropped).
	 */
	protected function composeSegment(array $segment, bool &$iccEmitted): string
	{
		switch ($segment['kind']) {
			case 'exif':
				return $this->getEXIF() === null ? '' : $this->segmentBytes(self::APP1, $this->getEXIF()->toSegment());
			case 'xmp':
				return $this->composeXmp();
			case 'meta':
				return $this->getMeta() === null ? '' : $this->segmentBytes(self::APP3, $this->getMeta()->toSegment());
			case 'jumbf':
				return $this->composeJumbf();
			case 'pictureinfo':
				return $this->getPictureInfo() === null ? '' : $this->segmentBytes(self::APP12, $this->getPictureInfo()->toBinary());
			case 'jfif':
				return $this->getJFIF() === null ? '' : $this->segmentBytes(self::APP0, $this->getJFIF()->toBinary());
			case 'jfxx':
				if ($this->getJFXX() !== null && ($bin = $this->getJFXX()->toBinary()) !== false) {
					return $this->segmentBytes(self::APP0, $bin);
				}
				return '';
			case 'iptc':
				return $this->getIptcDirect() === null ? '' : $this->segmentBytes(self::APP13, $this->getIptcDirect()->toBinary(true));
			case 'irb':
				return $this->composeIrb();
			case 'icc':
				if (!$iccEmitted && $this->getICCProfileDirect() !== null) {
					$iccEmitted = true;
					return $this->composeICC($this->getICCProfileDirect());
				}
				return '';
			default:
				return $this->segmentBytes($segment['marker'], $segment['payload']);
		}
	}

	/**
	 * Emits JFIF/JFXX/IPTC/ICC segments that were newly set on an image that lacked them.
	 * @return string The injected segment bytes (possibly empty).
	 */
	protected function composeInjectedHead(): string
	{
		$head = '';
		if ($this->getEXIF() !== null && !$this->hasSegmentKind('exif')) {
			$head .= $this->segmentBytes(self::APP1, $this->getEXIF()->toSegment());
		}
		if ($this->getXmpText() !== null && !$this->hasSegmentKind('xmp')) {
			$head .= $this->composeXmp();
		}
		if ($this->getMeta() !== null && !$this->hasSegmentKind('meta')) {
			$head .= $this->segmentBytes(self::APP3, $this->getMeta()->toSegment());
		}
		if ($this->_jumbfBoxes !== [] && !$this->hasSegmentKind('jumbf')) {
			$head .= $this->composeJumbf();   // APP11 follows APP1 and APP2
		}
		if ($this->getPictureInfo() !== null && !$this->hasSegmentKind('pictureinfo')) {
			$head .= $this->segmentBytes(self::APP12, $this->getPictureInfo()->toBinary());
		}
		if ($this->getJFIF() !== null && !$this->hasSegmentKind('jfif')) {
			$head .= $this->segmentBytes(self::APP0, $this->getJFIF()->toBinary());
		}
		if ($this->getJFXX() !== null && !$this->hasSegmentKind('jfxx') && ($bin = $this->getJFXX()->toBinary()) !== false) {
			$head .= $this->segmentBytes(self::APP0, $bin);
		}
		if ($this->getPhotoshopIRB() !== null && !$this->hasSegmentKind('irb')) {
			$head .= $this->composeIrb();
		}
		if ($this->getIptcDirect() !== null && $this->getPhotoshopIRB() === null && !$this->hasSegmentKind('iptc')) {
			$head .= $this->segmentBytes(self::APP13, $this->getIptcDirect()->toBinary(true));
		}
		if ($this->getICCProfileDirect() !== null && !$this->hasSegmentKind('icc')) {
			$head .= $this->composeICC($this->getICCProfileDirect());
		}
		return $head;
	}

	/**
	 * Emits the Photoshop IRB as chunked APP13 segments, syncing the live IPTC record
	 * set into resource 0x0404 first.
	 * @return string The concatenated APP13 segments (empty when the IRB was dropped).
	 */
	protected function composeIrb(): string
	{
		$irb = $this->getPhotoshopIRB();
		if ($irb === null) {
			return '';
		}
		$irb->setIPTC($this->getIptcDirect());
		$out = '';
		foreach ($irb->toSegments() as $payload) {
			$out .= $this->segmentBytes(self::APP13, $payload);
		}
		return $out;
	}

	/**
	 * Splits an ICC profile into APP2 segments.
	 * @param string $profile The ICC profile bytes.
	 * @return string The concatenated APP2 ICC segments.
	 */
	protected function composeICC(string $profile): string
	{
		$chunks = str_split($profile, static::ICC_CHUNK_SIZE);
		$total = count($chunks);
		$out = '';
		foreach ($chunks as $index => $chunk) {
			$payload = static::ICC_IDENTIFIER . chr($index + 1) . chr($total) . $chunk;
			$out .= $this->segmentBytes(self::APP2, $payload);
		}
		return $out;
	}

	/**
	 * Builds one marker segment from its marker byte and payload (marker, length, payload).
	 * @param int $marker The marker byte.
	 * @param string $payload The segment payload.
	 * @return string The segment bytes.
	 */
	protected function segmentBytes(int $marker, string $payload): string
	{
		return $this->markerBytes($marker) . pack('n', strlen($payload) + 2) . $payload;
	}

	/**
	 * Returns the two raw bytes of a marker (0xFF then the marker byte).
	 * @param int $marker The marker byte.
	 * @return string The two-byte marker.
	 */
	protected function markerBytes(int $marker): string
	{
		return chr(self::MARKER_PREFIX) . chr($marker);
	}
}
