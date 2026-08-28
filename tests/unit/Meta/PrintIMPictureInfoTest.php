<?php

use Belisoful\Image\Meta\EXIF;
use Belisoful\Image\Meta\PictureInfo;
use Belisoful\Image\Meta\PrintIM;
use Belisoful\Image\JPEGImage;

class PrintIMPictureInfoTest extends PHPUnit\Framework\TestCase
{
	public function testPimRoundTripBothOrders()
	{
		foreach ([true, false] as $bigEndian) {
			$pim = new PrintIM();
			$pim->setVersion('0300');
			$pim->setEntry(0x0001, 0x00160016);
			$pim->setEntry(0x0100, "\x01\x02\x03\x04");
			// A fresh instance defaults big-endian; parse with the matching order.
			$bytes = $pim->toBinary();
			self::assertStringStartsWith(PrintIM::Signature, $bytes);

			$reparsed = PrintIM::parse($bytes, true);
			self::assertNotFalse($reparsed);
			self::assertSame('0300', $reparsed->getVersion());
			self::assertCount(2, $reparsed->getEntries());
			self::assertSame(0x00160016, $reparsed->getEntryValue(0x0001));
			self::assertSame("\x01\x02\x03\x04", $reparsed->getEntry(0x0100));
			self::assertNull($reparsed->getEntry(0x9999));
		}
	}

	public function testPimPanasonicExtraNul()
	{
		$good = new PrintIM();
		$good->setEntry(0x0001, 42);
		$bytes = $good->toBinary();
		// Inject the Panasonic quirk: an extra NUL after the version terminator.
		$quirked = substr($bytes, 0, 13) . "\0" . substr($bytes, 13);
		$reparsed = PrintIM::parse($quirked, true);
		self::assertNotFalse($reparsed);
		self::assertSame(42, $reparsed->getEntryValue(0x0001));
	}

	public function testPimRejectsOther()
	{
		self::assertFalse(PrintIM::parse('not a pim block'));
		self::assertFalse(PrintIM::isPIM('PrintIN'));
	}

	public function testPimBlockEndingAtTheVersion()
	{
		// A block that stops right after the version terminator has no room for an
		// entry count at either candidate position: it parses to a version and nothing.
		$pim = PrintIM::parse(PrintIM::Signature . "0300\0");
		self::assertNotFalse($pim);
		self::assertSame('0300', $pim->getVersion());
		self::assertSame([], $pim->getEntries());
		self::assertNull($pim->getEntry(0x0001));
		self::assertNull($pim->getEntryValue(0x0001));
	}

	public function testPimSetEntryReplacesInPlace()
	{
		$pim = new PrintIM();
		$pim->setEntry(0x0001, 0x00010001);
		$pim->setEntry(0x0002, "\x0A\x0B\x0C\x0D");
		$pim->setEntry(0x0001, 0x00160016);   // replaces, does not append

		self::assertCount(2, $pim->getEntries());
		self::assertSame(0x00160016, $pim->getEntryValue(0x0001));
		self::assertSame(0x0001, $pim->getEntries()[0]['tag']);   // and keeps its place

		$reparsed = PrintIM::parse($pim->toBinary());
		self::assertCount(2, $reparsed->getEntries());
		self::assertSame(0x00160016, $reparsed->getEntryValue(0x0001));
		self::assertSame("\x0A\x0B\x0C\x0D", $reparsed->getEntry(0x0002));
	}

