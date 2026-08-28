<?php

use Belisoful\Image\ImageGraphicsLibraryInterface;
use Belisoful\Image\Meta\IPTC;
use Belisoful\Image\Meta\IPTCTags;
use Belisoful\Image\ImageGraphics;
use Belisoful\Image\ImageGraphicsMode;

class IPTCTest extends PHPUnit\Framework\TestCase
{
	public $obj;

	protected function setUp(): void
	{
		$this->obj = new IPTC();
	}

	public function testCollectionSurface()
	{
		// IPTC is its own collection: ArrayAccess, IteratorAggregate, and Countable.
		$iptc = new IPTC('');
		self::assertCount(0, $iptc);
		self::assertSame(0, $iptc->getCount());

		$iptc[IPTCTags::ObjectName] = 'Name';
		$iptc[IPTCTags::City] = 'London';
		self::assertCount(2, $iptc);
		self::assertSame(2, $iptc->getCount());
		self::assertTrue(isset($iptc[IPTCTags::City]));
		self::assertSame('London', $iptc[IPTCTags::City]);
		self::assertSame('London', $iptc->itemAt(IPTCTags::City));

		// Iteration is ordered by tag id.
		self::assertSame(['2#005', '2#090'], array_keys(iterator_to_array($iptc)));
		self::assertSame(['2#005', '2#090'], array_keys($iptc->toArray()));

		unset($iptc[IPTCTags::City]);
		self::assertFalse(isset($iptc[IPTCTags::City]));
		self::assertNull($iptc[IPTCTags::City]);

		$iptc->clear();
		self::assertCount(0, $iptc);
	}

	public function testMergeWith()
	{
		$iptc = new IPTC('');
		$iptc[IPTCTags::ObjectName] = 'Original';
		$iptc->mergeWith([IPTCTags::ObjectName => 'Replaced', IPTCTags::City => 'Paris']);
		self::assertSame('Replaced', $iptc[IPTCTags::ObjectName], 'A repeated key is overwritten.');
		self::assertSame('Paris', $iptc[IPTCTags::City], 'A new key is added.');

		// A Traversable merges the same way.
		$iptc->mergeWith(new ArrayIterator([IPTCTags::ProvinceState => 'IDF']));
		self::assertSame('IDF', $iptc[IPTCTags::ProvinceState]);
	}

	public function testGetKeysListsThePresentDatasets()
	{
		$iptc = new IPTC('');
		self::assertSame([], $iptc->getKeys());
		$iptc[IPTCTags::ObjectName] = 'Name';
		$iptc[IPTCTags::City] = 'London';
		self::assertSame(['2#005', '2#090'], $iptc->getKeys());
	}

	public function testCopyFromReplacesEverything()
	{
		$iptc = new IPTC('');
		$iptc[IPTCTags::ObjectName] = 'Original';
		$iptc[IPTCTags::City] = 'London';

		$iptc->copyFrom([IPTCTags::ProvinceState => 'IDF']);
		self::assertSame(['2#095'], $iptc->getKeys(), 'the previous datasets are cleared');
		self::assertSame('IDF', $iptc[IPTCTags::ProvinceState]);

		// A Traversable copies the same way, and an empty source just clears.
		$iptc->copyFrom(new ArrayIterator([IPTCTags::City => 'Paris']));
		self::assertSame(['2#090'], $iptc->getKeys());
	}

	public function testCopyFromRejectsANonIterableAndKeepsTheExistingDatasets()
	{
		$iptc = new IPTC('');
		$iptc[IPTCTags::ObjectName] = 'Kept';
		try {
			$iptc->copyFrom('not iterable');
			self::fail('a non-iterable must be refused');
		} catch (\InvalidArgumentException $e) {
			self::assertSame('Kept', $iptc[IPTCTags::ObjectName], 'a rejected copy changes nothing');
		}
	}

	public function testMergeWithRejectsANonIterable()
	{
		$iptc = new IPTC('');
		self::expectException(\InvalidArgumentException::class);
		$iptc->mergeWith('not iterable');
	}

	public function testIPTCDate()
	{
		self::assertEquals("20000101", IPTC::formatIPTCDate(946684800));
		self::assertEquals(date('Ymd'), IPTC::formatIPTCDate());
	}

	public function testIPTCTime()
	{
		self::assertEquals("000000+0000", IPTC::formatIPTCTime(946684800));
		self::assertEquals(date('HisO'), IPTC::formatIPTCTime());
	}

	public function testFormatTag()
	{
		self::assertEquals("1#090", IPTC::formatTag(1, 90));
		self::assertEquals("1#090", IPTC::formatTag('1', '90'));
	}

	/**
	 * Fills an IPTC with datasets from every record the class models, the way a
	 * photo editor writes them.  The original framework test read these from a
	 * tdot.jpg fixture that was never committed; the block is built in memory here
	 * so the parse path is exercised without a binary fixture.
	 */
	private function sampleIPTC(): IPTC
	{
		$iptc = new IPTC();
		$iptc[IPTCTags::FileFormat] = 11;
		$iptc[IPTCTags::DateSent] = '20230105';
		$iptc[IPTCTags::TimeSent] = '021159-0800';

		$iptc[IPTCTags::ObjectName] = 'TDot Sample';
		$iptc[IPTCTags::EditStatus] = 'Final Sample';
		$iptc[IPTCTags::Urgency] = '3';
		$iptc[IPTCTags::Keywords] = ['green, sample, Prado'];
		$iptc[IPTCTags::ReleaseDate] = '20230201';
		$iptc[IPTCTags::ReleaseTime] = '021159-0800';
		$iptc[IPTCTags::ReferenceNumber] = ['00000422'];
		$iptc[IPTCTags::OriginatingProgram] = 'Prado::TDot';
		$iptc[IPTCTags::ByLine] = ['Brad: Anderson'];
		$iptc[IPTCTags::ByLineTitle] = ['P-355335'];
		$iptc[IPTCTags::City] = 'Los Angeles';
		$iptc[IPTCTags::ProvinceState] = 'California';
		$iptc[IPTCTags::CountryPrimaryLocationCode] = 'WGW';
		$iptc[IPTCTags::CountryPrimaryLocationName] = 'World';
		$iptc[IPTCTags::CopyrightNotice] = ' Prado License BSD 3 Paragraph';
		$iptc[IPTCTags::Contact] = ['belisoful@icloud.com'];
		$iptc[IPTCTags::CaptionAbstract] = 'This is a sample of the TDot.';
		$iptc[IPTCTags::MasterDocumentID] = '4.2.2';

		$iptc[IPTCTags::IPTCBitsPerSample] = 8;
		$iptc[IPTCTags::ScanningDirection] = 0;
		$iptc[IPTCTags::IPTCImageRotation] = 0;
		$iptc[IPTCTags::BitsPerComponent] = 8;
		return $iptc;
	}

