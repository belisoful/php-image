<?php

use Belisoful\Image\PrivacyScrubbableInterface;
use Belisoful\Image\Meta\EXIF;
use Belisoful\Image\Meta\IPTC;
use Belisoful\Image\Meta\IPTCTags;
use Belisoful\Image\Meta\JFXX;
use Belisoful\Image\Meta\XMP;
use Belisoful\Image\GIFImage;
use Belisoful\Image\ImageChunk;
use Belisoful\Image\JPEGImage;
use Belisoful\Image\PhotoshopIRB;
use Belisoful\Image\PhotoshopResource;
use Belisoful\Image\PNGImage;
use Belisoful\Image\PrivacyCategory;
use Belisoful\Image\TIFFImage;
use Belisoful\Image\WebPImage;

/**
 * The metadata-wide privacy scrub: PrivacyCategory flags applied uniformly to every
 * carrier (XMP, IPTC, the Photoshop IRB — EXIF has its own suite) and fanned out by
 * every container to everything it holds.  A category must remove exactly its facts in
 * each carrier and leave the picture-describing fields, and a container scrub must reach
 * every carrier the format has.
 */
class PrivacyScrubTest extends PHPUnit\Framework\TestCase
{
	//
	// ─── The contract ────────────────────────────────────────────────────────
	//

	public function testEveryCarrierAndContainerImplementsTheContract()
	{
		foreach ([EXIF::class, XMP::class, IPTC::class, PhotoshopIRB::class,
			JPEGImage::class, PNGImage::class, TIFFImage::class, WebPImage::class, GIFImage::class] as $class) {
			self::assertContains(PrivacyScrubbableInterface::class, class_implements($class), "$class implements PrivacyScrubbableInterface");
		}
	}

	public function testCategoryBitsAreDistinctAndAllIsEveryBit()
	{
		$flags = [PrivacyCategory::Location, PrivacyCategory::Author, PrivacyCategory::Description,
			PrivacyCategory::CameraModel, PrivacyCategory::SerialNumber, PrivacyCategory::Timestamp,
			PrivacyCategory::Software, PrivacyCategory::MakerNote, PrivacyCategory::Thumbnail,
			PrivacyCategory::Interoperability];
		self::assertCount(count($flags), array_unique($flags));
		foreach ($flags as $flag) {
			self::assertSame(1, substr_count(decbin($flag), '1'), 'one bit per category');
			self::assertSame($flag, PrivacyCategory::All & $flag);
		}
		self::assertSame(-1, PrivacyCategory::All);
		self::assertSame(PrivacyCategory::Author | PrivacyCategory::SerialNumber | PrivacyCategory::MakerNote, PrivacyCategory::Identity);
		self::assertSame(PrivacyCategory::Location | PrivacyCategory::Timestamp | PrivacyCategory::Software, PrivacyCategory::Provenance);
	}

	//
	// ─── XMP ─────────────────────────────────────────────────────────────────
	//

	private function loadedXmp(): XMP
	{
		$xmp = XMP::blank();
		$xmp->setProperty(XMP::NS_DC, 'creator', 'Jane Doe');
		$xmp->setProperty(XMP::NS_DC, 'rights', '(c) Jane');
		$xmp->setProperty(XMP::NS_DC, 'description', 'At home with the kids');
		$xmp->setProperty(XMP::NS_DC, 'subject', ['family', 'home']);
		$xmp->setProperty(XMP::NS_XMP, 'CreateDate', '2026-01-01T12:00:00');
		$xmp->setProperty(XMP::NS_XMP, 'CreatorTool', 'Editor 1.0');
		$xmp->setProperty(XMP::NS_TIFF, 'Make', 'TestCam');
		$xmp->setProperty(XMP::NS_TIFF, 'Model', 'ZZ-1');
		$xmp->setProperty(XMP::NS_EXIF, 'GPSLatitude', '34,3.00N');
		$xmp->setProperty(XMP::NS_EXIF, 'GPSLongitude', '118,14.40W');
		$xmp->setProperty(XMP::NS_PHOTOSHOP, 'City', 'Los Angeles');
		$xmp->setProperty(XMP::NS_EXIF_AUX, 'SerialNumber', 'SN-000123');
		$xmp->setProperty(XMP::NS_MM, 'DocumentID', 'xmp.did:abc');
		$xmp->setProperty(XMP::NS_MM, 'InstanceID', 'xmp.iid:def');
		// non-identifying picture properties that must survive
		$xmp->setProperty(XMP::NS_EXIF, 'FNumber', '28/10');
		$xmp->setProperty(XMP::NS_EXIF, 'ExposureTime', '1/125');
		$xmp->setProperty(XMP::NS_TIFF, 'ImageWidth', '4000');
		return XMP::parse($xmp->toPacketText());   // reparse: realistic
	}

