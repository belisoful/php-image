<?php

use Belisoful\Image\TIFF\TIFFDataType;
use Belisoful\Image\TIFF\TIFFDocument;
use Belisoful\Image\TIFF\TIFFIfd;
use Belisoful\Image\TIFF\TIFFTag;

class TIFFDocumentTest extends PHPUnit\Framework\TestCase
{
	private function sampleDocument(bool $bigEndian): TIFFDocument
	{
		$tiff = new TIFFDocument();
		$tiff->setIsBigEndian($bigEndian);
		$ifd0 = new TIFFIfd();
		$ifd0->setTagValues(256, TIFFDataType::ULong, [640]);
		$ifd0->setTagValues(257, TIFFDataType::ULong, [480]);
		$ifd0->setTagValues(271, TIFFDataType::Ascii, "PradoCam\0");
		$ifd0->setTagValues(282, TIFFDataType::URational, [[72, 1]]);
		$exif = new TIFFIfd();
		$exif->setTagValues(33434, TIFFDataType::URational, [[1, 125]]);
		$exif->setTagValues(37377, TIFFDataType::SRational, [[-7, 2]]);
		$pointer = $ifd0->setTagValues(TIFFDocument::ExifIfdTag, TIFFDataType::ULong, [0]);
		$pointer->setSubIfd($exif);
		$tiff->addIfd($ifd0);
		return $tiff;
	}

	public function testDataTypeSizes()
	{
		self::assertSame(1, TIFFDataType::getSize(TIFFDataType::UByte));
		self::assertSame(2, TIFFDataType::getSize(TIFFDataType::UShort));
		self::assertSame(4, TIFFDataType::getSize(TIFFDataType::Float));
		self::assertSame(8, TIFFDataType::getSize(TIFFDataType::Double));
		self::assertSame(8, TIFFDataType::getSize(TIFFDataType::SRational));
		self::assertFalse(TIFFDataType::isValid(0));
		self::assertFalse(TIFFDataType::isValid(13));
	}

	public function testDataTypePackUnpackRoundTrip()
	{
		$cases = [
			[TIFFDataType::UByte, [0, 1, 255]],
			[TIFFDataType::SByte, [-128, -1, 0, 127]],
			[TIFFDataType::UShort, [0, 1, 65535]],
			[TIFFDataType::SShort, [-32768, -1, 32767]],
			[TIFFDataType::ULong, [0, 1, 4294967295]],
			[TIFFDataType::SLong, [-2147483648, -1, 2147483647]],
			[TIFFDataType::URational, [[1, 125], [4294967295, 1]]],
			[TIFFDataType::SRational, [[-7, 2], [1, -3]]],
			[TIFFDataType::Double, [0.0, -1.5, 1234.5678]],
		];
		foreach ($cases as [$type, $values]) {
			foreach ([true, false] as $bigEndian) {
				$packed = TIFFDataType::pack($type, $values, $bigEndian);
				self::assertSame(TIFFDataType::getSize($type) * count($values), strlen($packed));
				self::assertSame($values, TIFFDataType::unpack($type, $packed, $bigEndian), "type $type");
			}
		}
		// Floats round-trip through 32-bit precision.
		foreach ([true, false] as $bigEndian) {
			$packed = TIFFDataType::pack(TIFFDataType::Float, [1.5, -0.25], $bigEndian);
			self::assertEqualsWithDelta([1.5, -0.25], TIFFDataType::unpack(TIFFDataType::Float, $packed, $bigEndian), 1e-6);
		}
		self::assertSame('abc', TIFFDataType::pack(TIFFDataType::Ascii, 'abc', true));
		self::assertSame("\x01\x02", TIFFDataType::unpack(TIFFDataType::Undefined, "\x01\x02", false));
	}

	public function testEveryKnownDataTypeIsPackable()
	{
		// Every type getSize() admits is handled: the three string types by the early
		// return, the other ten by an arm of the pack/unpack match, so no value set that
		// clears getSize() can fall through the codec.
		$samples = [
			TIFFDataType::UByte => [1],
			TIFFDataType::Ascii => "a\0",
			TIFFDataType::UShort => [1],
			TIFFDataType::ULong => [1],
			TIFFDataType::URational => [[1, 2]],
			TIFFDataType::SByte => [-1],
			TIFFDataType::Undefined => "\x01\x02",
			TIFFDataType::SShort => [-1],
			TIFFDataType::SLong => [-1],
			TIFFDataType::SRational => [[-1, 2]],
			TIFFDataType::Float => [1.0],
			TIFFDataType::Double => [1.0],
			TIFFDataType::Utf8 => "\u{00E9}\0",
		];
		self::assertSame(array_keys(TIFFDataType::Sizes), array_keys($samples));
		foreach ($samples as $type => $values) {
			$packed = TIFFDataType::pack($type, $values, true);
			self::assertSame(TIFFDataType::getSize($type) * TIFFDataType::countOf($type, $values), strlen($packed), "type $type");
			self::assertSame($values, TIFFDataType::unpack($type, $packed, true), "type $type");
		}
	}

