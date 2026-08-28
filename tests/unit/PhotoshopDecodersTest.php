<?php

use Belisoful\Image\Photoshop8BIM;
use Belisoful\Image\PhotoshopIRB;
use Belisoful\Image\PhotoshopResource;
use Belisoful\Image\PhotoshopResourceNames;

class PhotoshopDecodersTest extends PHPUnit\Framework\TestCase
{
	private function psUnicode(string $utf8): string
	{
		$utf16 = mb_convert_encoding($utf8, 'UTF-16BE', 'UTF-8');
		return pack('N', intdiv(strlen($utf16), 2)) . $utf16;
	}

	public function testGridGuidesDecoder()
	{
		$data = pack('N', 1) . pack('N', 576) . pack('N', 576) . pack('N', 2)
			. pack('N', 320) . "\x00"    // vertical guide at 10px (32nds)
			. pack('N', 640) . "\x01";   // horizontal guide at 20px
		$resource = new PhotoshopResource(PhotoshopResource::GridAndGuides, $data);
		$decoded = $resource->decodeGridGuides();
		self::assertSame(1, $decoded['version']);
		self::assertSame(576, $decoded['gridHorizontal']);
		self::assertCount(2, $decoded['guides']);
		self::assertSame(10.0, $decoded['guides'][0]['location']);
		self::assertSame('vertical', $decoded['guides'][0]['direction']);
		self::assertSame('horizontal', $decoded['guides'][1]['direction']);

		self::assertNull((new PhotoshopResource(PhotoshopResource::GridAndGuides, 'short'))->decodeGridGuides());
	}

	public function testVersionInfoDecoder()
	{
		$data = pack('N', 1) . "\x01" . $this->psUnicode('Adobe Photoshop') . $this->psUnicode('Reader ✓') . pack('N', 3);
		$resource = new PhotoshopResource(PhotoshopResource::VersionInfo, $data);
		$decoded = $resource->decodeVersionInfo();
		self::assertSame(1, $decoded['version']);
		self::assertTrue($decoded['hasRealMergedData']);
		self::assertSame('Adobe Photoshop', $decoded['writer']);
		self::assertSame('Reader ✓', $decoded['reader']);
		self::assertSame(3, $decoded['fileVersion']);

		self::assertNull((new PhotoshopResource(PhotoshopResource::VersionInfo, "\x00"))->decodeVersionInfo());
	}

	public function testIrbThumbnailAndICCAccessors()
	{
		$jpeg = "\xFF\xD8FAKETHUMB\xFF\xD9";
		$thumbData = pack('N', 1) . pack('N', 16) . pack('N', 12) . pack('N', 48) . pack('N', 576)
			. pack('N', strlen($jpeg)) . pack('n', 24) . pack('n', 1) . $jpeg;
		$irb = new PhotoshopIRB();
		$irb->setResource(new PhotoshopResource(PhotoshopResource::Thumbnail5, $thumbData));
		$irb->setResource(new PhotoshopResource(PhotoshopResource::ICCProfile, 'ICCBYTES'));

		self::assertSame($jpeg, $irb->getThumbnail());
		self::assertSame('ICCBYTES', $irb->getICCProfile());

		// The Photoshop 4.0 form is the fallback.
		$irb4 = new PhotoshopIRB();
		$irb4->setResource(new PhotoshopResource(PhotoshopResource::Thumbnail4, $thumbData));
		self::assertSame($jpeg, $irb4->getThumbnail());

		self::assertNull((new PhotoshopIRB())->getThumbnail());
		self::assertNull((new PhotoshopIRB())->getICCProfile());
	}

	public function testIrbCollectionAndNames()
	{
		$irb = new PhotoshopIRB();
		$irb->setResource(new PhotoshopResource(PhotoshopResource::Url, 'https://a.example'));
		$irb->setResource(new PhotoshopResource(0x07D5, 'pathdata', 'clip'));

		self::assertTrue(PhotoshopIRB::isIRB(PhotoshopIRB::Signature . '8BIMxxxx'));
		self::assertTrue(PhotoshopIRB::isIRB('8BIMxxxx'));
		self::assertFalse(PhotoshopIRB::isIRB('not photoshop'));

		self::assertCount(2, $irb->getResources());
		$seen = [];
		foreach ($irb as $resource) {
			$seen[] = $resource->getId();
		}
		self::assertSame([PhotoshopResource::Url, 0x07D5], $seen);

		$path = $irb->getResource(0x07D5);
		self::assertSame('Path Information', $path->getResourceName());
		self::assertNotNull($path->getDescription());
		self::assertSame('Saved working path information', PhotoshopResourceNames::describe(0x0800));
		self::assertNotNull(PhotoshopResourceNames::describe(PhotoshopResource::IptcNaa));
		self::assertNull(PhotoshopResourceNames::nameOf(0xEEEE));

		$path->setName('renamed');
		$path->setData('newdata');
		self::assertSame('renamed', $path->getName());
		self::assertSame('newdata', $path->getData());

		self::assertTrue($irb->removeResource(0x07D5));
		self::assertFalse($irb->removeResource(0x07D5));
		self::assertCount(1, $irb);
	}