	private function xmpProbes(): array
	{
		return [
			'Location' => fn (XMP $x): bool => $x->getProperty(XMP::NS_EXIF, 'GPSLatitude') !== null,
			'Author' => fn (XMP $x): bool => $x->getProperty(XMP::NS_DC, 'creator') !== null,
			'Description' => fn (XMP $x): bool => $x->getProperty(XMP::NS_DC, 'description') !== null,
			'CameraModel' => fn (XMP $x): bool => $x->getProperty(XMP::NS_TIFF, 'Make') !== null,
			'SerialNumber' => fn (XMP $x): bool => $x->getProperty(XMP::NS_EXIF_AUX, 'SerialNumber') !== null,
			'Timestamp' => fn (XMP $x): bool => $x->getProperty(XMP::NS_XMP, 'CreateDate') !== null,
			'Software' => fn (XMP $x): bool => $x->getProperty(XMP::NS_XMP, 'CreatorTool') !== null,
		];
	}

	public function testXmpEachFlagRemovesExactlyItsCategory()
	{
		foreach ($this->xmpProbes() as $name => $probe) {
			$xmp = $this->loadedXmp();
			$xmp->clearPrivateData(constant(PrivacyCategory::class . '::' . $name));
			$xmp = XMP::parse($xmp->toPacketText());
			self::assertFalse($probe($xmp), "XMP $name should be removed by its flag");
			foreach ($this->xmpProbes() as $other => $otherProbe) {
				if ($other !== $name) {
					self::assertTrue($otherProbe($xmp), "XMP $name flag must not remove $other");
				}
			}
			self::assertSame('28/10', $xmp->getProperty(XMP::NS_EXIF, 'FNumber'), "$name keeps FNumber");
			self::assertSame('4000', $xmp->getProperty(XMP::NS_TIFF, 'ImageWidth'), "$name keeps ImageWidth");
		}
	}

	public function testXmpAllClearsEverythingIdentifying()
	{
		$xmp = $this->loadedXmp();
		$removed = $xmp->clearPrivateData();
		self::assertGreaterThanOrEqual(14, $removed);
		$xmp = XMP::parse($xmp->toPacketText());
		foreach ($this->xmpProbes() as $name => $probe) {
			self::assertFalse($probe($xmp), "All should remove XMP $name");
		}
		// The whole category, not just the probe property.
		foreach ([[XMP::NS_DC, 'rights'], [XMP::NS_DC, 'subject'], [XMP::NS_TIFF, 'Model'],
			[XMP::NS_EXIF, 'GPSLongitude'], [XMP::NS_PHOTOSHOP, 'City'], [XMP::NS_MM, 'DocumentID'],
			[XMP::NS_MM, 'InstanceID']] as [$ns, $prop]) {
			self::assertNull($xmp->getProperty($ns, $prop), "$prop should be gone");
		}
		self::assertSame('28/10', $xmp->getProperty(XMP::NS_EXIF, 'FNumber'));
		self::assertSame('1/125', $xmp->getProperty(XMP::NS_EXIF, 'ExposureTime'));
		self::assertSame(0, $xmp->clearPrivateData(), 'idempotent');
		self::assertSame(0, XMP::blank()->clearPrivateData(), 'empty packet is safe');
	}