	public function testComposeParseRoundTripBothOrders()
	{
		foreach ([true, false] as $bigEndian) {
			$bytes = $this->sampleDocument($bigEndian)->toBinary();
			self::assertSame($bigEndian ? 'MM' : 'II', substr($bytes, 0, 2));

			$tiff = TIFFDocument::fromString($bytes);
			self::assertSame($bigEndian, $tiff->getIsBigEndian());
			self::assertSame([], $tiff->getWarnings());
			$ifd0 = $tiff->getIfd(0);
			self::assertSame(640, $ifd0->getTagValue(256));
			self::assertSame('PradoCam', $ifd0->getTagValue(271));
			self::assertSame([[72, 1]], $ifd0->getTag(282)->getValues());
			self::assertEqualsWithDelta(72.0, $ifd0->getTag(282)->getRational(), 1e-9);

			$exif = $ifd0->getTag(TIFFDocument::ExifIfdTag)->getSubIfd();
			self::assertNotNull($exif);
			self::assertSame([[1, 125]], $exif->getTag(33434)->getValues());
			self::assertSame([[-7, 2]], $exif->getTag(37377)->getValues());
			self::assertEqualsWithDelta(-3.5, $exif->getTag(37377)->getRational(), 1e-9);
		}
	}

	public function testIfd1Chain()
	{
		$tiff = $this->sampleDocument(true);
		$ifd1 = new TIFFIfd();
		$ifd1->setTagValues(513, TIFFDataType::ULong, [1234]);
		$tiff->addIfd($ifd1);

		$reparsed = TIFFDocument::fromString($tiff->toBinary());
		self::assertCount(2, $reparsed->getIfds());
		self::assertSame(1234, $reparsed->getIfd(1)->getTagValue(513));
		self::assertNull($reparsed->getIfd(2));
	}

	public function testPreserveOffsetPinsValueArea()
	{
		$tiff = $this->sampleDocument(false);
		$note = str_repeat("\xAB", 64);
		$noteTag = $tiff->getIfd(0)->getTag(TIFFDocument::ExifIfdTag)->getSubIfd()
			->setTagValues(37500, TIFFDataType::Undefined, $note);
		$noteTag->setOffset(0x200);
		$noteTag->setPreserveOffset(true);

		$bytes = $tiff->toBinary();
		self::assertSame($note, substr($bytes, 0x200, 64));

		$reparsed = TIFFDocument::fromString($bytes);
		$reTag = $reparsed->getIfd(0)->getTag(TIFFDocument::ExifIfdTag)->getSubIfd()->getTag(37500);
		self::assertSame($note, $reTag->getValues());
		self::assertSame(0x200, $reTag->getOffset());
	}

	public function testInlineValuesStayInline()
	{
		$tiff = new TIFFDocument();
		$ifd = new TIFFIfd();
		$ifd->setTagValues(305, TIFFDataType::Ascii, "abc\0");     // exactly 4 bytes: inline
		$ifd->setTagValues(296, TIFFDataType::UShort, [2]);
		$tiff->addIfd($ifd);
		$bytes = $tiff->toBinary();
		// header(8) + count(2) + 2 entries(24) + next(4) = 38 bytes, nothing out-of-line
		self::assertSame(38, strlen($bytes));
		$reparsed = TIFFDocument::fromString($bytes);
		self::assertSame('abc', $reparsed->getIfd(0)->getTagValue(305));
		self::assertSame(2, $reparsed->getIfd(0)->getTagValue(296));
	}

	public function testEmptyDocumentComposesABareHeader()
	{
		// With no IFD to point at, the header's first-IFD pointer is zero and the file
		// is the eight header bytes and nothing else.
		$tiff = new TIFFDocument();
		self::assertSame("MM\x00\x2A\x00\x00\x00\x00", $tiff->toBinary());

		$tiff->setIsBigEndian(false);
		self::assertSame("II\x2A\x00\x00\x00\x00\x00", $tiff->toBinary());

		$reparsed = TIFFDocument::fromString($tiff->toBinary());
		self::assertSame([], $reparsed->getIfds());
		self::assertSame([], $reparsed->getWarnings());
	}