	/**
	 * Asserts a parsed block round-tripped every dataset of {@see sampleIPTC()}.
	 * @param IPTC $data
	 */
	private function assertSampleIPTC(IPTC $data): void
	{
		self::assertEquals(4, $data[IPTCTags::EnvelopeRecordVersion]);
		self::assertEquals(11, $data[IPTCTags::FileFormat]);
		self::assertEquals('20230105', $data[IPTCTags::DateSent]);
		self::assertEquals('021159-0800', $data[IPTCTags::TimeSent]);

		self::assertEquals(4, $data[IPTCTags::ApplicationRecordVersion]);
		self::assertEquals('TDot Sample', $data[IPTCTags::ObjectName]);

		self::assertEquals('Final Sample', $data[IPTCTags::EditStatus]);
		self::assertEquals('3', $data[IPTCTags::Urgency]);
		self::assertEquals(['green, sample, Prado'], $data[IPTCTags::Keywords]);
		self::assertEquals('20230201', $data[IPTCTags::ReleaseDate]);
		self::assertEquals('021159-0800', $data[IPTCTags::ReleaseTime]);
		self::assertEquals(['00000422'], $data[IPTCTags::ReferenceNumber]);
		self::assertEquals('Prado::TDot', $data[IPTCTags::OriginatingProgram]);
		self::assertEquals(['Brad: Anderson'], $data[IPTCTags::ByLine]);
		self::assertEquals(['P-355335'], $data[IPTCTags::ByLineTitle]);
		self::assertEquals('Los Angeles', $data[IPTCTags::City]);
		self::assertEquals('California', $data[IPTCTags::ProvinceState]);
		self::assertEquals('WGW', $data[IPTCTags::CountryPrimaryLocationCode]);
		self::assertEquals('World', $data[IPTCTags::CountryPrimaryLocationName]);
		self::assertEquals(' Prado License BSD 3 Paragraph', $data[IPTCTags::CopyrightNotice]);
		self::assertEquals(['belisoful@icloud.com'], $data[IPTCTags::Contact]);
		self::assertEquals('This is a sample of the TDot.', $data[IPTCTags::CaptionAbstract]);
		self::assertEquals('4.2.2', $data[IPTCTags::MasterDocumentID]);

		self::assertEquals(4, $data[IPTCTags::NewsPhotoVersion]);
		self::assertEquals(8, $data[IPTCTags::IPTCBitsPerSample]);
		self::assertEquals(0, $data[IPTCTags::ScanningDirection]);
		self::assertEquals(0, $data[IPTCTags::IPTCImageRotation]);
		self::assertEquals(8, $data[IPTCTags::BitsPerComponent]);
	}

	public function testIPTCparse()
	{
		$block = $this->sampleIPTC()->toBinary(true);
		// The Photoshop 8BIM wrapper is decoded before the datasets are read.
		self::assertSame("Photoshop 3.0\0", substr($block, 0, 14));

		$data = IPTC::iptcparse($block);
		self::assertInstanceof(IPTC::class, $data);
		$this->assertSampleIPTC($data);
		self::assertEquals(32, count($data));

		// An unwrapped IIM block parses the same way.
		$raw = $this->sampleIPTC()->toBinary(false);
		$data = IPTC::iptcparse($raw);
		self::assertInstanceof(IPTC::class, $data);
		$this->assertSampleIPTC($data);
	}

	public function testIPTCparseStream()
	{
		$block = $this->sampleIPTC()->toBinary(true);

		// A JPEG-shaped stream: SOI, a filler APP0, then the APP13 the parser wants.
		$jpeg = "\xFF\xD8" . "\xFF\xE0" . pack('n', 16) . str_repeat("\0", 14)
			. "\xFF\xED" . pack('n', strlen($block) + 2) . $block;
		$stream = fopen('php://memory', 'r+b');
		fwrite($stream, $jpeg);
		rewind($stream);

		$marker = fread($stream, 2);
		while (!feof($stream) && $marker !== "\xFF\xED") {
			$marker = $marker[1] . fgetc($stream);
		}
		self::assertEquals(22, ftell($stream));
		$length = unpack('n', fread($stream, 2))[1] - 2;
		self::assertEquals(strlen($block), $length);

		$data = IPTC::iptcparse([$stream, $length]);
		self::assertInstanceof(IPTC::class, $data);
		self::assertEquals(32, count($data));
		$this->assertSampleIPTC($data);
		fclose($stream);
	}

	public function testIPTCTagKeys()
	{
		$keys = IPTC::getIPTCTagKeys();
		self::assertEquals(123, count($keys));
		self::assertTrue(array_key_exists('enveloperecordversion', $keys));

		$keys = IPTC::getIPTCTagKeys(true);
		self::assertTrue(array_key_exists('enveloperecordversion', $keys));
		self::assertTrue(array_key_exists('copyright', $keys));

		$keys = IPTC::getIPTCTagKeys(false);
		self::assertTrue(array_key_exists('EnvelopeRecordVersion', $keys));
		self::assertTrue(array_key_exists('Copyright', $keys));

		$keys = IPTC::getIPTCTagKeys(true, false);
		self::assertTrue(array_key_exists('enveloperecordversion', $keys));
		self::assertFalse(array_key_exists('copyright', $keys));

		$keys = IPTC::getIPTCTagKeys(false, false);
		self::assertTrue(array_key_exists('EnvelopeRecordVersion', $keys));
		self::assertFalse(array_key_exists('Copyright', $keys));
	}

	public function testMapToIPTCTagId()
	{
		self::assertEquals('2#005', IPTC::mapToIPTCTagId('ObjectName'));
		self::assertEquals('2#005', IPTC::mapToIPTCTagId('objectname'));
		self::assertEquals('2#005', IPTC::mapToIPTCTagId('2#005'));
		self::assertEquals('2#005', IPTC::mapToIPTCTagId('2#5'));
		self::assertEquals('2#005', IPTC::mapToIPTCTagId(0x0205));
		self::assertEquals('2#005', IPTC::mapToIPTCTagId(2, 5));
		self::assertEquals('2#005', IPTC::mapToIPTCTagId('2', '5'));
		self::assertEquals('2#193', IPTC::mapToIPTCTagId('2#193'));
		self::assertEquals(null, IPTC::mapToIPTCTagId('11#3'));
		self::assertEquals(null, IPTC::mapToIPTCTagId('invalidTag'));
		self::assertEquals(null, IPTC::mapToIPTCTagId(0));
		self::assertEquals(null, IPTC::mapToIPTCTagId(null));
	}