	//
	// ─── IPTC ────────────────────────────────────────────────────────────────
	//

	private function loadedIptc(): IPTC
	{
		$iptc = new IPTC();
		$iptc[IPTCTags::ByLine] = ['Jane Doe'];
		$iptc[IPTCTags::Credit] = 'Jane Photo';
		$iptc[IPTCTags::CopyrightNotice] = '(c) Jane';
		$iptc[IPTCTags::City] = 'Los Angeles';
		$iptc[IPTCTags::ProvinceState] = 'California';
		$iptc[IPTCTags::CountryPrimaryLocationName] = 'USA';
		$iptc[IPTCTags::CaptionAbstract] = 'At home';
		$iptc[IPTCTags::Keywords] = ['family'];
		$iptc[IPTCTags::Headline] = 'Birthday';
		$iptc[IPTCTags::DateCreated] = '20260101';
		$iptc[IPTCTags::TimeCreated] = '120000+0000';
		$iptc[IPTCTags::OriginatingProgram] = 'Editor';
		$iptc[IPTCTags::UniqueDocumentID] = 'doc-1';
		$iptc[IPTCTags::ExifCameraInfo] = "II\x2A\x00camera";
		$iptc[IPTCTags::ServiceIdentifier] = 'SVC';
		$iptc[IPTCTags::DateSent] = '20260101';
		// non-identifying NewsPhoto fields that must survive
		$iptc[IPTCTags::ImageOrientation] = 'L';
		$iptc[IPTCTags::IPTCBitsPerSample] = 8;
		$bytes = $iptc->toBinary(false);
		return IPTC::parse($bytes);   // reparse: realistic
	}

	private function iptcProbes(): array
	{
		return [
			'Location' => fn (IPTC $i): bool => $i->contains(IPTCTags::City),
			'Author' => fn (IPTC $i): bool => $i->contains(IPTCTags::ByLine),
			'Description' => fn (IPTC $i): bool => $i->contains(IPTCTags::CaptionAbstract),
			'CameraModel' => fn (IPTC $i): bool => $i->contains(IPTCTags::ExifCameraInfo),
			'SerialNumber' => fn (IPTC $i): bool => $i->contains(IPTCTags::UniqueDocumentID),
			'Timestamp' => fn (IPTC $i): bool => $i->contains(IPTCTags::DateCreated),
			'Software' => fn (IPTC $i): bool => $i->contains(IPTCTags::OriginatingProgram),
		];
	}

	public function testIptcEachFlagRemovesExactlyItsCategory()
	{
		foreach ($this->iptcProbes() as $name => $probe) {
			$iptc = $this->loadedIptc();
			$iptc->clearPrivateData(constant(PrivacyCategory::class . '::' . $name));
			$bytes = $iptc->toBinary(false);
			$iptc = IPTC::parse($bytes);
			self::assertFalse($probe($iptc), "IPTC $name should be removed by its flag");
			foreach ($this->iptcProbes() as $other => $otherProbe) {
				if ($other !== $name) {
					self::assertTrue($otherProbe($iptc), "IPTC $name flag must not remove $other");
				}
			}
			self::assertTrue($iptc->contains(IPTCTags::ImageOrientation), "$name keeps ImageOrientation");
			self::assertTrue($iptc->contains(IPTCTags::IPTCBitsPerSample), "$name keeps BitsPerSample");
		}
	}

