<?php

/**
 * PhotoshopIRB class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image;

use Belisoful\Image\Meta\IPTC;

/**
 * PhotoshopIRB class.
 *
 * The Photoshop image-resource block set: every 8BIM resource of a JPEG APP13
 * `Photoshop 3.0` segment (or the EXIF/TIFF tag 34377), parsed to
 * {@see PhotoshopResource} objects and rewritten byte-faithfully.  {@see parse()}
 * tolerates Photoshop's own spec deviations by resynchronizing on the `8BIM`
 * signature; {@see toBinary()} re-packs the set, and {@see toSegments()} splits it
 * into the 32000-byte APP13 payload chunks Photoshop writes.
 *
 * The embedded metadata is bridged: {@see getIPTC()}/{@see setIPTC()} read and write
 * the IPTC-NAA record (0x0404), {@see getThumbnail()} answers the Photoshop 4/5
 * thumbnail's JPEG bytes (0x0409/0x040C), and {@see getICCProfile()} the raw ICC
 * profile resource (0x040F); the per-resource decoders live on
 * {@see PhotoshopResource}.
 *
 * ```php
 * $irb = PhotoshopIRB::parse($app13Payload);
 * $irb->getIPTC()['Keywords'];
 * $irb->getResource(PhotoshopResource::JpegQuality)?->decodeJpegQuality();
 * $segments = $irb->toSegments();                 // APP13 payloads, chunked
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class PhotoshopIRB implements \IteratorAggregate, \Countable, PrivacyScrubbableInterface
{
	use StreamIOTrait;

	/** The APP13 segment signature. */
	public const Signature = "Photoshop 3.0\x00";

	/** The 8BIM resource signature. */
	public const ResourceSignature = '8BIM';

	/** The maximum resource bytes per APP13 segment chunk. */
	public const ChunkSize = 32000;

	/** @var PhotoshopResource[] The resources, in file order. */
	private array $_resources = [];

	/**
	 * Indicates whether a payload is a Photoshop APP13 block or bare 8BIM data.
	 * @param string $data The candidate payload.
	 * @return bool Whether 8BIM resources can be parsed from it.
	 */
	public static function isIRB(string $data): bool
	{
		return str_starts_with($data, self::Signature) || str_starts_with($data, self::ResourceSignature);
	}

	/**
	 * Parses an APP13 payload (or bare 8BIM data), resynchronizing on the 8BIM
	 * signature to tolerate the padding deviations Photoshop itself writes.
	 * @param string $data The payload; multiple concatenated APP13 payloads are handled.
	 * @return false|PhotoshopIRB The parsed set, or false when no resource was found.
	 */
	public static function parse(string $data): false|PhotoshopIRB
	{
		// Strip every embedded segment signature (each APP13 chunk repeats it).
		$data = str_replace(self::Signature, '', $data);
		$irb = new self();
		$len = strlen($data);
		$pos = strpos($data, self::ResourceSignature);
		while ($pos !== false && $pos + 10 <= $len) {
			$id = unpack('n', substr($data, $pos + 4, 2))[1];
			$nameLength = ord($data[$pos + 6]);
			$name = substr($data, $pos + 7, $nameLength);
			$namePadded = 1 + $nameLength + (($nameLength + 1) & 1);   // padded to even
			$sizeAt = $pos + 6 + $namePadded;
			if ($sizeAt + 4 > $len) {
				break;
			}
			$size = unpack('N', substr($data, $sizeAt, 4))[1];
			$payload = substr($data, $sizeAt + 4, $size);
			$irb->_resources[] = new PhotoshopResource($id, $payload, $name);
			$next = min($len, max($sizeAt + 4 + $size + ($size & 1), $pos + 4));
			// Resynchronize: Photoshop sometimes mispads, so trust the next signature.
			$pos = strpos($data, self::ResourceSignature, $next);
		}
		return $irb->_resources === [] ? false : $irb;
	}

	/**
	 * Parses 8BIM resources from a PSR-7 stream or stream resource, reading from the
	 * current position to the end.
	 * @param mixed $stream The {@see \Psr\Http\Message\StreamInterface} or PHP stream resource.
	 * @return false|PhotoshopIRB The parsed set, or false when no resource was found.
	 */
	public static function fromStream(mixed $stream): false|PhotoshopIRB
	{
		return static::parse(static::sourceBytes($stream));
	}

	/**
	 * Returns the resources in file order.
	 * @return PhotoshopResource[] The resources.
	 */
	public function getResources(): array
	{
		return $this->_resources;
	}

	/**
	 * Returns the first resource with an id.
	 * @param int $id The resource id.
	 * @return ?PhotoshopResource The resource, or null when absent.
	 */
	public function getResource(int $id): ?PhotoshopResource
	{
		foreach ($this->_resources as $resource) {
			if ($resource->getId() === $id) {
				return $resource;
			}
		}
		return null;
	}

	/**
	 * Stores a resource: replaces the first with the same id, or appends.
	 * @param PhotoshopResource $resource The resource.
	 */
	public function setResource(PhotoshopResource $resource): void
	{
		foreach ($this->_resources as $i => $existing) {
			if ($existing->getId() === $resource->getId()) {
				$this->_resources[$i] = $resource;
				return;
			}
		}
		$this->_resources[] = $resource;
	}

	/**
	 * Removes every resource with an id.
	 * @param int $id The resource id.
	 * @return bool Whether a resource was removed.
	 */
	public function removeResource(int $id): bool
	{
		$before = count($this->_resources);
		$this->_resources = array_values(array_filter($this->_resources, fn ($r) => $r->getId() !== $id));
		return count($this->_resources) !== $before;
	}

	/**
	 * Returns the number of resources.
	 * @return int The resource count.
	 */
	public function count(): int
	{
		return count($this->_resources);
	}

	/**
	 * Iterates the resources in file order.
	 * @return \Iterator The resource iterator.
	 */
	public function getIterator(): \Iterator
	{
		return new \ArrayIterator($this->_resources);
	}

	/**
	 * Returns the embedded IPTC record set (resource 0x0404).
	 * @return ?IPTC The IPTC, or null when absent or unparsable.
	 */
	public function getIPTC(): ?IPTC
	{
		$resource = $this->getResource(PhotoshopResource::IptcNaa);
		if ($resource === null) {
			return null;
		}
		$data = $resource->getData();
		$iptc = IPTC::parse($data);
		return $iptc === false ? null : $iptc;
	}

	/**
	 * Sets (or removes, when null) the embedded IPTC record set.
	 * @param ?IPTC $iptc The IPTC, or null to remove the resource.
	 */
	public function setIPTC(?IPTC $iptc): void
	{
		if ($iptc === null) {
			$this->removeResource(PhotoshopResource::IptcNaa);
		} else {
			$this->setResource(new PhotoshopResource(PhotoshopResource::IptcNaa, $iptc->toBinary(null, false)));
		}
	}

	//
	// ─── Privacy ─────────────────────────────────────────────────────────────
	//

	/**
	 * @var array<int, array<int, int>> The image resources each {@see PrivacyCategory}
	 *   flag removes.  The embedded IPTC record set (0x0404) is not listed: it is scrubbed
	 *   through {@see \Belisoful\Image\Meta\IPTC::clearPrivateData()} so its picture-describing
	 *   datasets survive.
	 */
	protected const PrivacyResources = [
		PrivacyCategory::Author => [
			PhotoshopResource::CopyrightFlag,
			PhotoshopResource::Url,
			PhotoshopResource::WorkflowUrl,
			PhotoshopResource::UrlList,
		],
		PrivacyCategory::Description => [
			PhotoshopResource::CaptionString,
			PhotoshopResource::Watermark,
		],
		PrivacyCategory::Software => [
			PhotoshopResource::VersionInfo,
		],
		PrivacyCategory::Thumbnail => [
			PhotoshopResource::Thumbnail4,
			PhotoshopResource::Thumbnail5,
		],
	];

	/**
	 * Removes identifying information from the resource block by category: the caption,
	 * copyright and URL resources, the version info, and the embedded thumbnails — and
	 * scrubs the embedded IPTC record set with the same flags, so it is redacted rather
	 * than dropped.  Layout, resolution, guide, and colour resources are left.
	 * @param int $types The {@see PrivacyCategory} flags to remove. Default {@see PrivacyCategory::All}.
	 * @return int The number of resources and IPTC datasets removed.
	 */
	public function clearPrivateData(int $types = PrivacyCategory::All): int
	{
		$removed = 0;
		foreach (self::PrivacyResources as $flag => $ids) {
			if (($types & $flag) === 0) {
				continue;
			}
			foreach ($ids as $id) {
				if ($this->removeResource($id)) {
					$removed++;
				}
			}
		}
		$iptc = $this->getIPTC();
		if ($iptc !== null) {
			$count = $iptc->clearPrivateData($types);
			if ($count > 0) {
				$this->setIPTC($iptc);
				$removed += $count;
			}
		}
		return $removed;
	}

	/**
	 * Returns the Photoshop thumbnail's JPEG bytes (resource 0x040C, falling back to
	 * the Photoshop 4.0 form 0x0409).
	 * @return ?string The thumbnail JPEG, or null when absent.
	 */
	public function getThumbnail(): ?string
	{
		$resource = $this->getResource(PhotoshopResource::Thumbnail5)
			?? $this->getResource(PhotoshopResource::Thumbnail4);
		$decoded = $resource?->decodeThumbnail();
		return $decoded === null || $decoded['jpeg'] === '' ? null : $decoded['jpeg'];
	}

	/**
	 * Returns the embedded ICC profile bytes (resource 0x040F).
	 * @return ?string The ICC profile, or null when absent.
	 */
	public function getICCProfile(): ?string
	{
		return $this->getResource(PhotoshopResource::ICCProfile)?->getData();
	}

	/**
	 * Packs the resources back to 8BIM bytes (without the APP13 signature).
	 * @return string The 8BIM resource bytes.
	 */
	public function toBinary(): string
	{
		$out = '';
		foreach ($this->_resources as $resource) {
			$name = $resource->getName();
			$pascal = chr(strlen($name)) . $name;
			if (strlen($pascal) & 1) {
				$pascal .= "\0";
			}
			$data = $resource->getData();
			$out .= self::ResourceSignature . pack('n', $resource->getId()) . $pascal . pack('N', strlen($data)) . $data;
			if (strlen($data) & 1) {
				$out .= "\0";
			}
		}
		return $out;
	}

	/**
	 * Packs the resources into APP13 segment payloads, signature included, chunked at
	 * {@see ChunkSize} bytes the way Photoshop writes large blocks.
	 * @return string[] The APP13 payloads, in order.
	 */
	public function toSegments(): array
	{
		$binary = $this->toBinary();
		if ($binary === '') {
			return [];
		}
		return array_map(fn ($chunk) => self::Signature . $chunk, str_split($binary, self::ChunkSize));
	}
}