	public function testMapToIPTCTagName()
	{
		self::assertEquals(null, IPTC::mapToIPTCTagName(''));
		self::assertEquals('ObjectName', IPTC::mapToIPTCTagName('ObjectName'));
		self::assertEquals('ObjectName', IPTC::mapToIPTCTagName('objectname'));
		self::assertEquals('ObjectName', IPTC::mapToIPTCTagName('2#005'));
		self::assertEquals('ObjectName', IPTC::mapToIPTCTagName('2#5'));
	}

	public function testComputeEnvelopeNumber()
	{
		$serviceId = 'PRADO4.2.3';
		$date = '20230518';
		$ref = 76564395;
		self::assertEquals($ref, IPTC::computeEnvelopeNumber($serviceId, $date));
		self::assertEquals($ref, IPTC::computeEnvelopeNumber($serviceId, $date));
		self::assertNotEquals($ref, IPTC::computeEnvelopeNumber($serviceId, '20230517'));
		self::assertNotEquals($ref, IPTC::computeEnvelopeNumber('PRADO4.2.4', $date));
	}

	public function testConstruct()
	{
		self::assertEquals(true, $this->obj->contains('1#090'));

		$this->obj = new IPTC('');
		self::assertEquals(false, $this->obj->contains('1#090'));
	}

	public function testRasterizedCaptionImage()
	{
		$refImage = imageCreate(460, 128);
		$black = imagecolorallocate($refImage, 0, 0, 0);
		$white = imagecolorallocate($refImage, 255, 255, 255);
		imagesetpixel($refImage, 0, 127, $white);
		imagesetpixel($refImage, 0, 126, $white);
		$this->obj->setRasterizedCaptionImage($refImage);
		self::assertEquals(str_pad("\x03", 7360, "\x00"), $this->obj[IPTCTags::RasterizedCaption]);

		imagecolorset($refImage, 0, 255, 255, 255);
		imagecolorset($refImage, 1, 0, 0, 0);

		$this->obj->setRasterizedCaptionImage($refImage);
		self::assertEquals(str_pad("\xFC", 7360, "\xFF"), $this->obj[IPTCTags::RasterizedCaption]);

		imagecolorset($refImage, 0, 0, 0, 0);
		imagecolorset($refImage, 1, 255, 255, 255);

		// Deterministic noise: a random pattern would make the encoded caption -- and the
		// codec branches it exercises -- differ from run to run.
		$noise = PseudoRandomBytes::bytes(128 * 460, 'iptc-caption');
		for ($y = 0; $y < 128; $y++) {
			for ($x = 0; $x < 460; $x++) {
				imagesetpixel($refImage, $x, $y, (ord($noise[$y * 460 + $x]) & 1) ? $white : $black);
			}
		}
		$this->obj->setRasterizedCaptionImage($refImage);

		$image = $this->obj->getRasterizedCaptionImage();
		self::assertInstanceOf(\GdImage::class, $image);
		for ($y = 0; $y < 128; $y++) {
			for ($x = 0; $x < 460; $x++) {
				$ref = imagecolorsforindex($refImage, imagecolorat($refImage, $x, $y));
				$expected = ($ref['red'] << 16) | ($ref['green'] << 8) | $ref['blue'];
				self::assertSame($expected, imagecolorat($image, $x, $y));
			}
		}
		imageDestroy($refImage);
		imageDestroy($image);
	}

	public function testValidate()
	{
		$this->obj[IPTCTags::ApplicationRecordVersion] = 4;
		$this->obj[IPTCTags::NewsPhotoVersion] = 4;
		self::assertEquals(3, count($this->obj));
		$this->obj->validate();
		self::assertEquals(7, count($this->obj));
		self::assertEquals(false, $this->obj->contains(IPTCTags::ApplicationRecordVersion));
		self::assertEquals(false, $this->obj->contains(IPTCTags::NewsPhotoVersion));

		self::assertEquals(4, $this->obj[IPTCTags::EnvelopeRecordVersion]);
		self::assertEquals(1, $this->obj[IPTCTags::FileFormat]);
		self::assertEquals(4, $this->obj[IPTCTags::FileVersion]);
		self::assertSame(IPTC::DefaultServiceIdentifier, $this->obj[IPTCTags::ServiceIdentifier]);
		self::assertEquals(date('Ymd'), $this->obj[IPTCTags::DateSent]);
		self::assertEquals(8, strlen($this->obj[IPTCTags::EnvelopeNumber]));


		$this->obj[IPTCTags::JobID] = 'Job ID';
		$this->obj[IPTCTags::IPTCPictureNumber] = "ABCDEF20230209YZ";

		$this->obj[IPTCTags::EnvelopeRecordVersion] = 5;
		$this->obj[IPTCTags::FileFormat] = 2;
		$this->obj[IPTCTags::FileVersion] = 6;
		$this->obj[IPTCTags::ServiceIdentifier] = '0123456789';
		$this->obj[IPTCTags::DateSent] = '19991231';
		$envelope = $this->obj[IPTCTags::EnvelopeNumber] = str_pad(crc32($this->obj[IPTCTags::ServiceIdentifier] . $this->obj[IPTCTags::DateSent]) % 100000000, 8, '0', STR_PAD_LEFT);
		$this->obj->validate();
		self::assertEquals(true, $this->obj->contains(IPTCTags::ApplicationRecordVersion));
		self::assertEquals(true, $this->obj->contains(IPTCTags::NewsPhotoVersion));

		self::assertEquals(4, $this->obj[IPTCTags::ApplicationRecordVersion]);
		self::assertEquals(4, $this->obj[IPTCTags::NewsPhotoVersion]);

		self::assertEquals(5, $this->obj[IPTCTags::EnvelopeRecordVersion]);
		self::assertEquals(2, $this->obj[IPTCTags::FileFormat]);
		self::assertEquals(6, $this->obj[IPTCTags::FileVersion]);
		self::assertEquals('0123456789', $this->obj[IPTCTags::ServiceIdentifier]);
		self::assertEquals('19991231', $this->obj[IPTCTags::DateSent]);
		self::assertEquals($envelope, $this->obj[IPTCTags::EnvelopeNumber]);

		unset($this->obj[IPTCTags::IPTCPictureNumber]);
		$this->obj->validate();
		self::assertEquals(true, $this->obj->contains(IPTCTags::ApplicationRecordVersion));
		self::assertEquals(false, $this->obj->contains(IPTCTags::NewsPhotoVersion));
	}