	public function testIptcAllClearsEverythingAndDoesNotRegenerateTheEnvelopeNumber()
	{
		$iptc = $this->loadedIptc();
		// IIM mandates DateSent, ServiceIdentifier, and EnvelopeNumber, and toBinary()
		// refills any missing one with TODAY'S date and a fresh identifier -- which would
		// stamp a new timestamp onto a scrubbed file.  A scrub pins them to a fixed,
		// obviously synthetic sentinel instead, and the result must be stable across writes.
		self::assertTrue($iptc->contains(IPTCTags::EnvelopeNumber));
		$iptc->clearPrivateData();
		self::assertSame(IPTC::ScrubbedDate, $iptc[IPTCTags::DateSent], 'DateSent is the sentinel, not today');
		self::assertSame(
			IPTC::computeEnvelopeNumber($iptc[IPTCTags::ServiceIdentifier], IPTC::ScrubbedDate),
			$iptc[IPTCTags::EnvelopeNumber],
			'the envelope number derives from the sentinel, so it is constant and reveals nothing',
		);
		self::assertNotSame(IPTC::formatIPTCDate(), $iptc[IPTCTags::DateSent], 'not today');

		$bytes = $iptc->toBinary(false);
		$iptc = IPTC::parse($bytes);
		foreach ($this->iptcProbes() as $name => $probe) {
			self::assertFalse($probe($iptc), "All should remove IPTC $name");
		}
		foreach ([IPTCTags::Credit, IPTCTags::CopyrightNotice, IPTCTags::ProvinceState,
			IPTCTags::CountryPrimaryLocationName, IPTCTags::Keywords, IPTCTags::Headline,
			IPTCTags::TimeCreated] as $dataset) {
			self::assertFalse($iptc->contains($dataset), "$dataset should be gone");
		}
		// The mandatory envelope stays, pinned to the sentinel, and the reparse kept it that way.
		self::assertSame('SVC', $iptc[IPTCTags::ServiceIdentifier], 'an existing service id is kept, not re-minted');
		self::assertSame(IPTC::ScrubbedDate, $iptc[IPTCTags::DateSent]);
		self::assertTrue($iptc->contains(IPTCTags::ImageOrientation));
		self::assertSame(0, $iptc->clearPrivateData(), 'idempotent');
		self::assertSame(0, (new IPTC())->clearPrivateData(), 'empty set is safe');
	}

	public function testIptcEnvelopeDateIsReplacedNeverMinted()
	{
		// A record set whose only identifying fact is its envelope date: the replacement
		// counts, so a container knows to write the carrier back.
		$iptc = new IPTC();
		$iptc[IPTCTags::ServiceIdentifier] = 'SVC';
		$iptc[IPTCTags::DateSent] = '20260101';
		$iptc[IPTCTags::ImageOrientation] = 'L';
		$bytes = $iptc->toBinary();   // validate() derives the envelope number, as any written record set has
		$iptc = IPTC::parse($bytes);
		self::assertTrue($iptc->contains(IPTCTags::EnvelopeNumber));
		self::assertSame(1, $iptc->clearPrivateData(PrivacyCategory::Timestamp), 'the date is replaced (its envelope number follows)');
		self::assertSame(IPTC::ScrubbedDate, $iptc[IPTCTags::DateSent]);
		self::assertSame(IPTC::computeEnvelopeNumber('SVC', IPTC::ScrubbedDate), $iptc[IPTCTags::EnvelopeNumber]);
		self::assertSame(0, $iptc->clearPrivateData(PrivacyCategory::Timestamp), 'idempotent');

		// A record set with no envelope yet is not given one: nothing is minted.
		$fresh = new IPTC();
		$fresh[IPTCTags::ImageOrientation] = 'L';
		self::assertSame(0, $fresh->clearPrivateData());
		self::assertFalse($fresh->contains(IPTCTags::DateSent));
		self::assertFalse($fresh->contains(IPTCTags::EnvelopeNumber));
	}

	//
	// ─── Photoshop IRB ───────────────────────────────────────────────────────
	//