	public function testReservedSpacesAnswerLowestOffsetFirst()
	{
		// Make's ten-byte value area sits at 52 and Model's at 40, so the pins are
		// collected in tag order but must be reported in offset order.
		$bytes = "MM\x00\x2A\x00\x00\x00\x08"
			. "\x00\x02"
			. "\x01\x0F\x00\x02\x00\x00\x00\x0A\x00\x00\x00\x34"   // Make, 10 Ascii at 52
			. "\x01\x10\x00\x02\x00\x00\x00\x0A\x00\x00\x00\x28"   // Model, 10 Ascii at 40
			. "\x00\x00\x00\x00"
			. "\x00\x00"                                           // 38-39 pad
			. "Scanner-1\0"                                        // 40-49
			. "\x00\x00"                                           // 50-51 pad
			. "PradoCam1\0";                                       // 52-61

		$tiff = TIFFDocument::fromString($bytes);
		self::assertSame('PradoCam1', $tiff->getIfd(0)->getTagValue(271));
		self::assertSame([], $tiff->getReservedSpaces());   // nothing is pinned yet

		$tiff->getIfd(0)->getTag(271)->setPreserveOffset(true);
		$tiff->getIfd(0)->getTag(272)->setPreserveOffset(true);
		self::assertSame([[40, 10], [52, 10]], $tiff->getReservedSpaces());

		// The writer places everything else around exactly those ranges.
		$out = $tiff->toBinary();
		self::assertSame("Scanner-1\0", substr($out, 40, 10));
		self::assertSame("PradoCam1\0", substr($out, 52, 10));
	}

	public function testMalformedHeaderThrows()
	{
		foreach (['', 'short', "XX\x00\x2A\x00\x00\x00\x08", "MM\x00\x2B\x00\x00\x00\x08"] as $bad) {
			try {
				TIFFDocument::fromString($bad);
				self::fail('parse accepted malformed header');
			} catch (\RuntimeException $e) {
				self::assertInstanceOf(\UnexpectedValueException::class, $e);
			}
		}
	}

	public function testTolerantParseCollectsWarnings()
	{
		// Valid header; IFD with one entry whose value offset runs past the data.
		$bytes = "MM\x00\x2A\x00\x00\x00\x08"
			. "\x00\x01"
			. "\x01\x0F\x00\x02\x00\x00\x00\x20\x00\x00\xFF\x00"   // Ascii count 32 at offset 0xFF00
			. "\x00\x00\x00\x00";
		$tiff = TIFFDocument::fromString($bytes);
		self::assertNotSame([], $tiff->getWarnings());
		self::assertNull($tiff->getIfd(0)->getTag(271));
	}

	public function testDataTypeSizeRejectsAnUnknownType()
	{
		foreach ([0, 13, 128] as $type) {
			try {
				TIFFDataType::getSize($type);
				self::fail("getSize accepted data type $type");
			} catch (\InvalidArgumentException $e) {
				self::assertInstanceOf(\InvalidArgumentException::class, $e);
			}
		}
	}

	public function testParseWarnsOnALoopingIfdChain()
	{
		// IFD0 at 8 chains to IFD1 at 26, whose next pointer loops back to IFD0.
		$bytes = "MM\x00\x2A\x00\x00\x00\x08"
			. "\x00\x01" . "\x01\x0F\x00\x02\x00\x00\x00\x04" . "abc\0" . "\x00\x00\x00\x1A"
			. "\x00\x01" . "\x01\x10\x00\x02\x00\x00\x00\x04" . "def\0" . "\x00\x00\x00\x08";

		$tiff = TIFFDocument::fromString($bytes);
		self::assertCount(2, $tiff->getIfds());
		self::assertSame('abc', $tiff->getIfd(0)->getTagValue(271));
		self::assertSame('def', $tiff->getIfd(1)->getTagValue(272));
		self::assertNotEmpty(array_filter($tiff->getWarnings(), fn ($w) => str_contains($w, 'loops back to offset 8')));
	}

	public function testParseWarnsOnAnIfdOutsideTheData()
	{
		// A valid header whose first-IFD pointer addresses the very end of the data.
		$tiff = TIFFDocument::fromString("MM\x00\x2A\x00\x00\x00\x08");
		self::assertSame([], $tiff->getIfds());
		self::assertSame(['IFD offset 8 is outside the data'], $tiff->getWarnings());
	}

