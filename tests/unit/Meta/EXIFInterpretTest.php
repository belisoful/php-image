<?php

use Belisoful\Image\Meta\EXIFTags;
use Belisoful\Image\TIFF\TIFFDataType;
use Belisoful\Image\TIFF\TIFFTag;

class EXIFInterpretTest extends PHPUnit\Framework\TestCase
{
	public function testCodedStringUserComment()
	{
		$ascii = new TIFFTag(37510, TIFFDataType::Undefined, "ASCII\x00\x00\x00Hello EXIF\x00");
		self::assertSame('Hello EXIF', EXIFTags::textValue($ascii, EXIFTags::EXIF));

		$unicode = new TIFFTag(37510, TIFFDataType::Undefined, "UNICODE\x00" . mb_convert_encoding('Ünïcode ✓', 'UTF-16BE', 'UTF-8'));
		self::assertSame('Ünïcode ✓', EXIFTags::textValue($unicode, EXIFTags::EXIF));

		$unknown = new TIFFTag(37510, TIFFDataType::Undefined, "\x00\x00\x00\x00\x00\x00\x00\x00raw text\x00");
		self::assertStringContainsString('raw text', (string) EXIFTags::textValue($unknown, EXIFTags::EXIF));
	}

	public function testCodedStringUnicodeByteOrderIsNamedNotGuessed()
	{
		// 'UTF-16' without a byte-order mark is big-endian by the Unicode default, and
		// that is what encodeCodedString() writes -- but iconv's bare 'UTF-16' resolves
		// the default per platform (libiconv big-endian, glibc little-endian), so an
		// unmarked comment must decode the same way everywhere.
		$text = 'Ünïcode ✓';
		$be = new TIFFTag(37510, TIFFDataType::Undefined, "UNICODE\0" . mb_convert_encoding($text, 'UTF-16BE', 'UTF-8'));
		self::assertSame($text, EXIFTags::textValue($be, EXIFTags::EXIF));

		// A writer that left a mark is believed, in either order.
		$markedBe = new TIFFTag(37510, TIFFDataType::Undefined, "UNICODE\0" . "\xFE\xFF" . mb_convert_encoding($text, 'UTF-16BE', 'UTF-8'));
		self::assertSame($text, EXIFTags::textValue($markedBe, EXIFTags::EXIF));

		$markedLe = new TIFFTag(37510, TIFFDataType::Undefined, "UNICODE\0" . "\xFF\xFE" . mb_convert_encoding($text, 'UTF-16LE', 'UTF-8'));
		self::assertSame($text, EXIFTags::textValue($markedLe, EXIFTags::EXIF));

		// The round trip through this library's own writer holds.
		self::assertSame($text, EXIFTags::decodeCodedString(EXIFTags::encodeCodedString($text, 'UNICODE')));
	}

	public function testComponentsConfiguration()
	{
		$tag = new TIFFTag(37121, TIFFDataType::Undefined, "\x01\x02\x03\x00");
		self::assertSame('Y Cb Cr -', EXIFTags::textValue($tag, EXIFTags::EXIF));

		$rgb = new TIFFTag(37121, TIFFDataType::Undefined, "\x04\x05\x06\x00");
		self::assertSame('R G B -', EXIFTags::textValue($rgb, EXIFTags::EXIF));
	}

	public function testYCbCrSubSampling()
	{
		$tag = new TIFFTag(530, TIFFDataType::UShort, [2, 2]);
		self::assertSame('YCbCr 4:2:0', EXIFTags::textValue($tag, EXIFTags::TIFF));
		$tag = new TIFFTag(530, TIFFDataType::UShort, [2, 1]);
		self::assertSame('YCbCr 4:2:2', EXIFTags::textValue($tag, EXIFTags::TIFF));
		$odd = new TIFFTag(530, TIFFDataType::UShort, [3, 3]);
		self::assertSame('3,3', EXIFTags::textValue($odd, EXIFTags::TIFF));
	}

	public function testCfaPattern()
	{
		// 2x2 RGGB grid: columns(2) rows(2) then the color indices.
		$tag = new TIFFTag(41730, TIFFDataType::Undefined, "\x00\x02\x00\x02\x00\x01\x01\x02");
		self::assertSame('RG / GB', EXIFTags::textValue($tag, EXIFTags::EXIF));

		$short = new TIFFTag(41730, TIFFDataType::Undefined, "\x00");
		self::assertNull(EXIFTags::textValue($short, EXIFTags::EXIF));
	}

