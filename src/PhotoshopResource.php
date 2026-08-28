<?php

/**
 * PhotoshopResource class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image;

/**
 * PhotoshopResource class.
 *
 * One Photoshop 8BIM image resource: the numeric {@see getId() id}, the (usually
 * empty) Pascal-string {@see getName() name}, and the payload {@see getData() bytes}.
 * {@see getResourceName()} and {@see getDescription()} answer the id's meaning from
 * the {@see PhotoshopResourceNames} vocabulary, and the typed decoders unpack the
 * documented resources: {@see decodeResolutionInfo()} (0x03ED),
 * {@see decodeJpegQuality()} (0x0406), {@see decodeGridGuides()} (0x0408),
 * {@see decodeThumbnail()} (0x0409/0x040C), {@see decodeBoolean()} (0x040A copyright
 * flag and similar), {@see decodeText()} (0x040B URL and similar),
 * {@see decodeInteger()} (0x040D global angle, 0x0419 global altitude),
 * {@see decodeVersionInfo()} (0x0421), {@see decodeHalftone()} (0x03F4/0x03F5), and
 * {@see decodeTransferFunction()} (0x03F7/0x03F8).
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class PhotoshopResource
{
	public const ResolutionInfo = 0x03ED;
	public const GrayscaleHalftone = 0x03F4;
	public const ColorHalftone = 0x03F5;
	public const GrayscaleTransferFunction = 0x03F7;
	public const ColorTransferFunction = 0x03F8;
	/** The Caption String resource. */
	public const CaptionString = 0x03F0;

	public const IptcNaa = 0x0404;
	public const JpegQuality = 0x0406;
	public const GridAndGuides = 0x0408;
	public const Thumbnail4 = 0x0409;
	public const CopyrightFlag = 0x040A;
	public const Url = 0x040B;
	public const Thumbnail5 = 0x040C;

	/** The Watermark resource. */
	public const Watermark = 0x0410;

	/** The Workflow URL resource. */
	public const WorkflowUrl = 0x041B;
	public const GlobalAngle = 0x040D;
	public const ICCProfile = 0x040F;
	public const ICCUntagged = 0x0411;
	public const DocumentIds = 0x0414;
	public const GlobalAltitude = 0x0419;
	public const Slices = 0x041A;
	public const UrlList = 0x041E;
	public const VersionInfo = 0x0421;

	/** @var int The resource id. */
	private int $_id;

	/** @var string The Pascal-string resource name (usually ''). */
	private string $_name;

	/** @var string The payload bytes. */
	private string $_data;

	/**
	 * Constructs a resource.
	 * @param int $id The resource id.
	 * @param string $data The payload bytes.
	 * @param string $name The resource name. Default ''.
	 */
	public function __construct(int $id, string $data = '', string $name = '')
	{
		$this->_id = $id;
		$this->_data = $data;
		$this->_name = $name;
	}

	/**
	 * Returns the resource id.
	 * @return int The id.
	 */
	public function getId(): int
	{
		return $this->_id;
	}

	/**
	 * Returns the resource name.
	 * @return string The Pascal-string name (usually '').
	 */
	public function getName(): string
	{
		return $this->_name;
	}

	/**
	 * Sets the resource name.
	 * @param string $value The name.
	 */
	public function setName(string $value): void
	{
		$this->_name = $value;
	}

	/**
	 * Returns the payload bytes.
	 * @return string The data.
	 */
	public function getData(): string
	{
		return $this->_data;
	}

	/**
	 * Sets the payload bytes.
	 * @param string $value The data.
	 */
	public function setData(string $value): void
	{
		$this->_data = $value;
	}

	/**
	 * Returns the id's standard name.
	 * @return ?string The name, or null when unknown.
	 */
	public function getResourceName(): ?string
	{
		return PhotoshopResourceNames::nameOf($this->_id);
	}

	/**
	 * Returns the id's standard description.
	 * @return ?string The description, or null when unknown.
	 */
	public function getDescription(): ?string
	{
		return PhotoshopResourceNames::describe($this->_id);
	}

	/**
	 * Decodes a ResolutionInfo resource: resolutions as 16.16 fixed-point dpi and the
	 * display units.
	 * @return ?array The hRes/hResUnit/widthUnit/vRes/vResUnit/heightUnit set, or null.
	 */
	public function decodeResolutionInfo(): ?array
	{
		if (strlen($this->_data) < 16) {
			return null;
		}
		$fields = unpack('NhFixed/nhResUnit/nwidthUnit/NvFixed/nvResUnit/nheightUnit', $this->_data);
		return [
			'hRes' => $fields['hFixed'] / 0x10000,
			'hResUnit' => $fields['hResUnit'],
			'widthUnit' => $fields['widthUnit'],
			'vRes' => $fields['vFixed'] / 0x10000,
			'vResUnit' => $fields['vResUnit'],
			'heightUnit' => $fields['heightUnit'],
		];
	}

	/**
	 * Decodes a JPEG quality resource: the 1-12 quality scale, the format, and the
	 * progressive scan count.
	 * @return ?array The quality/format/progressiveScans set, or null.
	 */
	public function decodeJpegQuality(): ?array
	{
		if (strlen($this->_data) < 6) {
			return null;
		}
		$fields = unpack('nquality/nformat/nscans', $this->_data);
		$quality = $fields['quality'];
		$quality = $quality >= 0xFFFD ? $quality - 0x10000 + 4 : $quality + 4;   // signed offset scale
		return [
			'quality' => $quality,
			'format' => match ($fields['format']) {
				0x0000 => 'Standard',
				0x0001 => 'Optimised',
				0x0101 => 'Progressive',
				default => sprintf('Unknown (0x%04X)', $fields['format']),
			},
			'progressiveScans' => $fields['scans'] + 2,
		];
	}

	/**
	 * Decodes a grid-and-guides resource.
	 * @return ?array The version, grid cycles, and guides ([location, direction]), or null.
	 */
	public function decodeGridGuides(): ?array
	{
		if (strlen($this->_data) < 16) {
			return null;
		}
		$fields = unpack('Nversion/NgridH/NgridV/Ncount', $this->_data);
		$guides = [];
		for ($i = 0; $i < $fields['count'] && 16 + $i * 5 + 5 <= strlen($this->_data); $i++) {
			$guide = unpack('Nlocation/Cdirection', substr($this->_data, 16 + $i * 5, 5));
			$guides[] = [
				'location' => $guide['location'] / 32.0,
				'direction' => $guide['direction'] === 0 ? 'vertical' : 'horizontal',
			];
		}
		return ['version' => $fields['version'], 'gridHorizontal' => $fields['gridH'], 'gridVertical' => $fields['gridV'], 'guides' => $guides];
	}

	/**
	 * Decodes a thumbnail resource to its JPEG bytes and dimensions.
	 * @return ?array The format/width/height/jpeg set, or null.
	 */
	public function decodeThumbnail(): ?array
	{
		if (strlen($this->_data) < 28) {
			return null;
		}
		$fields = unpack('Nformat/Nwidth/Nheight/NwidthBytes/Nsize/Ncompressed/nbits/nplanes', $this->_data);
		return [
			'format' => $fields['format'],   // 1 = JPEG RGB
			'width' => $fields['width'],
			'height' => $fields['height'],
			'jpeg' => substr($this->_data, 28),
		];
	}

	/**
	 * Decodes a one-byte boolean resource (the copyright flag).
	 * @return ?bool The flag, or null.
	 */
	public function decodeBoolean(): ?bool
	{
		return $this->_data === '' ? null : ord($this->_data[0]) !== 0;
	}

	/**
	 * Decodes a plain-text resource (the URL).
	 * @return string The text.
	 */
	public function decodeText(): string
	{
		return rtrim($this->_data, "\0");
	}

	/**
	 * Decodes a big-endian 32-bit integer resource (global angle/altitude).
	 * @return ?int The value, or null.
	 */
	public function decodeInteger(): ?int
	{
		return strlen($this->_data) >= 4 ? unpack('N', $this->_data)[1] : null;
	}

	/**
	 * Decodes a version-info resource.
	 * @return ?array The version/hasRealMergedData/writer/reader/fileVersion set, or null.
	 */
	public function decodeVersionInfo(): ?array
	{
		if (strlen($this->_data) < 9) {
			return null;
		}
		$version = unpack('N', $this->_data)[1];
		$hasMerged = ord($this->_data[4]) !== 0;
		$pos = 5;
		$writer = $this->readUnicodeString($pos);
		$reader = $this->readUnicodeString($pos);
		$fileVersion = strlen($this->_data) >= $pos + 4 ? unpack('N', substr($this->_data, $pos, 4))[1] : null;
		return ['version' => $version, 'hasRealMergedData' => $hasMerged, 'writer' => $writer, 'reader' => $reader, 'fileVersion' => $fileVersion];
	}

	/**
	 * Decodes a halftone-screen resource: frequency, angle, and dot shape per channel.
	 * @return array The per-channel frequency/angle/shape sets.
	 */
	public function decodeHalftone(): array
	{
		$shapes = [0 => 'Round', 1 => 'Ellipse', 2 => 'Line', 3 => 'Square', 4 => 'Cross', 6 => 'Diamond'];
		$channels = [];
		for ($pos = 0; $pos + 18 <= strlen($this->_data); $pos += 18) {
			$fields = unpack('Nfreq/nfreqScale/Nangle/nangleScale/nshape', substr($this->_data, $pos, 14));
			$channels[] = [
				'frequency' => $fields['freq'] / 0x10000,
				'angle' => $fields['angle'] / 0x10000,
				'shape' => $shapes[$fields['shape']] ?? "Unknown ({$fields['shape']})",
			];
		}
		return $channels;
	}

	/**
	 * Decodes a transfer-function resource: the 13-point ink curve (in 0.1% units,
	 * -1 marking an unset point) and the override flag, per channel.
	 * @return array The per-channel curve/override sets.
	 */
	public function decodeTransferFunction(): array
	{
		$channels = [];
		for ($pos = 0; $pos + 28 <= strlen($this->_data); $pos += 28) {
			$points = array_values(unpack('n13', substr($this->_data, $pos, 26)));
			$curve = array_map(fn ($v) => $v === 0xFFFF ? -1 : $v / 10.0, $points);
			$override = unpack('n', substr($this->_data, $pos + 26, 2))[1];
			$channels[] = ['curve' => $curve, 'override' => $override !== 0];
		}
		return $channels;
	}

	/**
	 * Reads a Photoshop unicode string (u32 length then UTF-16BE code units), advancing
	 * the position.
	 * @param int &$pos The read position.
	 * @return string The UTF-8 text.
	 */
	protected function readUnicodeString(int &$pos): string
	{
		if (strlen($this->_data) < $pos + 4) {
			return '';
		}
		$length = (int) unpack('N', substr($this->_data, $pos, 4))[1];
		$pos += 4;
		$utf16 = substr($this->_data, $pos, $length * 2);
		$pos += $length * 2;
		$decoded = @iconv('UTF-16BE', 'UTF-8//IGNORE', $utf16);
		return rtrim($decoded === false ? '' : $decoded, "\0");
	}
}