	public function testToBinary()
	{
		$envVer = "\x1c\x01\x00\x00\x02\x00\x04";
		$char = "\x1c\x01\x5A\x00\x03\x1B\x25\x47";
		$ff = "\x1c\x01\x14\x00\x02\x00\x01";
		$fv = "\x1c\x01\x16\x00\x02\x00\x04";
		$svid = "\x1c\x01\x1E" . pack('n', strlen(IPTC::DefaultServiceIdentifier)) . IPTC::DefaultServiceIdentifier;
		$date = "\x1c\x01\x46\x00\x08" . date('Ymd');
		$val = IPTC::DefaultServiceIdentifier . date('Ymd');
		$envNum = "\x1c\x01\x28\x00\x08" . str_pad(crc32($val) % 100000000, 8, '0', STR_PAD_LEFT);

		$this->obj[IPTCTags::ByLine] = ['abc', '123'];
		$this->obj[IPTCTags::ByLineTitle] = 'def';
		$appRec = "\x1c\x02\x00\x00\x02\x00\x04";
		$bl = "\x1c\x02\x50\x00\x03abc\x1c\x02\x50\x00\x03123";
		$blt = "\x1c\x02\x55\x00\x03def";

		self::assertEquals($iptcData = $envVer . $ff . $fv . $svid . $envNum . $date . $char . $appRec . $bl . $blt, $this->obj->toBinary(false));

		self::assertEquals("Photoshop 3.0\08BIM\x04\x04\0\0" . pack('N', strlen($iptcData)) . $iptcData, $this->obj->toBinary(true));
	}