	public function testNumericFormsAndUnits()
	{
		// A reciprocal rational renders as a fraction with units.
		$exposure = new TIFFTag(33434, TIFFDataType::URational, [[1, 60]]);
		self::assertSame('1/60 seconds', EXIFTags::textValue($exposure, EXIFTags::EXIF));

		// A plain quotient renders decimal (FNumber has no units).
		$fnumber = new TIFFTag(33437, TIFFDataType::URational, [[71, 10]]);
		self::assertSame('7.1', EXIFTags::textValue($fnumber, EXIFTags::EXIF));

		// Division by zero keeps the raw fraction.
		$broken = new TIFFTag(33437, TIFFDataType::URational, [[5, 0]]);
		self::assertSame('5/0', EXIFTags::textValue($broken, EXIFTags::EXIF));

		// Multi-value integers join.
		$bits = new TIFFTag(258, TIFFDataType::UShort, [8, 8, 8]);
		self::assertStringContainsString('8, 8, 8', (string) EXIFTags::textValue($bits, EXIFTags::TIFF));
	}

	public function testLookupUnknownValueAndStringTrim()
	{
		$orientation = new TIFFTag(274, TIFFDataType::UShort, [99]);
		self::assertSame('Unknown (99)', EXIFTags::textValue($orientation, EXIFTags::TIFF));

		$make = new TIFFTag(271, TIFFDataType::Ascii, "  PradoCam \0\0");
		self::assertSame('PradoCam', EXIFTags::textValue($make, EXIFTags::TIFF));
	}

	public function testGpsTimestampAndAltitudeStyleValues()
	{
		$time = new TIFFTag(7, TIFFDataType::URational, [[14, 1], [30, 1], [5, 1]]);
		self::assertSame('14:30:05', EXIFTags::textValue($time, EXIFTags::GPS));

		// A non-composite GPS rational falls back to numeric text with its units.
		$altitude = new TIFFTag(6, TIFFDataType::URational, [[125, 10]]);
		self::assertSame('12.5 Metres with respect to Altitude Reference', EXIFTags::textValue($altitude, EXIFTags::GPS));
	}

	public function testPointerTagsHaveNoText()
	{
		$pointer = new TIFFTag(34665, TIFFDataType::ULong, [1234]);
		self::assertNull(EXIFTags::textValue($pointer, EXIFTags::TIFF));
		$makernote = new TIFFTag(37500, TIFFDataType::Undefined, 'opaque');
		self::assertNull(EXIFTags::textValue($makernote, EXIFTags::EXIF));
	}

	public function testCodedStringJisAndTruncatedUnicode()
	{
		// The JIS (ISO-2022-JP) charset of the coded-string form round trips.
		$coded = EXIFTags::encodeCodedString('日本語', 'JIS');
		self::assertStringStartsWith("JIS\0\0\0\0\0", $coded);
		self::assertSame("\x1B\x24\x42", substr($coded, 8, 3));   // the JIS X 0208 escape
		self::assertSame('日本語', EXIFTags::decodeCodedString($coded));

		$tag = new TIFFTag(37510, TIFFDataType::Undefined, $coded);
		self::assertSame('日本語', EXIFTags::textValue($tag, EXIFTags::EXIF));

		// A UNICODE payload truncated mid-character decodes to nothing rather than
		// raising: neither the BOM-sensing nor the big-endian pass can convert it.
		$odd = new TIFFTag(37510, TIFFDataType::Undefined, "UNICODE\0" . "\x00A\x00B\x00");
		self::assertSame('', EXIFTags::textValue($odd, EXIFTags::EXIF));
	}

	public function testNumericTextOfByteStringValues()
	{
		// A numeric tag a camera wrote as Undefined bytes renders its byte values.
		$width = new TIFFTag(256, TIFFDataType::Undefined, "\x01\x02\xFF");
		self::assertSame('1 2 255 pixels', EXIFTags::textValue($width, EXIFTags::TIFF));

		$empty = new TIFFTag(256, TIFFDataType::Undefined, '');
		self::assertSame(' pixels', EXIFTags::textValue($empty, EXIFTags::TIFF));
	}

	public function testCfaPatternGridShorterThanItsDimensions()
	{
		// 4x4 declared but only one colour byte present: no partial grid is invented.
		$tag = new TIFFTag(41730, TIFFDataType::Undefined, "\x00\x04\x00\x04\x00");
		self::assertNull(EXIFTags::textValue($tag, EXIFTags::EXIF));
	}

	public function testJpegInterchangeFormatHasNoNumericText()
	{
		// The IFD1 thumbnail pointer names its payload instead of printing an offset.
		$tag = new TIFFTag(513, TIFFDataType::ULong, [1024]);
		self::assertSame('JPEG thumbnail data', EXIFTags::textValue($tag, EXIFTags::TIFF));
	}