	public function testLittleEndianBlockWithTrailingPadding()
	{
		// A little-endian PrintIM block padded past its last entry: no count position
		// makes the entries fill the block exactly, so the parser falls through to the
		// second pass that accepts a count whose entries merely fit.
		$entry = "\x01\x00" . "\x16\x00\x16\x00";                       // tag 0x0001, four data bytes
		$data = PrintIM::Signature . "0250\0" . "\x01\x00" . $entry . "\xAA\xBB\xCC";

		$pim = PrintIM::parse($data, false);
		self::assertNotFalse($pim);
		self::assertSame('0250', $pim->getVersion());
		self::assertCount(1, $pim->getEntries());
		self::assertSame(0x0001, $pim->getEntries()[0]['tag']);
		// Read little-endian: the same four bytes a big-endian reader calls 0x16001600.
		self::assertSame(0x00160016, $pim->getEntryValue(0x0001));

		// Editing keeps the parsed byte order: an integer packs little-endian too.
		$pim->setEntry(0x0002, 0x01020304);
		self::assertSame("\x04\x03\x02\x01", $pim->getEntry(0x0002));
		self::assertSame(0x01020304, $pim->getEntryValue(0x0002));

		// And so does composing: the entry count and tag numbers pack little-endian.
		$bytes = $pim->toBinary();
		self::assertSame("\x02\x00", substr($bytes, 13, 2));
		self::assertSame("\x02\x00", substr($bytes, 21, 2));
		$reparsed = PrintIM::parse($bytes, false);
		self::assertSame([0x0001, 0x0002], array_column($reparsed->getEntries(), 'tag'));
		self::assertSame(0x00160016, $reparsed->getEntryValue(0x0001));
		self::assertSame(0x01020304, $reparsed->getEntryValue(0x0002));
	}

	public function testPictureInfoVendors()
	{
		$olympus = PictureInfo::parse("OLYMPUS OPTICAL CO.,LTD.\r\n[Camera Info]\r\nType=SX151\r\nSerial=1234\r\n[end]trailing");
		self::assertNotFalse($olympus);
		self::assertSame('OLYMPUS OPTICAL CO.,LTD.', $olympus->getHeader());
		self::assertStringEndsWith('[end]', $olympus->getText());
		self::assertSame(['Type' => 'SX151', 'Serial' => '1234'], $olympus->getFields());

		$epson = PictureInfo::parse("SEIKO EPSON CORP.  \x00F=2.8\r\nT=1/60\r\n");
		self::assertSame(['F' => '2.8', 'T' => '1/60'], $epson->getFields());

		$typed = PictureInfo::parse("Type=E200\r\nVer=1.0\r\n");
		self::assertSame('', $typed->getHeader());
		self::assertSame(['Type' => 'E200', 'Ver' => '1.0'], $typed->getFields());

		$hp = PictureInfo::parse("\x05\x00\x00\x00Res=Fine\r\n");
		self::assertSame("\x05\x00\x00\x00", $hp->getHeader());
		self::assertSame(['Res' => 'Fine'], $hp->getFields());

		self::assertFalse(PictureInfo::parse('random APP12 content'));
	}

	public function testJpegPictureInfoRoundTrip()
	{
		$im = imagecreatetruecolor(8, 8);
		ob_start();
		imagejpeg($im);
		imagedestroy($im);
		$jpeg = JPEGImage::fromString(ob_get_clean());

		$info = new PictureInfo();
		$info->setHeader('[picture info]');
		$info->setText("\r\nResolution=1024x768\r\n[end]");
		$jpeg->setPictureInfo($info);

		$reparsed = JPEGImage::fromString($jpeg->toBinary());
		self::assertNotNull($reparsed->getPictureInfo());
		self::assertSame(['Resolution' => '1024x768'], $reparsed->getPictureInfo()->getFields());

		$reparsed->setPictureInfo(null);
		self::assertNull(JPEGImage::fromString($reparsed->toBinary())->getPictureInfo());
	}

	public function testExifPimBridge()
	{
		$pim = new PrintIM();
		$pim->setEntry(0x0010, 5);
		$exif = new EXIF();
		$exif->setPIM($pim);

		$reparsed = EXIF::fromSegment($exif->toBinary());
		self::assertNotNull($reparsed->getPIM());
		self::assertSame(5, $reparsed->getPIM()->getEntryValue(0x0010));
		self::assertSame('0300', $reparsed->getPIM()->getVersion());

		$reparsed->setPIM(null);
		self::assertNull(EXIF::fromSegment($reparsed->toBinary())->getPIM());
	}
}