	public function testTagBinary()
	{
		$data = "test string data";
		self::assertEquals("\x1c\x02\xCA\x00" . chr(strlen($data)) . $data, $this->obj->tagBinary('2#202', $data));

		$data = str_pad("test string data", 512);
		$len = strlen($data);
		self::assertEquals("\x1c\x02\xCA" . chr(($len >> 8) & 0xFF) . chr($len & 0xFF) . $data, $this->obj->tagBinary('2#202', $data));

		$data = str_pad("test string data", 0x8000);
		$len = strlen($data);
		self::assertEquals("\x1c\x02\xCA\x80\x04" . chr(($len >> 24) & 0xFF) . chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF) . $data, $this->obj->tagBinary('2#202', $data));
	}

	public function testValidateIPTCValue()
	{
		// undefined upper boundary
		$value = str_pad("II\x00\x42\x00\x00", 5000);
		$this->obj[IPTCTags::ExifCameraInfo] = $value;
		self::assertEquals(4096, strlen($this->obj[IPTCTags::ExifCameraInfo]));

		$value = 0x58; // 8 bit
		$this->obj[IPTCTags::ColorSequence] = $value;
		self::assertEquals(0x58, $this->obj[IPTCTags::ColorSequence]);

		$value = 0x400;
		$this->obj[IPTCTags::ColorSequence] = $value;
		self::assertEquals(0xFF, $this->obj[IPTCTags::ColorSequence]);

		$value = '15';
		$this->obj[IPTCTags::ColorSequence] = $value;
		self::assertEquals(0x0F, $this->obj[IPTCTags::ColorSequence]);

		$value = 0x9234;
		$this->obj[IPTCTags::IPTCImageWidth] = $value;
		self::assertEquals(0x9234, $this->obj[IPTCTags::IPTCImageWidth]);

		$value = 0xC9234;
		$this->obj[IPTCTags::IPTCImageWidth] = $value;
		self::assertEquals(0xFFFF, $this->obj[IPTCTags::IPTCImageWidth]);

		$value = '1026';
		$this->obj[IPTCTags::IPTCImageWidth] = $value;
		self::assertEquals(1026, $this->obj[IPTCTags::IPTCImageWidth]);

		$value = 0x91235678; // 32 bit
		$this->obj[IPTCTags::DataCompressionMethod] = $value;
		self::assertEquals(0x91235678, $this->obj[IPTCTags::DataCompressionMethod]);

		$value = "8475628"; // 32 bit
		$this->obj[IPTCTags::DataCompressionMethod] = $value;
		self::assertEquals(8475628, $this->obj[IPTCTags::DataCompressionMethod]);

		$value = "b5"; // numeric string
		$this->obj[IPTCTags::EditorialUpdate] = $value;
		self::assertEquals("5 ", $this->obj[IPTCTags::EditorialUpdate]);

		$value = "b5c"; // alpha string
		$this->obj[IPTCTags::CountryPrimaryLocationCode] = $value;
		self::assertEquals("bc ", $this->obj[IPTCTags::CountryPrimaryLocationCode]);

		$value = "B5C"; // alpha string
		$this->obj[IPTCTags::CountryPrimaryLocationCode] = $value;
		self::assertEquals("BC ", $this->obj[IPTCTags::CountryPrimaryLocationCode]);


		$value = "abcABC0123()#@^&.,;'!*? \t\r\n"; // graphic char
		$this->obj[IPTCTags::Destination] = $value;
		self::assertEquals("abcABC0123()#@^&.,;'!*?", $this->obj[IPTCTags::Destination]);

		$value = "abcABC0123()#@^&.,;'!*? \t\r\n"; // graphic char + space
		$this->obj[IPTCTags::Contact] = $value;
		self::assertEquals("abcABC0123()#@^&.,;'!*? \t", $this->obj[IPTCTags::Contact]);

		$value = "abcABC0123()#@^&.,;'!*? \t\r\n"; // graphic char + space + \r\n
		$this->obj[IPTCTags::DocumentNotes] = $value;
		self::assertEquals("abcABC0123()#@^&.,;'!*? \t\r\n", $this->obj[IPTCTags::DocumentNotes]);

		$value = "abcABC0123()#@^&.,;'!*? \t\r\n"; // graphic char + object name
		$this->obj[IPTCTags::UniqueObjectName] = $value;
		self::assertEquals("abcABC0123()#@^&.,;'!", $this->obj[IPTCTags::UniqueObjectName]);
	}

	public function testRoundTrip()
	{
		$now = time();
		$this->obj['Destination'] = ['abc', 'efg'];
		$this->obj['ProductID'] = ['Product1', 'Product2'];
		$this->obj['EnvelopePriority'] = "2";
		$this->obj['TimeSent'] = $now;
		$this->obj['UniqueObjectName'] = "NAME:SUB:CONTEXT:LAST:variable";
		$this->obj['ARMIdentifier'] = 20;
		$this->obj['ARMVersion'] = 21;

		$this->obj['ObjectTypeReference'] = "11:ObjectRef";
		$this->obj['ObjectAttributeReference'] = ["12:ObjAttrRef", '13:SecondAttrRef'];
		$this->obj['ObjectName'] = "Object Name";
		$this->obj['EditStatus'] = "Unit Testing";
		$this->obj['EditorialUpdate'] = "23";
		$this->obj['Urgency'] = "1";
		$this->obj['SubjectReference'] = ["ABCDEF0123456789", "0123456789ABCDEF"];
		$this->obj['Category'] = "aEU";
		$this->obj['SupplementalCategories'] = ['test', 'iptc unit'];

		$this->obj['FixtureIdentifier'] = 'fixtureID1234';
		$this->obj['Keywords'] = ['first', 'iptc', 'test'];
		$this->obj['ContentLocationCode'] = ['ABC', 'BCD', 'CDE'];
		$this->obj['ContentLocationName'] = ['First', 'Second', 'Third'];
		$this->obj['ReleaseDate'] = $now;
		$this->obj['ReleaseTime'] = $now;
		$this->obj['ExpirationDate'] = $now;
		$this->obj['ExpirationTime'] = $now;
		$this->obj['SpecialInstructions'] = "These are special instructions.";
		$this->obj['ActionAdvised'] = "45";

		$this->obj['ReferenceService'] = ["PRADO4.2.0", "ANOTHER123"];
		$this->obj['ReferenceDate'] = [$now, '11/12/2013'];
		$this->obj['ReferenceNumber'] = ['47632500', '00008773'];

		$this->obj['DateCreated'] = $now;
		$this->obj['TimeCreated'] = $now;
		$this->obj['DigitalCreationDate'] = $now;
		$this->obj['DigitalCreationTime'] = $now;
		$this->obj['OriginatingProgram'] = 'PRADO';
		$this->obj['ProgramVersion'] = '4.2.2';
		$this->obj['ObjectCycle'] = 'A';
		$this->obj['By-line'] = ['author1', 'author 2'];
		$this->obj['By-lineTitle'] = ['doctor', 'PhD'];

		$this->obj['City'] = 'Los Angeles';
		$this->obj['Sub-location'] = 'Long Beach';
		$this->obj['Province-State'] = 'California';
		$this->obj['Country-PrimaryLocationCode'] = 'USA';
		$this->obj['Country-PrimaryLocationName'] = 'United Nations - MemberState \'gov\'';
		$this->obj['OriginalTransmissionReference'] = 'WGWC';

		$this->obj['Headline'] = 'Image gets an IPTC in PRADO - First Time!!';
		$this->obj['Credit'] = 'Brad Anderson';
		$this->obj['Source'] = 'belisoful [ut] icloud [dat] com';
		$this->obj['CopyrightNotice'] = 'Copyright ©2023 PRADO';
		$this->obj['Contact'] = ['brad anderson', 'belisoful'];
		$this->obj['Caption-Abstract'] = 'This is a caption of the data with the IPTC';
		$this->obj['LocalCaption'] = 'The local caption';
		$this->obj['Writer-Editor'] = ['editor', 'writer'];
		$this->obj['RasterizedCaption'] = str_pad('', 7360, "\x00");
		$this->obj['ImageType'] = '7A';
		$this->obj['ImageOrientation'] = 'L';
		$this->obj['LanguageIdentifier'] = 'en';

		$this->obj['AudioType'] = '8Z';
		$this->obj['AudioSamplingRate'] = '044100';
		$this->obj['AudioSamplingResolution'] = '08';
		$this->obj['AudioDuration'] = '000120';
		$this->obj['AudioOutcue'] = '....and....  cut';

		$this->obj['JobID'] = 'jobId $&#^ 234.:';
		$this->obj['MasterDocumentID'] = "Master Doc 12345";
		$this->obj['ShortDocumentID'] = "12345";
		$this->obj['UniqueDocumentID'] = "12345ABC";
		$this->obj['OwnerID'] = "belisoful";
		$this->obj['ObjectPreviewFileFormat'] = '11';
		$this->obj['ObjectPreviewFileVersion'] = '2';
		$this->obj['ObjectPreviewData'] = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$this->obj['Prefs'] = 'quality: 75';
		$this->obj['ClassifyState'] = 'unclassified';
		$this->obj['SimilarityIndex'] = '484736';
		$this->obj['DocumentNotes'] = 'My Document Notes, Take heed, unit test ahead';
		$this->obj['DocumentHistory'] = 'checking all variables';
		$this->obj['ExifCameraInfo'] = 'II\x00\x42\x00\x00';
		$this->obj['CatalogSets'] = ['abc', 'def', 'ghi'];

		$this->obj['IPTCPictureNumber'] = "ABCDEF20230210-0";
		$this->obj['IPTCImageWidth'] = 1030;
		$this->obj['IPTCImageHeight'] = 512;
		$this->obj['IPTCPixelWidth'] = 700;
		$this->obj['IPTCPixelHeight'] = 800;
		$this->obj['SupplementalType'] = 3;
		$this->obj['ColorRepresentation'] = 289;
		$this->obj['InterchangeColorSpace'] = 3;
		$this->obj['ColorSequence'] = 3;
		$this->obj['ICC_Profile'] = "ABCDEFGHIJK0123456789";
		$this->obj['LookupTable'] = "1234123412341234";
		$this->obj['NumIndexEntries'] = 700;
		$this->obj['ColorPalette'] = '2345234523452345234523452345';
		$this->obj['IPTCBitsPerSample'] = 16;
		$this->obj['SampleStructure'] = 13;
		$this->obj['ScanningDirection'] = 2;
		$this->obj['IPTCImageRotation'] = 3;
		$this->obj['DataCompressionMethod'] = 0x91873477;
		$this->obj['QuantizationMethod'] = 5;
		$this->obj['EndPoints'] = '13';
		$this->obj['ExcursionTolerance'] = 99;
		$this->obj['BitsPerComponent'] = 8;
		$this->obj['MaximumDensityRange'] = 50899;
		$this->obj['GammaCompensatedValue'] = 30498;

		$this->obj['SizeMode'] = 3;
		$this->obj['MaxSubfileSize'] = 6;
		$this->obj['ObjectSizeAnnounced'] = 6;
		$this->obj['MaximumObjectSize'] = 6;

		$this->obj['SubFile'] = ['A', 'BC', 'DEF'];

		$this->obj['ConfirmedObjectSize'] = 6;

		self::assertEquals('UTF-8', $this->obj['CodedCharacterSet']);

		$data = $this->obj->toBinary(false);
		self::assertEquals(9159, strlen($data));
		$iptc = IPTC::iptcparse($data);
		self::assertNotEquals(false, $iptc);
		self::assertEquals(115, $iptc->count()); //ColorCalibrationMatrix is obsolete, not set
		self::assertEquals(116, count(IPTC::TAG_MAP));
		self::assertEquals(115, $this->obj->count()); //ColorCalibrationMatrix is obsolete, not set
		self::assertEquals($this->obj->toArray(), $iptc->toArray());

		foreach ($this->obj->toArray() as $key => $refVal) {
			self::assertEquals($this->obj[$key], $iptc[$key], "bad $key");
		}
	}


	public function testTList()
	{
		// itemAt, priorityAt, add, remove, and contains are tested.
		$now = time();

		//Add
		$this->obj->add('ProductID', $now);
		$this->obj->add(IPTCTags::ObjectName, $now + 1);
		$this->obj->add('ICC_Profile', $now + 2);

		//Contains
		self::assertTrue($this->obj->contains(IPTCTags::ProductID));
		self::assertTrue($this->obj->contains('ProductID'));
		self::assertTrue($this->obj->contains('productid'));

		self::assertTrue($this->obj->contains(IPTCTags::ObjectName));
		self::assertTrue($this->obj->contains('ObjectName'));
		self::assertTrue($this->obj->contains('objectname'));

		self::assertTrue($this->obj->contains(IPTCTags::ICC_Profile));
		self::assertTrue($this->obj->contains('ICC_Profile'));
		self::assertTrue($this->obj->contains('icc_profile'));

		//ItemAt
		self::assertEquals($now, $this->obj[IPTCTags::ProductID]);
		self::assertEquals($now, $this->obj['ProductID']);
		self::assertEquals($now + 1, $this->obj[IPTCTags::ObjectName]);
		self::assertEquals($now + 1, $this->obj['ObjectName']);
		self::assertEquals($now + 2, $this->obj[IPTCTags::ICC_Profile]);
		self::assertEquals($now + 2, $this->obj['ICC_Profile']);

		//Add null
		$this->obj->add(IPTCTags::ObjectName, null);
		$this->obj->add('ReleaseTime', null);
		$this->obj->add('ICC_Profile', null);

		//contains, null
		self::assertTrue($this->obj->contains(IPTCTags::ObjectName));
		self::assertTrue($this->obj->contains('ObjectName'));
		self::assertTrue($this->obj->contains('objectname'));

		self::assertTrue($this->obj->contains(IPTCTags::ReleaseTime));
		self::assertTrue($this->obj->contains('ReleaseTime'));
		self::assertTrue($this->obj->contains('releasetime'));

		//remove
		$this->obj->remove(IPTCTags::ObjectName);
		$this->obj->remove('ReleaseTime');
		$this->obj->remove('ICC_Profile');

		//contains, false
		self::assertFalse($this->obj->contains(IPTCTags::ObjectName));
		self::assertFalse($this->obj->contains('ObjectName'));
		self::assertFalse($this->obj->contains('objectname'));

		self::assertFalse($this->obj->contains(IPTCTags::ReleaseTime));
		self::assertFalse($this->obj->contains('ReleaseTime'));
		self::assertFalse($this->obj->contains('releasetime'));
	}


	public function testTListAddTypes()
	{
		$now = $this->obj[IPTCTags::TimeSent] = time();
		self::assertEquals(date('HisO'), $this->obj[IPTCTags::TimeSent]);

		$this->obj[IPTCTags::TimeSent] = '15:45:11';
		self::assertEquals('154511+0000', $this->obj[IPTCTags::TimeSent]);

		$this->obj[IPTCTags::DateSent] = $now;
		self::assertEquals(date('Ymd'), $this->obj[IPTCTags::DateSent]);

		$this->obj[IPTCTags::DateSent] = '12/01/2022';
		self::assertEquals('20221201', $this->obj[IPTCTags::DateSent]);

		$this->obj[IPTCTags::ReferenceDate] = ['01/01/2000', '11/29/2023'];
		self::assertEquals(['20000101', '20231129'], $this->obj[IPTCTags::ReferenceDate]);

		$this->obj[IPTCTags::ReferenceDate] = ['01/01/2000', '11/29/2023'];
		self::assertEquals(['20000101', '20231129'], $this->obj[IPTCTags::ReferenceDate]);

		$this->obj[IPTCTags::SupplementalType] = '45';
		self::assertEquals(45, $this->obj[IPTCTags::SupplementalType]);
		self::assertTrue(is_int($this->obj[IPTCTags::SupplementalType]));

		$this->obj[IPTCTags::IPTCPixelHeight] = '45920';
		self::assertEquals(45920, $this->obj[IPTCTags::IPTCPixelHeight]);
		self::assertTrue(is_int($this->obj[IPTCTags::IPTCPixelHeight]));

		$this->obj[IPTCTags::DataCompressionMethod] = '28746065';
		self::assertEquals(28746065, $this->obj[IPTCTags::DataCompressionMethod]);
		self::assertTrue(is_int($this->obj[IPTCTags::DataCompressionMethod]));

		$this->obj[IPTCTags::ServiceIdentifier] = 'new value';
		$this->obj[IPTCTags::DateSent] = time() - 10;
		$ref = $this->obj[IPTCTags::EnvelopeNumber] = '12345678';
		self::assertEquals($ref, $this->obj[IPTCTags::EnvelopeNumber]);

		$this->obj[IPTCTags::ServiceIdentifier] = 'new value';
		self::assertEquals($ref, $this->obj[IPTCTags::EnvelopeNumber]);

		$this->obj[IPTCTags::EnvelopeNumber] = '12345678';
		$this->obj[IPTCTags::DateSent] = time();
		self::assertEquals($ref, $this->obj[IPTCTags::EnvelopeNumber]);

		unset($this->obj[IPTCTags::EnvelopeNumber]);
	}

	public function testWidth()
	{
		self::assertNull($this->obj->getWidth());
		self::assertFalse($this->obj->contains(IPTCTags::IPTCImageWidth));
		$this->obj->setWidth(55);
		self::assertTrue($this->obj->contains(IPTCTags::IPTCImageWidth));
		self::assertEquals(55, $this->obj->getWidth());
		$this->obj->setWidth(null);
		self::assertFalse($this->obj->contains(IPTCTags::IPTCImageWidth));
		self::assertNull($this->obj->getWidth());
	}

	public function testHeight()
	{
		self::assertNull($this->obj->getHeight());
		self::assertFalse($this->obj->contains(IPTCTags::IPTCImageHeight));
		$this->obj->setHeight(55);
		self::assertTrue($this->obj->contains(IPTCTags::IPTCImageHeight));
		self::assertEquals(55, $this->obj->getHeight());
		$this->obj->setHeight(null);
		self::assertFalse($this->obj->contains(IPTCTags::IPTCImageHeight));
		self::assertNull($this->obj->getHeight());
	}

	public function testICCProfile()
	{
		self::assertFalse($this->obj->hasICCProfile());
		self::assertFalse($this->obj->contains(IPTCTags::ICC_Profile));
		self::assertNull($this->obj->getICCProfile());

		$this->obj->setICCProfile($data = "abcdef0123456789");

		self::assertTrue($this->obj->contains(IPTCTags::ICC_Profile));
		self::assertTrue($this->obj->hasICCProfile());
		self::assertEquals($data, $this->obj->getICCProfile());

		$this->obj->setICCProfile(null);

		self::assertFalse($this->obj->contains(IPTCTags::ICC_Profile));
		self::assertFalse($this->obj->hasICCProfile());
		self::assertNull($this->obj->getICCProfile());
	}

	public function testIPTC()
	{
		self::assertTrue($this->obj->hasIPTC());
		self::assertEquals($this->obj, $this->obj->getIPTC());

		$this->obj[IPTCTags::TimeSent] = $time = time();
		$binary = $this->obj->toBinary();

		self::assertEquals(8, $this->obj->getCount());
		self::assertTrue($this->obj->setIPTC(null));
		self::assertEquals(0, $this->obj->getCount());

		self::assertTrue($this->obj->setIPTC($binary));
		self::assertEquals(8, $this->obj->getCount());
		self::assertEquals(IPTC::formatIPTCTime($time), $this->obj[IPTCTags::TimeSent]);

		$iptc = new IPTC('');
		$iptc[IPTCTags::DateSent] = $time;

		self::assertTrue($this->obj->setIPTC($iptc));
		self::assertEquals(1, $this->obj->getCount());
		self::assertEquals(IPTC::formatIPTCDate($time), $this->obj[IPTCTags::DateSent]);
		self::assertNull($this->obj[IPTCTags::TimeSent]);
	}


	public function testEXIF()
	{
		self::assertFalse($this->obj->hasEXIF());
		self::assertFalse($this->obj->contains(IPTCTags::ExifCameraInfo));
		self::assertNull($this->obj->getEXIF());

		self::assertTrue($this->obj->setEXIF($data = "abcdef0123456789"));

		self::assertTrue($this->obj->contains(IPTCTags::ExifCameraInfo));
		self::assertTrue($this->obj->hasEXIF());
		self::assertEquals($data, $this->obj->getEXIF());

		$this->obj->setEXIF(null);

		self::assertFalse($this->obj->contains(IPTCTags::ExifCameraInfo));
		self::assertFalse($this->obj->hasEXIF());
		self::assertNull($this->obj->getEXIF());
	}

	public function testXMP()
	{
		self::assertFalse($this->obj->hasXMP());
		self::assertFalse($this->obj->contains(IPTCTags::ExifCameraInfo));
		self::assertNull($this->obj->getEXIF());

		self::assertTrue($this->obj->setEXIF($data = "abcdef0123456789"));

		self::assertTrue($this->obj->contains(IPTCTags::ExifCameraInfo));
		self::assertTrue($this->obj->hasEXIF());
		self::assertEquals($data, $this->obj->getEXIF());

		$this->obj->setEXIF(null);

		self::assertFalse($this->obj->contains(IPTCTags::ExifCameraInfo));
		self::assertFalse($this->obj->hasEXIF());
		self::assertNull($this->obj->getEXIF());
	}

	public function testReadRejectsANonStringSource()
	{
		$block = 42;
		self::assertFalse($this->obj->read($block));
		// The refused source leaves the existing record set alone.
		self::assertEquals(1, $this->obj->getCount());
		self::assertTrue($this->obj->contains(IPTCTags::CodedCharacterSet));
	}

	public function testReadStopsWhenTheDeclaredSizeOutrunsTheData()
	{
		// The declared length runs past the end of the block: the reader takes the
		// datasets that are there and stops at the end of the data.
		$block = ["\x1C\x02\x05\x00\x04Test", 100, 0];
		self::assertTrue($this->obj->read($block));
		self::assertEquals('Test', $this->obj[IPTCTags::ObjectName]);
	}

	public function testReadExtendedLengthDataset()
	{
		// The 0x8004 length marker introduces a 32-bit length.
		$block = ["\x1C\x02\x05" . pack('n', 0x8004) . pack('N', 4) . 'Test', null, 0];
		self::assertTrue($this->obj->read($block));
		self::assertEquals('Test', $this->obj[IPTCTags::ObjectName]);
	}

	public function testReadRejectsMalformedDatasets()
	{
		// Only one of the two length bytes is present.
		$block = ["\x1C\x02\x05\x00", 100, 0];
		self::assertFalse($this->obj->read($block));

		// The 32-bit extended length is cut short.
		$block = ["\x1C\x02\x05" . pack('n', 0x8004) . "\x00\x00", 100, 0];
		self::assertFalse($this->obj->read($block));

		// The length is declared but the value bytes are missing.
		$block = ["\x1C\x02\x05\x00\x04", 100, 0];
		self::assertFalse($this->obj->read($block));

		// Record 9 dataset 99 is not an IIM dataset.
		$block = "\x1C\x09\x63\x00\x00";
		self::assertFalse($this->obj->read($block));
	}

	public function testUnknownTagKeysAreRefused()
	{
		self::assertNull($this->obj['NotATag']);
		self::assertFalse($this->obj->contains('NotATag'));
		self::assertNull($this->obj->remove('NotATag'));
		self::assertEquals('', IPTC::tagBinary('9#099', 'value'));

		self::expectException(\InvalidArgumentException::class);
		$this->obj['NotATag'] = 'value';
	}

	public function testUnboundedDatasetIsStoredVerbatim()
	{
		// ColorCalibrationMatrix declares no size bounds, so nothing is padded or trimmed.
		$this->obj[IPTCTags::ColorCalibrationMatrix] = "\x01\x02\x03";
		self::assertSame("\x01\x02\x03", $this->obj[IPTCTags::ColorCalibrationMatrix]);
	}

	public function testClearPrivateDataRederivesAnInconsistentEnvelopeNumber()
	{
		$iptc = new IPTC('');
		$iptc[IPTCTags::ServiceIdentifier] = 'ACME';
		$iptc[IPTCTags::DateSent] = '20260717';
		$iptc[IPTCTags::EnvelopeNumber] = '12345678';   // not derived from the pair above
		$iptc[IPTCTags::City] = 'Oslo';

		// The city goes, the envelope date is replaced, and the envelope number that no
		// longer matches it is re-derived from the scrubbed date.
		self::assertEquals(3, $iptc->clearPrivateData());
		self::assertFalse($iptc->contains(IPTCTags::City));
		self::assertEquals(IPTC::ScrubbedDate, $iptc[IPTCTags::DateSent]);
		self::assertEquals(
			IPTC::computeEnvelopeNumber('ACME', IPTC::ScrubbedDate),
			$iptc[IPTCTags::EnvelopeNumber],
		);

		// A second scrub has nothing left to do.
		self::assertEquals(0, $iptc->clearPrivateData());
	}

	public function testRasterizedCaptionImageGuards()
	{
		self::assertNull($this->obj->getRasterizedCaptionImage());

		// A non-string value skips the dataset's size fixing, so the payload is not the
		// mandated 460x128 bitmap and the caption is reported as malformed.
		$this->obj[IPTCTags::RasterizedCaption] = 12345;
		self::assertFalse($this->obj->getRasterizedCaptionImage());
	}

	/**
	 * Runs a callable with a stand-in graphics library registered for the GD mode.
	 * @param ImageGraphicsLibraryInterface $library The stand-in library.
	 * @param callable $callback The code to run.
	 */
	private function withGraphicsLibrary(ImageGraphicsLibraryInterface $library, callable $callback): void
	{
		$property = new ReflectionProperty(ImageGraphics::class, '_libraries');
		$property->setAccessible(true);
		$saved = $property->getValue();
		$property->setValue(null, [ImageGraphicsMode::GD => $library] + $saved);
		try {
			$callback();
		} finally {
			$property->setValue(null, $saved);
		}
	}

	public function testSetRasterizedCaptionImageReportsGraphicsFailures()
	{
		$source = imagecreatetruecolor(46, 13);

		$noResample = $this->createMock(ImageGraphicsLibraryInterface::class);
		$noResample->method('resampled')->willReturn(false);
		$this->withGraphicsLibrary($noResample, function () use ($source) {
			self::assertFalse($this->obj->setRasterizedCaptionImage($source));
		});
		self::assertFalse($this->obj->contains(IPTCTags::RasterizedCaption));

		$noMono = $this->createMock(ImageGraphicsLibraryInterface::class);
		$noMono->method('resampled')->willReturn(imagecreatetruecolor(460, 128));
		$noMono->method('monoPixels')->willReturn(false);
		$this->withGraphicsLibrary($noMono, function () use ($source) {
			self::assertFalse($this->obj->setRasterizedCaptionImage($source));
		});
		self::assertFalse($this->obj->contains(IPTCTags::RasterizedCaption));

		imagedestroy($source);
	}

	public function testReadFromANonSeekableStream()
	{
		// A stream that cannot be seeked has no position to remember and no position to
		// restore: the window has to start where the stream already is.  The stub throws
		// from both tell() and seek(), so the read only succeeds if neither is called.
		$payload = IPTC::tagBinary(IPTCTags::ObjectName, 'Streamed Title')
			. IPTC::tagBinary(IPTCTags::City, 'Bergen');
		$block = new TIPTCNonSeekableStream($payload);

		self::assertTrue($this->obj->read($block));
		self::assertSame('Streamed Title', $this->obj[IPTCTags::ObjectName]);
		self::assertSame('Bergen', $this->obj[IPTCTags::City]);
		// The block variable is replaced by the bytes the window yielded.
		self::assertSame($payload, $block);
	}

	public function testShortValuesArePaddedToTheirDatasetMinimum()
	{
		// A numeric left-zero dataset is padded on the left with '0' ...
		$this->obj['ActionAdvised'] = '5';
		self::assertSame('05', $this->obj['ActionAdvised']);
		$this->obj['AudioDuration'] = '120';
		self::assertSame('000120', $this->obj['AudioDuration']);

		// ... while every other dataset is padded on the right with spaces.
		$this->obj['LanguageIdentifier'] = 'e';
		self::assertSame('e ', $this->obj['LanguageIdentifier']);
		$this->obj['ObjectTypeReference'] = '1';
		self::assertSame('1  ', $this->obj['ObjectTypeReference']);
		// The same holds for an undefined-type dataset, which is not character filtered.
		$this->obj['RasterizedCaption'] = "\x01\x02";
		self::assertSame(str_pad("\x01\x02", 7360, ' '), $this->obj['RasterizedCaption']);
	}

	public function testAnUnparsableDateBecomesTheCurrentDate()
	{
		// Neither an IPTC date nor anything strtotime() understands: the dataset falls
		// back to today rather than storing the text.
		$this->obj['DateCreated'] = 'not a date at all';
		self::assertSame(date('Ymd'), $this->obj['DateCreated']);

		// A date it does understand is converted, not replaced.
		$this->obj['ReleaseDate'] = '17 July 2026';
		self::assertSame('20260717', $this->obj['ReleaseDate']);
	}

	public function testReadFromAStreamAtAnExplicitOffset()
	{
		// [source, length, offset] against a seekable stream: the window must start at the
		// offset given, not at wherever the stream happens to sit, and the surrounding
		// parse position must be restored afterwards.
		$iptc = new IPTC();
		$iptc[IPTCTags::ByLine] = ['Jane Doe'];
		$iptc[IPTCTags::City] = 'Bergen';
		$block = $iptc->toBinary(false);

		$lead = str_repeat("\xEE", 37);
		$stream = new TestPsr7Stream($lead . $block . str_repeat("\xDD", 11));
		$stream->seek(5);   // the surrounding parse sits somewhere else entirely

		$read = new IPTC();
		$source = [$stream, strlen($block), strlen($lead)];
		self::assertTrue($read->read($source));
		self::assertSame(['Jane Doe'], $read[IPTCTags::ByLine]);
		self::assertSame('Bergen', $read[IPTCTags::City]);
		self::assertSame(5, $stream->tell(), 'the surrounding position is restored');

		// Without the offset the window would start at the stream position, which is not
		// an IIM block at all -- so the explicit offset is what made the read succeed.
		$stream->seek(5);
		$noOffset = new IPTC();
		$plain = [$stream, strlen($block), null];
		self::assertFalse($noOffset->read($plain));
	}
}

