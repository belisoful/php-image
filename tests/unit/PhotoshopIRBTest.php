<?php

use Belisoful\Image\Meta\IPTC;
use Belisoful\Image\Meta\IPTCTags;
use Belisoful\Image\JPEGImage;
use Belisoful\Image\PhotoshopIRB;
use Belisoful\Image\PhotoshopResource;
use Belisoful\Image\PhotoshopResourceNames;

class PhotoshopIRBTest extends PHPUnit\Framework\TestCase
{
	private function jpegBytes(int $w = 24, int $h = 16): string
	{
		$im = imagecreatetruecolor($w, $h);
		imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, 200, 60, 20));
		ob_start();
		imagejpeg($im, null, 80);
		imagedestroy($im);
		return ob_get_clean();
	}

	public function testResourceRoundTrip()
	{
		$irb = new PhotoshopIRB();
		$irb->setResource(new PhotoshopResource(PhotoshopResource::Url, 'https://example.org'));
		$irb->setResource(new PhotoshopResource(PhotoshopResource::CopyrightFlag, "\x01"));
		$irb->setResource(new PhotoshopResource(PhotoshopResource::GlobalAngle, pack('N', 30)));
		$irb->setResource(new PhotoshopResource(0x0999, 'odd', 'nm'));   // odd-length data + named

		$reparsed = PhotoshopIRB::parse($irb->toBinary());
		self::assertNotFalse($reparsed);
		self::assertCount(4, $reparsed);
		self::assertSame('https://example.org', $reparsed->getResource(PhotoshopResource::Url)->decodeText());
		self::assertTrue($reparsed->getResource(PhotoshopResource::CopyrightFlag)->decodeBoolean());
		self::assertSame(30, $reparsed->getResource(PhotoshopResource::GlobalAngle)->decodeInteger());
		self::assertSame('odd', $reparsed->getResource(0x0999)->getData());
		self::assertSame('nm', $reparsed->getResource(0x0999)->getName());
	}

	public function testIptcBridge()
	{
		$iptc = new IPTC();
		$iptc[IPTCTags::ObjectName] = 'IRB Title';
		$irb = new PhotoshopIRB();
		$irb->setIPTC($iptc);

		$reparsed = PhotoshopIRB::parse($irb->toBinary());
		self::assertSame('IRB Title', $reparsed->getIPTC()[IPTCTags::ObjectName]);

		$reparsed->setIPTC(null);
		self::assertNull($reparsed->getIPTC());
		self::assertFalse($reparsed->getResource(PhotoshopResource::IptcNaa) !== null);
	}

	public function testGetIptcAnswersNullForAnUnparsableResource()
	{
		$irb = new PhotoshopIRB();
		$irb->setResource(new PhotoshopResource(PhotoshopResource::IptcNaa, 'not an IIM dataset'));

		// The resource is there but its bytes are not IPTC, so nothing is returned ...
		self::assertNull($irb->getIPTC());
		// ... and the resource itself is left exactly as it was found.
		self::assertSame('not an IIM dataset', $irb->getResource(PhotoshopResource::IptcNaa)->getData());
		self::assertCount(1, $irb);
	}

	public function testDecoders()
	{
		$resolution = new PhotoshopResource(
			PhotoshopResource::ResolutionInfo,
			pack('N', 72 * 0x10000) . pack('n', 1) . pack('n', 2) . pack('N', 144 * 0x10000) . pack('n', 1) . pack('n', 1),
		);
		$decoded = $resolution->decodeResolutionInfo();
		self::assertEqualsWithDelta(72.0, $decoded['hRes'], 1e-9);
		self::assertEqualsWithDelta(144.0, $decoded['vRes'], 1e-9);

		$quality = new PhotoshopResource(PhotoshopResource::JpegQuality, pack('n', 8) . pack('n', 0x0101) . pack('n', 3));
		$decoded = $quality->decodeJpegQuality();
		self::assertSame(12, $decoded['quality']);
		self::assertSame('Progressive', $decoded['format']);
		self::assertSame(5, $decoded['progressiveScans']);

		$halftone = new PhotoshopResource(
			PhotoshopResource::GrayscaleHalftone,
			pack('N', 53 * 0x10000) . pack('n', 1) . pack('N', 45 * 0x10000) . pack('n', 1) . pack('n', 1) . str_repeat("\0", 4),
		);
		$channels = $halftone->decodeHalftone();
		self::assertEqualsWithDelta(53.0, $channels[0]['frequency'], 1e-9);
		self::assertEqualsWithDelta(45.0, $channels[0]['angle'], 1e-9);
		self::assertSame('Ellipse', $channels[0]['shape']);

		$curvePoints = [0, 0xFFFF, 100, 0xFFFF, 0xFFFF, 500, 0xFFFF, 0xFFFF, 0xFFFF, 900, 0xFFFF, 0xFFFF, 1000];
		$transfer = new PhotoshopResource(
			PhotoshopResource::GrayscaleTransferFunction,
			pack('n13', ...$curvePoints) . pack('n', 1),
		);
		$channels = $transfer->decodeTransferFunction();
		self::assertSame(0.0, $channels[0]['curve'][0]);
		self::assertSame(-1, $channels[0]['curve'][1]);
		self::assertSame(100.0, $channels[0]['curve'][12]);
		self::assertTrue($channels[0]['override']);

		$thumbJpeg = $this->jpegBytes(8, 6);
		$thumb = new PhotoshopResource(
			PhotoshopResource::Thumbnail5,
			pack('N', 1) . pack('N', 8) . pack('N', 6) . pack('N', 24) . pack('N', 144) . pack('N', strlen($thumbJpeg)) . pack('n', 24) . pack('n', 1) . $thumbJpeg,
		);
		$decoded = $thumb->decodeThumbnail();
		self::assertSame(8, $decoded['width']);
		self::assertSame($thumbJpeg, $decoded['jpeg']);

		$names = new PhotoshopResource(PhotoshopResource::IptcNaa);
		self::assertSame('IPTC-NAA record', $names->getResourceName());
		self::assertSame('Path Information', PhotoshopResourceNames::nameOf(0x0800));
	}

	public function testChunkingSplitsLargeBlocks()
	{
		$irb = new PhotoshopIRB();
		$irb->setResource(new PhotoshopResource(0x0999, str_repeat('A', 70000)));
		$segments = $irb->toSegments();
		self::assertGreaterThan(2, count($segments));
		foreach ($segments as $segment) {
			self::assertStringStartsWith(PhotoshopIRB::Signature, $segment);
			self::assertLessThanOrEqual(PhotoshopIRB::ChunkSize + strlen(PhotoshopIRB::Signature), strlen($segment));
		}
		$reparsed = PhotoshopIRB::parse(implode('', $segments));
		self::assertSame(str_repeat('A', 70000), $reparsed->getResource(0x0999)->getData());
	}

	public function testJpegIntegrationWithIrbAndIptcSync()
	{
		$jpeg = JPEGImage::fromString($this->jpegBytes());

		$iptc = new IPTC();
		$iptc[IPTCTags::ObjectName] = 'Original';
		$irb = new PhotoshopIRB();
		$irb->setIPTC($iptc);
		$irb->setResource(new PhotoshopResource(PhotoshopResource::Url, 'https://prado.example'));
		$jpeg->setPhotoshopIRB($irb);
		$jpeg->setIPTC($iptc);

		$reparsed = JPEGImage::fromString($jpeg->toBinary());
		self::assertNotNull($reparsed->getPhotoshopIRB());
		self::assertSame('https://prado.example', $reparsed->getPhotoshopIRB()->getResource(PhotoshopResource::Url)->decodeText());
		self::assertSame('Original', $reparsed->getIPTC()[IPTCTags::ObjectName]);

		// Editing the live IPTC syncs into the IRB on the next compose.
		$reparsed->getIPTC()[IPTCTags::ObjectName] = 'Edited';
		$again = JPEGImage::fromString($reparsed->toBinary());
		self::assertSame('Edited', $again->getIPTC()[IPTCTags::ObjectName]);
		self::assertSame('Edited', $again->getPhotoshopIRB()->getIPTC()[IPTCTags::ObjectName]);

		// A large IRB round-trips across multiple APP13 segments.
		$again->getPhotoshopIRB()->setResource(new PhotoshopResource(0x0998, str_repeat('B', 66000)));
		$multi = JPEGImage::fromString($again->toBinary());
		self::assertSame(str_repeat('B', 66000), $multi->getPhotoshopIRB()->getResource(0x0998)->getData());
	}

	public function testParseStopsAtATruncatedResourceHeader()
	{
		$irb = new PhotoshopIRB();
		$irb->setResource(new PhotoshopResource(PhotoshopResource::Url, 'https://example.org'));
		// A trailing 8BIM header that ends before its four size bytes is dropped.
		$stub = PhotoshopIRB::ResourceSignature . pack('n', 0x0999) . "\x00\x00" . "\x00\x00";
		self::assertSame(10, strlen($stub));

		$reparsed = PhotoshopIRB::parse($irb->toBinary() . $stub);
		self::assertCount(1, $reparsed);
		self::assertSame('https://example.org', $reparsed->getResource(PhotoshopResource::Url)->decodeText());
		self::assertNull($reparsed->getResource(0x0999));

		// A block that is nothing but the truncated header holds no resource at all.
		self::assertFalse(PhotoshopIRB::parse($stub));
	}

	public function testEmptyIrbHasNoSegments()
	{
		$irb = new PhotoshopIRB();
		self::assertSame('', $irb->toBinary());
		self::assertSame([], $irb->toSegments());

		$irb->setResource(new PhotoshopResource(PhotoshopResource::Url, 'https://example.org'));
		self::assertCount(1, $irb->toSegments());
	}

	public function testExifIrbBridge()
	{
		$irb = new PhotoshopIRB();
		$irb->setResource(new PhotoshopResource(PhotoshopResource::Url, 'https://embedded.example'));

		$exif = new Belisoful\Image\Meta\EXIF();
		$exif->setIRB($irb);
		$reparsed = Belisoful\Image\Meta\EXIF::fromSegment($exif->toBinary());
		self::assertSame('https://embedded.example', $reparsed->getIRB()->getResource(PhotoshopResource::Url)->decodeText());
	}
}