	public function testGpsCompositeFallbacks()
	{
		// A coordinate with a zero-denominator second falls back to the raw fractions.
		$broken = new TIFFTag(2, TIFFDataType::URational, [[34, 1], [3, 1], [30, 0]]);
		self::assertSame('34, 3, 30/0 (Degrees Minutes Seconds North or South)', EXIFTags::textValue($broken, EXIFTags::GPS));

		// A three-rational GPS tag that is not a coordinate or the timestamp keeps its
		// numeric rendering (GPSDestLatitude has no composite decoder).
		$dest = new TIFFTag(20, TIFFDataType::URational, [[10, 1], [20, 1], [30, 1]]);
		self::assertSame('10, 20, 30 (Degrees Minutes Seconds North or South)', EXIFTags::textValue($dest, EXIFTags::GPS));
	}

	public function testSpecialDecodersWithTheOtherValueShape()
	{
		// The same four tags a camera may write either as Undefined bytes or as a
		// numeric array; each decoder reads the shape it is given.
		$components = new TIFFTag(37121, TIFFDataType::UByte, [1, 2, 3, 0]);
		self::assertSame('Y Cb Cr -', EXIFTags::textValue($components, EXIFTags::EXIF));

		$cfa = new TIFFTag(41730, TIFFDataType::UByte, [0, 2, 0, 2, 0, 1, 1, 2]);
		self::assertSame('RG / GB', EXIFTags::textValue($cfa, EXIFTags::EXIF));

		// The subsampling pair is a numeric pair by definition: bytes carry no pair.
		$subSampling = new TIFFTag(530, TIFFDataType::Undefined, "\x00\x02\x00\x01");
		self::assertSame('', EXIFTags::textValue($subSampling, EXIFTags::TIFF));

		// And the learning block is a byte string: an array form carries no sets.
		$learning = new TIFFTag(37511, TIFFDataType::UByte, [0, 1, 0, 0, 0, 2]);
		self::assertNull(EXIFTags::textValue($learning, EXIFTags::EXIF));
	}

	public function testStringTagWrittenWithANumericType()
	{
		// A text tag a camera wrote with a numeric type still renders: the values join
		// rather than being dropped for having the wrong shape.
		$make = new TIFFTag(271, TIFFDataType::UShort, [12, 34]);
		self::assertSame('1234', EXIFTags::textValue($make, EXIFTags::TIFF));
	}

	public function testSignedRationalCoordinate()
	{
		// A coordinate written with the signed rational type decodes the same way the
		// spec's unsigned form does.
		$signed = new TIFFTag(2, TIFFDataType::SRational, [[34, 1], [3, 1], [3000, 100]]);
		self::assertSame('34° 3\' 30"', EXIFTags::textValue($signed, EXIFTags::GPS));

		// A signed rational that is not three long has no composite form.
		$pair = new TIFFTag(2, TIFFDataType::SRational, [[34, 1], [3, 1]]);
		self::assertStringNotContainsString('°', (string) EXIFTags::textValue($pair, EXIFTags::GPS));

		// Nor has a GPS tag that is no rational at all: GPSVersionID is four bytes.
		$version = new TIFFTag(0, TIFFDataType::UByte, [2, 3, 0, 0]);
		self::assertStringStartsWith('2, 3, 0, 0', (string) EXIFTags::textValue($version, EXIFTags::GPS));
	}

	public function testCodedStringThatCannotBeConverted()
	{
		// Text ending mid-character cannot be converted at all (iconv answers false
		// whether or not it is asked to ignore): the charset marker is written with an
		// empty payload rather than raising.
		self::assertSame("UNICODE\0", EXIFTags::encodeCodedString("abc\xE2", 'UNICODE'));
		self::assertSame("JIS\0\0\0\0\0", EXIFTags::encodeCodedString("abc\xE2", 'JIS'));

		// And a JIS payload that stops inside an escape sequence passes through as the
		// stored bytes instead of decoding to nothing.
		$coded = "JIS\0\0\0\0\0" . "\x1B\x24";
		self::assertSame("\x1B\x24", EXIFTags::decodeCodedString($coded));
		$tag = new TIFFTag(37510, TIFFDataType::Undefined, $coded);
		self::assertSame("\x1B\x24", EXIFTags::textValue($tag, EXIFTags::EXIF));
	}

	public function testFindByNameGroupScoping()
	{
		self::assertSame([EXIFTags::GPS, 2], EXIFTags::findByName('GPSLatitude', EXIFTags::GPS));
		self::assertNull(EXIFTags::findByName('GPSLatitude', EXIFTags::EXIF));
		self::assertSame('ExposureTime', EXIFTags::nameOf(EXIFTags::EXIF, 33434));
	}
}