	public function testParseClampsAnEntryCountBeyondTheData()
	{
		// The table declares five entries but only one fits in the data.
		$bytes = "MM\x00\x2A\x00\x00\x00\x08"
			. "\x00\x05"
			. "\x01\x0F\x00\x02\x00\x00\x00\x04" . "abc\0"
			. "\x00\x00\x00\x00";

		$tiff = TIFFDocument::fromString($bytes);
		self::assertSame('abc', $tiff->getIfd(0)->getTagValue(271));
		self::assertCount(1, $tiff->getIfd(0)->getTags());
		self::assertSame(['IFD at 8 declares 5 entries beyond the data'], $tiff->getWarnings());
	}

	public function testParseSkipsAnUnknownDataType()
	{
		$bytes = "MM\x00\x2A\x00\x00\x00\x08"
			. "\x00\x02"
			. "\x01\x0F\x00\x63\x00\x00\x00\x04\x00\x00\x00\x00"    // tag 271, data type 99
			. "\x01\x10\x00\x02\x00\x00\x00\x04" . "def\0"
			. "\x00\x00\x00\x00";

		$tiff = TIFFDocument::fromString($bytes);
		self::assertNull($tiff->getIfd(0)->getTag(271));
		self::assertSame('def', $tiff->getIfd(0)->getTagValue(272));
		self::assertSame(['tag 271 has unknown data type 99'], $tiff->getWarnings());
	}

	public function testMismatchedOffsetAndCountTagsAreNotCaptured()
	{
		$tiff = new TIFFDocument();
		$ifd = new TIFFIfd();
		$ifd->setTagValues(256, TIFFDataType::ULong, [4]);
		$ifd->setTagValues(273, TIFFDataType::ULong, [8, 8]);   // two strip offsets
		$ifd->setTagValues(279, TIFFDataType::ULong, [4]);      // but one byte count
		$tiff->addIfd($ifd);

		$reparsed = TIFFDocument::fromString($tiff->toBinary());
		self::assertNull($reparsed->getIfd(0)->getTag(273)->getExternalData());
		self::assertSame(['tag 273 has 2 offsets but 1 byte counts'], $reparsed->getWarnings());
	}

	public function testScannedMismatchedOffsetAndCountTagsAreNotDeferred()
	{
		$tiff = new TIFFDocument();
		$ifd = new TIFFIfd();
		$ifd->setTagValues(256, TIFFDataType::ULong, [4]);
		$ifd->setTagValues(273, TIFFDataType::ULong, [8, 8]);   // two strip offsets
		$ifd->setTagValues(279, TIFFDataType::ULong, [4]);      // but one byte count
		$tiff->addIfd($ifd);

		$source = TestIOHelper::dataResource($tiff->toBinary());
		$scanned = TIFFDocument::scanStream($source, null, 16777216, true);
		self::assertNull($scanned->getIfd(0)->getTag(273)->getExternalData());
		self::assertSame(['tag 273 has 2 offsets but 1 byte counts'], $scanned->getWarnings());
		fclose($source);
	}

	public function testByteCountsTagIsRetypedForExternalData()
	{
		// A byte-counts tag of an inapplicable type is retyped to ULong on compose.
		$strip = str_repeat("\xC3", 40);
		$tiff = new TIFFDocument();
		$ifd = new TIFFIfd();
		$offsets = $ifd->setTagValues(273, TIFFDataType::ULong, [0]);
		$ifd->setTagValues(279, TIFFDataType::Ascii, "40\0");
		$offsets->setExternalData([$strip]);
		$tiff->addIfd($ifd);

		$reparsed = TIFFDocument::fromString($tiff->toBinary());
		$counts = $reparsed->getIfd(0)->getTag(279);
		self::assertSame(TIFFDataType::ULong, $counts->getType());
		self::assertSame([40], $counts->getValues());
		self::assertSame([$strip], $reparsed->getIfd(0)->getTag(273)->getExternalData());
	}

	public function testTagConvenienceAccessors()
	{
		$tag = new TIFFTag(305, TIFFDataType::Ascii, "Prado\0");
		self::assertSame('Prado', $tag->getValue());
		self::assertSame(6, $tag->getCount());

		$multi = new TIFFTag(532, TIFFDataType::ULong, [1, 2, 3]);
		self::assertSame([1, 2, 3], $multi->getValue());
		self::assertNull($multi->getRational());

		$ifd = new TIFFIfd();
		$ifd->setTag($tag);
		self::assertTrue($ifd->hasTag(305));
		self::assertSame('Prado', $ifd->getTagValue(305));
		self::assertSame($tag, $ifd->removeTag(305));
		self::assertFalse($ifd->hasTag(305));
		self::assertNull($ifd->getTagValue(305));
	}
}