/**
 * A read-only stream that cannot be seeked and refuses to report its position, so
 * {@see IPTC::read()} must take its window from where the stream already is.
 */
class TIPTCNonSeekableStream implements Psr\Http\Message\StreamInterface
{
	private int $_position = 0;

	public function __construct(private string $buffer)
	{
	}

	public function read(int $length): string
	{
		$bytes = substr($this->buffer, $this->_position, $length);
		$this->_position += strlen($bytes);
		return $bytes;
	}

	public function eof(): bool
	{
		return $this->_position >= strlen($this->buffer);
	}

	public function getContents(): string
	{
		$bytes = substr($this->buffer, $this->_position);
		$this->_position = strlen($this->buffer);
		return $bytes;
	}

	public function __toString(): string
	{
		return $this->buffer;
	}

	public function close(): void
	{
	}

	public function detach()
	{
		return null;
	}

	public function getSize(): ?int
	{
		return strlen($this->buffer);
	}

	public function tell(): int
	{
		throw new RuntimeException('the position of a non-seekable stream is not reported');
	}

	public function isSeekable(): bool
	{
		return false;
	}

	public function seek(int $offset, int $whence = SEEK_SET): void
	{
		throw new RuntimeException('not seekable');
	}

	public function rewind(): void
	{
		throw new RuntimeException('not seekable');
	}

	public function isWritable(): bool
	{
		return false;
	}

	public function write(string $string): int
	{
		throw new RuntimeException('not writable');
	}

	public function isReadable(): bool
	{
		return true;
	}

	public function getMetadata(?string $key = null)
	{
		return $key === null ? [] : null;
	}
}