	public function testResolutionAndJpegQualityDecoders()
	{
		$resolution = new PhotoshopResource(
			PhotoshopResource::ResolutionInfo,
			pack('N', 72 * 0x10000) . pack('n', 1) . pack('n', 2) . pack('N', 300 * 0x10000) . pack('n', 1) . pack('n', 3),
		);
		$decoded = $resolution->decodeResolutionInfo();
		self::assertEqualsWithDelta(72.0, $decoded['hRes'], 1e-9);
		self::assertEqualsWithDelta(300.0, $decoded['vRes'], 1e-9);
		self::assertSame(2, $decoded['widthUnit']);
		self::assertSame(3, $decoded['heightUnit']);

		$quality = fn (int $scale, int $format) => (new PhotoshopResource(
			PhotoshopResource::JpegQuality,
			pack('n', $scale) . pack('n', $format) . pack('n', 1),
		))->decodeJpegQuality();

		// The stored scale is a signed offset: 0xFFFD..0x0008 maps to quality 1..12.
		self::assertSame(12, $quality(0x0008, 0x0000)['quality']);
		self::assertSame(1, $quality(0xFFFD, 0x0000)['quality']);
		self::assertSame(3, $quality(0x0008, 0x0000)['progressiveScans']);
		self::assertSame('Standard', $quality(0x0008, 0x0000)['format']);
		self::assertSame('Optimised', $quality(0x0008, 0x0001)['format']);
		self::assertSame('Unknown (0x0202)', $quality(0x0008, 0x0202)['format']);

		// Payloads shorter than the fixed field set decode to nothing.
		self::assertNull((new PhotoshopResource(PhotoshopResource::ResolutionInfo, str_repeat("\x00", 15)))->decodeResolutionInfo());
		self::assertNull((new PhotoshopResource(PhotoshopResource::JpegQuality, "\x00\x08\x00"))->decodeJpegQuality());
		self::assertNull((new PhotoshopResource(PhotoshopResource::Thumbnail5, str_repeat("\x00", 27)))->decodeThumbnail());
	}

	public function testVersionInfoWithATruncatedUnicodeString()
	{
		// The writer string is complete; the reader string's length field runs past the end.
		$data = pack('N', 1) . "\x01" . $this->psUnicode('Adobe Photoshop');
		$decoded = (new PhotoshopResource(PhotoshopResource::VersionInfo, $data))->decodeVersionInfo();
		self::assertSame('Adobe Photoshop', $decoded['writer']);
		self::assertSame('', $decoded['reader']);
		self::assertNull($decoded['fileVersion']);
	}

	public function testBooleanAndIntegerDecodersOnUndersizedPayloads()
	{
		// An empty payload has no flag byte to read, and fewer than four bytes is not an
		// integer: both answer null rather than a made-up value.
		self::assertNull((new PhotoshopResource(PhotoshopResource::CopyrightFlag))->decodeBoolean());
		self::assertNull((new PhotoshopResource(PhotoshopResource::GlobalAngle, "\x00\x00\x1E"))->decodeInteger());

		self::assertFalse((new PhotoshopResource(PhotoshopResource::CopyrightFlag, "\x00"))->decodeBoolean());
		self::assertTrue((new PhotoshopResource(PhotoshopResource::CopyrightFlag, "\x01"))->decodeBoolean());
		self::assertSame(30, (new PhotoshopResource(PhotoshopResource::GlobalAngle, pack('N', 30)))->decodeInteger());
	}

	public function testVersionInfoWithAUnicodeStringCutMidCodeUnit()
	{
		// The writer string declares three UTF-16 code units but only three bytes follow,
		// so the decode fails and the string reads as empty instead of as mojibake.
		$data = pack('N', 1) . "\x01" . pack('N', 3) . "\x00A\x00";
		$decoded = (new PhotoshopResource(PhotoshopResource::VersionInfo, $data))->decodeVersionInfo();

		self::assertSame(1, $decoded['version']);
		self::assertTrue($decoded['hasRealMergedData']);
		self::assertSame('', $decoded['writer']);
		self::assertSame('', $decoded['reader']);
		self::assertNull($decoded['fileVersion']);
	}

	public function testLegacy8BimHelpers()
	{
		$iptcPayload = "\x1C\x02\x05\x00\x04Test";
		$wrapped = Photoshop8BIM::iptcEncode($iptcPayload);
		self::assertTrue(Photoshop8BIM::isPhotoshop($wrapped));

		// String decode narrows to the payload.
		$copy = $wrapped;
		$length = Photoshop8BIM::iptcDecode($copy);
		self::assertSame(strlen($iptcPayload), $length);
		self::assertSame($iptcPayload, substr($copy, 0, $length));

		// Stream decode positions at the payload.
		$stream = fopen('php://memory', 'w+b');
		fwrite($stream, $wrapped);
		rewind($stream);
		$length = Photoshop8BIM::iptcDecode($stream);
		self::assertSame(strlen($iptcPayload), $length);
		self::assertSame($iptcPayload, fread($stream, $length));
		fclose($stream);

		// Non-Photoshop data answers null; a bad type throws.
		$other = 'plain data';
		self::assertNull(Photoshop8BIM::iptcDecode($other));
		self::expectException(\InvalidArgumentException::class);
		$bad = 42;
		Photoshop8BIM::iptcDecode($bad);
	}
}