	public function testIrbRemovesItsOwnResourcesAndRedactsTheEmbeddedIptc()
	{
		$irb = new PhotoshopIRB();
		$irb->setResource(new PhotoshopResource(PhotoshopResource::Url, 'http://jane.example'));
		$irb->setResource(new PhotoshopResource(PhotoshopResource::CopyrightFlag, "\x01"));
		$irb->setResource(new PhotoshopResource(PhotoshopResource::CaptionString, 'a caption'));
		$irb->setResource(new PhotoshopResource(PhotoshopResource::VersionInfo, 'version bytes'));
		$irb->setResource(new PhotoshopResource(PhotoshopResource::Thumbnail5, 'thumb bytes'));
		$irb->setResource(new PhotoshopResource(0x03ED, 'resolution info'));   // non-identifying
		$iptc = new IPTC();
		$iptc[IPTCTags::ByLine] = ['Jane'];
		$iptc[IPTCTags::ImageOrientation] = 'L';
		$irb->setIPTC($iptc);
		$irb = PhotoshopIRB::parse($irb->toBinary());

		// One flag at a time.
		$author = PhotoshopIRB::parse($irb->toBinary());
		$author->clearPrivateData(PrivacyCategory::Author);
		self::assertNull($author->getResource(PhotoshopResource::Url));
		self::assertNull($author->getResource(PhotoshopResource::CopyrightFlag));
		self::assertNotNull($author->getResource(PhotoshopResource::CaptionString), 'Author does not remove the caption');
		self::assertNotNull($author->getResource(PhotoshopResource::Thumbnail5));
		self::assertFalse($author->getIPTC()->contains(IPTCTags::ByLine), 'the embedded IPTC by-line went with Author');
		self::assertTrue($author->getIPTC()->contains(IPTCTags::ImageOrientation), 'the embedded IPTC is redacted, not dropped');

		$thumb = PhotoshopIRB::parse($irb->toBinary());
		$thumb->clearPrivateData(PrivacyCategory::Thumbnail);
		self::assertNull($thumb->getResource(PhotoshopResource::Thumbnail5));
		self::assertNotNull($thumb->getResource(PhotoshopResource::Url));

		// Everything.
		$removed = $irb->clearPrivateData();
		self::assertGreaterThanOrEqual(6, $removed);
		$irb = PhotoshopIRB::parse($irb->toBinary());
		foreach ([PhotoshopResource::Url, PhotoshopResource::CopyrightFlag, PhotoshopResource::CaptionString,
			PhotoshopResource::VersionInfo, PhotoshopResource::Thumbnail5] as $id) {
			self::assertNull($irb->getResource($id), "resource $id should be gone");
		}
		self::assertNotNull($irb->getResource(0x03ED), 'the resolution info survives');
		self::assertNotNull($irb->getIPTC());
		self::assertTrue($irb->getIPTC()->contains(IPTCTags::ImageOrientation));
		self::assertSame(0, (new PhotoshopIRB())->clearPrivateData(), 'empty block is safe');
	}

	//
	// ─── Containers: one call reaches every carrier ──────────────────────────
	//

	private function gdImage(): \GdImage
	{
		$image = imagecreatetruecolor(6, 4);
		imagefilledrectangle($image, 0, 0, 5, 3, imagecolorallocate($image, 10, 120, 200));
		return $image;
	}

	private function encoded(string $function): string
	{
		ob_start();
		$function($this->gdImage());
		return (string) ob_get_clean();
	}

	private function loadedExif(): EXIF
	{
		$exif = new EXIF();
		$exif->setSignature('');
		$exif->setValueByName('Artist', 'Jane Doe');
		$exif->setValueByName('Make', 'TestCam');
		$exif->setValueByName('FNumber', 2.8);
		$exif->setLatitude(34.05);
		$exif->setLongitude(-118.24);
		return $exif;
	}

	private function loadedContainerXmp(): XMP
	{
		$xmp = XMP::blank();
		$xmp->setProperty(XMP::NS_DC, 'creator', 'Jane Doe');
		$xmp->setProperty(XMP::NS_EXIF, 'FNumber', '28/10');
		return $xmp;
	}

	private function loadedContainerIptc(): IPTC
	{
		$iptc = new IPTC();
		$iptc[IPTCTags::ByLine] = ['Jane Doe'];
		$iptc[IPTCTags::ImageOrientation] = 'L';
		return $iptc;
	}

	/**
	 * Loads every carrier a container supports, scrubs the container, reparses, and
	 * asserts each carrier was reached and its picture-describing fields survived.
	 * @param string $class The container class.
	 * @param string $bytes The bare container bytes.
	 * @param callable $extras Adds format-specific carriers to the container.
	 * @param callable $extrasGone Asserts the format-specific carriers were removed.
	 */
	private function assertContainerScrubReachesEveryCarrier(string $class, string $bytes, callable $extras, callable $extrasGone): void
	{
		$file = $class::fromString($bytes);
		if (method_exists($file, 'setEXIF')) {
			$file->setEXIF($this->loadedExif());
		} elseif ($file instanceof TIFFImage) {
			$exif = $file->getEXIF();
			$exif->setValueByName('Artist', 'Jane Doe');
			$exif->setValueByName('Make', 'TestCam');
			$exif->setValueByName('FNumber', 2.8);
			$exif->setLatitude(34.05);
			$exif->setLongitude(-118.24);
		}
		$file->setXMP($this->loadedContainerXmp());
		$hasIptc = false;
		try {
			$file->setIPTC($this->loadedContainerIptc());
			$hasIptc = true;
		} catch (\RuntimeException $e) {
			// WebP and GIF define no IPTC carrier.
		}
		$extras($file);
		$file = $class::fromString($file->toBinary());   // realistic: scrub a parsed file

		$removed = $file->clearPrivateData();
		self::assertGreaterThan(0, $removed, "$class removed something");
		$round = $class::fromString($file->toBinary());

		if (method_exists($round, 'getEXIF') && $round->getEXIF() !== null) {
			self::assertNull($round->getEXIF()->getValueByName('Artist'), "$class EXIF Artist gone");
			self::assertNull($round->getEXIF()->getValueByName('Make'), "$class EXIF Make gone");
			self::assertNull($round->getEXIF()->getLatitude(), "$class EXIF GPS gone");
			self::assertNotNull($round->getEXIF()->getValueByName('FNumber'), "$class EXIF FNumber kept");
		}
		self::assertNull($round->getXMP()?->getProperty(XMP::NS_DC, 'creator'), "$class XMP creator gone");
		self::assertSame('28/10', $round->getXMP()?->getProperty(XMP::NS_EXIF, 'FNumber'), "$class XMP FNumber kept");
		if ($hasIptc) {
			self::assertFalse($round->getIPTC()?->contains(IPTCTags::ByLine) ?? false, "$class IPTC by-line gone");
			self::assertTrue($round->getIPTC()?->contains(IPTCTags::ImageOrientation) ?? false, "$class IPTC orientation kept");
		}
		$extrasGone($round);

		// A second scrub finds nothing.
		self::assertSame(0, $round->clearPrivateData(), "$class scrub is idempotent");
	}

	public function testJpegScrubReachesEveryCarrier()
	{
		$this->assertContainerScrubReachesEveryCarrier(
			JPEGImage::class,
			$this->encoded('imagejpeg'),
			function (JPEGImage $jpeg): void {
				$jpeg->setComment('hi Jane');
				$jfxx = new JFXX();
				$jfxx->setImage($this->gdImage(), JFXX::JPEG_THUMB);
				$jpeg->setJFXX($jfxx);
			},
			function (JPEGImage $jpeg): void {
				self::assertNull($jpeg->getComment(), 'JPEG comment gone');
				self::assertNull($jpeg->getJFXX(), 'JFXX thumbnail gone');
			},
		);
	}

	public function testPngScrubReachesEveryCarrier()
	{
		$this->assertContainerScrubReachesEveryCarrier(
			PNGImage::class,
			$this->encoded('imagepng'),
			function (PNGImage $png): void {
				$png->addChunk(new ImageChunk('tEXt', 0, 0, "Author\0Jane Doe"));
				$png->addChunk(new ImageChunk('tEXt', 0, 0, "Comment\0hi Jane"));
			},
			function (PNGImage $png): void {
				foreach ($png->getChunks() as $chunk) {
					if ($chunk->getType() === 'tEXt') {
						self::assertStringStartsNotWith("Author\0", $chunk->getData(), 'PNG Author tEXt gone');
						self::assertStringStartsNotWith("Comment\0", $chunk->getData(), 'PNG Comment tEXt gone');
					}
				}
			},
		);
	}

	public function testTiffScrubReachesItsLiveExif()
	{
		$this->assertContainerScrubReachesEveryCarrier(
			TIFFImage::class,
			TIFFImage::fromImage($this->gdImage())->toBinary(),
			fn (TIFFImage $tiff) => null,
			fn (TIFFImage $tiff) => null,
		);
	}

	public function testWebPScrubReachesEveryCarrier()
	{
		if (!function_exists('imagewebp')) {
			self::markTestSkipped('GD lacks WebP support.');
		}
		$this->assertContainerScrubReachesEveryCarrier(
			WebPImage::class,
			$this->encoded('imagewebp'),
			fn (WebPImage $webp) => null,
			fn (WebPImage $webp) => null,
		);
	}

	public function testGifScrubReachesEveryCarrier()
	{
		$this->assertContainerScrubReachesEveryCarrier(
			GIFImage::class,
			$this->encoded('imagegif'),
			fn (GIFImage $gif) => $gif->addComment('hi Jane'),
			fn (GIFImage $gif) => self::assertSame([], $gif->getComments(), 'GIF comment gone'),
		);
	}

	public function testContainerFlagIsolation()
	{
		// A single flag on a container removes that category from every carrier and
		// nothing else from any of them.
		$jpeg = JPEGImage::fromString($this->encoded('imagejpeg'));
		$jpeg->setEXIF($this->loadedExif());
		$jpeg->setXMP($this->loadedContainerXmp());
		$jpeg->setIPTC($this->loadedContainerIptc());
		$jpeg->setComment('hi Jane');
		$jpeg = JPEGImage::fromString($jpeg->toBinary());

		$jpeg->clearPrivateData(PrivacyCategory::Location);
		$round = JPEGImage::fromString($jpeg->toBinary());
		self::assertNull($round->getEXIF()->getLatitude(), 'Location removed EXIF GPS');
		self::assertSame('Jane Doe', $round->getEXIF()->getValueByName('Artist'), 'Location kept EXIF Artist');
		self::assertSame('Jane Doe', $round->getXMP()->getProperty(XMP::NS_DC, 'creator'), 'Location kept XMP creator');
		self::assertTrue($round->getIPTC()->contains(IPTCTags::ByLine), 'Location kept IPTC by-line');
		self::assertSame('hi Jane', $round->getComment(), 'Location kept the comment');

		$round->clearPrivateData(PrivacyCategory::Author);
		$again = JPEGImage::fromString($round->toBinary());
		self::assertNull($again->getEXIF()->getValueByName('Artist'));
		self::assertNull($again->getXMP()->getProperty(XMP::NS_DC, 'creator'));
		self::assertFalse($again->getIPTC()->contains(IPTCTags::ByLine));
		self::assertSame('hi Jane', $again->getComment(), 'Author does not remove a comment');
		self::assertSame('TestCam', $again->getEXIF()->getValueByName('Make'), 'Author does not remove the camera');
	}

	public function testContainerWithNoMetadataIsSafe()
	{
		foreach (['imagepng' => PNGImage::class, 'imagegif' => GIFImage::class] as $fn => $class) {
			$file = $class::fromString($this->encoded($fn));
			self::assertSame(0, $file->clearPrivateData(), "$class with no metadata removes nothing");
			self::assertSame([6, 4], [$file->getWidth(), $file->getHeight()], "$class is untouched");
		}
	}

	public function testGdEncoderCommentIsTreatedAsSoftwareMetadata()
	{
		// GD stamps a "CREATOR: gd-jpeg ..." COM comment into every JPEG it writes -- a
		// real toolchain fingerprint -- so a "bare" GD JPEG is not metadata-free, and the
		// scrub correctly removes it under Description.
		$jpeg = JPEGImage::fromString($this->encoded('imagejpeg'));
		self::assertStringStartsWith('CREATOR:', (string) $jpeg->getComment());
		self::assertSame(1, $jpeg->clearPrivateData());
		self::assertNull($jpeg->getComment());
		self::assertSame(0, $jpeg->clearPrivateData(), 'and nothing is left');
	}
}
