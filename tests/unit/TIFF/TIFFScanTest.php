<?php

use Belisoful\Image\Meta\EXIF;
use Belisoful\Image\TIFF\TIFFDataType;
use Belisoful\Image\TIFF\TIFFDocument;
use Belisoful\Image\TIFF\TIFFIfd;
use Belisoful\Image\TIFFImage;

class TIFFScanTest extends PHPUnit\Framework\TestCase
{
	/**
	 * Builds a TIFF file: EXIF-style metadata, an IFD1 JPEG thumbnail, and a large
	 * pixel strip the scanner must never read.
	 * @param int $stripSize
	 */
	private function bigTiffBytes(int $stripSize = 4194304): string
	{
		$exif = new EXIF();
		$exif->setValueByName('Make', 'PradoCam');
		$exif->setValueByName('Model', 'Scanner-1');
		$exif->getExifIfd(true)->setTagValues(33434, TIFFDataType::URational, [[1, 500]]);
		$exif->getExifIfd(true)->setTagValues(EXIF::MakerNoteTag, TIFFDataType::Undefined, 'NOTE' . str_repeat("\xA5", 28));
		$exif->setThumbnail("\xFF\xD8THUMBNAIL-JPEG-BYTES\xFF\xD9");

		$ifd0 = $exif->getIfd0();
		$ifd0->setTagValues(TIFFImage::WidthTag, TIFFDataType::ULong, [4000]);
		$ifd0->setTagValues(TIFFImage::HeightTag, TIFFDataType::ULong, [3000]);
		$offsets = $ifd0->setTagValues(273, TIFFDataType::ULong, [0]);
		$ifd0->setTagValues(279, TIFFDataType::ULong, [$stripSize]);
		$offsets->setExternalData([str_repeat("\x5A", $stripSize)]);

		$exif->setSignature('');
		return $exif->toBinary();
	}

	public function testScanFileReadsMetadataWithoutStrips()
	{
		$path = tempnam(sys_get_temp_dir(), 'tiffscan');
		try {
			file_put_contents($path, $this->bigTiffBytes());

			$before = memory_get_usage();
			$exif = EXIF::scanFile($path);
			$grown = memory_get_usage() - $before;

			self::assertTrue($exif->getTiff()->getIsScanned());
			self::assertSame('PradoCam', $exif->getMake());
			self::assertSame('Scanner-1', $exif->getModel());
			self::assertSame([[1, 500]], $exif->getExifIfd()->getTag(33434)->getValues());
			self::assertSame("\xFF\xD8THUMBNAIL-JPEG-BYTES\xFF\xD9", $exif->getThumbnail());

			// The strip offsets tag survives as metadata, but its 4 MB of pixel data
			// was never read: no captured blocks, and far less than 4 MB allocated.
			$stripTag = $exif->getIfd0()->getTag(273);
			self::assertNotNull($stripTag);
			self::assertNull($stripTag->getExternalData());
			self::assertLessThan(1048576, $grown);

			// The makernote pin still applies on the scanned form.
			self::assertTrue($exif->getExifIfd()->getTag(EXIF::MakerNoteTag)->getPreserveOffset());
		} finally {
			@unlink($path);
		}
	}

	public function testScanStreamFromResourceAndOffsetBase()
	{
		$tiff = $this->bigTiffBytes(65536);

		// Raw PHP resource in.
		$resource = fopen('php://memory', 'w+b');
		fwrite($resource, $tiff);
		rewind($resource);
		$scanned = TIFFDocument::scanStream($resource);
		self::assertSame('PradoCam', $scanned->getIfd(0)->getTagValue(271));
		fclose($resource);

		// A TIFF embedded at a nonzero position scans from the current position.
		$stream = new TestPsr7Stream('PREFIX-JUNK!' . $tiff);
		$stream->seek(12);
		$offsetScan = TIFFDocument::scanStream($stream);
		self::assertSame('Scanner-1', $offsetScan->getIfd(0)->getTagValue(272));
		self::assertSame([], array_filter($offsetScan->getWarnings(), fn ($w) => !str_contains($w, 'scan cap')));
	}

	public function testScanFileRejectsAnUnreadablePath()
	{
		self::expectException(\RuntimeException::class);
		EXIF::scanFile('/no-such-directory-for-php-image/missing.tif');
	}

	public function testScanRequiresSeekableStream()
	{
		$stream = new TestNonSeekableStream(($this->bigTiffBytes(1024)));
		try {
			TIFFDocument::scanStream($stream);
			self::fail('scanStream accepted a non-seekable stream');
		} catch (\RuntimeException $e) {
			self::assertInstanceOf(\RuntimeException::class, $e);
		}
	}

	public function testScanTagSizeCapSkipsOversizedValues()
	{
		$exif = new EXIF();
		$exif->setValueByName('Make', 'CapCam');
		$exif->getIfd0()->setTagValues(700, TIFFDataType::UByte, array_fill(0, 8192, 65));   // 8 KB XMP
		$exif->setSignature('');
		$stream = TestIOHelper::dataResource($exif->toBinary());

		$scanned = TIFFDocument::scanStream($stream, null, 1024);
		self::assertSame('CapCam', $scanned->getIfd(0)->getTagValue(271));
		self::assertNull($scanned->getIfd(0)->getTag(700));
		self::assertNotEmpty(array_filter($scanned->getWarnings(), fn ($w) => str_contains($w, 'scan cap')));
	}

	public function testScanSkipsATagWhoseValueAreaRunsPastTheData()
	{
		// A tag whose value is larger than four bytes lives at an offset elsewhere in the
		// file.  When that offset points past the end, the read fails and the scan drops
		// the tag with a warning rather than aborting the whole directory.
		$exif = new EXIF();
		$exif->setValueByName('Make', 'TruncCam');
		$exif->setValueByName('Model', 'ModelThatIsLongerThanFourBytes');
		$exif->setSignature('');
		$bytes = $exif->toBinary();

		// Repoint IFD0's Model tag at an offset well past the end of the file.
		$entry = strpos($bytes, pack('n', 272));
		self::assertNotFalse($entry, 'the Model tag entry is present');
		$bytes = substr_replace($bytes, pack('N', strlen($bytes) + 0x1000), $entry + 8, 4);

		$scanned = TIFFDocument::scanStream(TestIOHelper::dataResource($bytes));
		self::assertSame('TruncCam', $scanned->getIfd(0)->getTagValue(271), 'the sound tag still reads');
		self::assertNull($scanned->getIfd(0)->getTag(272), 'the unreadable tag is dropped');
		self::assertNotEmpty(
			array_filter($scanned->getWarnings(), fn ($w) => str_contains($w, 'runs past the data')),
			'the scan warns about the value area',
		);
	}

	public function testScanSkipsATagWhoseValueAreaRefusesToSeekThePsr7Way()
	{
		// The same failure, but raised by the stream's own seek rather than by the scan's
		// bounds check, so the tolerance is reached from the other direction.
		$exif = new EXIF();
		$exif->setValueByName('Make', 'BoundCam');
		$exif->setValueByName('Model', 'ModelThatIsLongerThanFourBytes');
		$exif->setSignature('');
		$bytes = $exif->toBinary();
		$entry = strpos($bytes, pack('n', 272));
		self::assertNotFalse($entry, 'the Model tag entry is present');
		$bytes = substr_replace($bytes, pack('N', strlen($bytes) + 0x1000), $entry + 8, 4);

		$stream = new TestBoundedSeekStream(($bytes));
		$scanned = TIFFDocument::scanStream($stream);

		self::assertSame(1, $stream->refusals, 'the stream refused the out-of-range seek');
		self::assertSame('BoundCam', $scanned->getIfd(0)->getTagValue(271), 'the sound tag still reads');
		self::assertNull($scanned->getIfd(0)->getTag(272), 'the unreadable tag is dropped');
		self::assertNotEmpty(
			array_filter($scanned->getWarnings(), fn ($w) => str_contains($w, 'runs past the data')),
			'the scan warns about the value area',
		);
	}

	public function testScannedExifComposesMetadataOnlySegment()
	{
		$path = tempnam(sys_get_temp_dir(), 'tiffscan');
		try {
			file_put_contents($path, $this->bigTiffBytes(131072));
			$exif = EXIF::scanFile($path);
			$exif->setValueByName('Artist', 'From A Scan');

			$reparsed = EXIF::fromSegment($exif->toSegment());
			self::assertSame('PradoCam', $reparsed->getMake());
			self::assertSame('From A Scan', $reparsed->getValueByName('Artist'));
			self::assertSame("\xFF\xD8THUMBNAIL-JPEG-BYTES\xFF\xD9", $reparsed->getThumbnail());
		} finally {
			@unlink($path);
		}
	}

	public function testScanMatchesFullParse()
	{
		// The scanned metadata equals the in-memory parse of the same file, tag for tag.
		$bytes = $this->bigTiffBytes(32768);
		$full = TIFFDocument::fromString($bytes);
		$scanned = TIFFDocument::scanStream(TestIOHelper::dataResource($bytes));

		foreach ($full->getIfds() as $index => $ifd) {
			$scannedIfd = $scanned->getIfd($index);
			self::assertNotNull($scannedIfd);
			self::assertSame(array_keys($ifd->getTags()), array_keys($scannedIfd->getTags()));
			foreach ($ifd->getTags() as $id => $tag) {
				$other = $scannedIfd->getTag($id);
				self::assertSame($tag->getType(), $other->getType(), "tag $id type");
				self::assertSame($tag->getValues(), $other->getValues(), "tag $id values");
			}
		}
		$fullExif = $full->getIfd(0)->getTag(TIFFDocument::ExifIfdTag)->getSubIfd();
		$scanExif = $scanned->getIfd(0)->getTag(TIFFDocument::ExifIfdTag)->getSubIfd();
		self::assertSame(array_keys($fullExif->getTags()), array_keys($scanExif->getTags()));
	}

	public function testScanRejectsANonStreamSource()
	{
		foreach (['MM\x00\x2A', 42, null] as $source) {
			try {
				TIFFDocument::scanStream($source);
				self::fail('scanStream accepted ' . get_debug_type($source));
			} catch (\InvalidArgumentException $e) {
				self::assertInstanceOf(\InvalidArgumentException::class, $e);
			}
		}
	}

	public function testScanReadsLittleEndian()
	{
		$doc = new TIFFDocument();
		$doc->setIsBigEndian(false);
		$ifd = new TIFFIfd();
		$ifd->setTagValues(271, TIFFDataType::Ascii, "LittleCam\0");
		$ifd->setTagValues(256, TIFFDataType::ULong, [1024]);
		$doc->addIfd($ifd);
		$bytes = $doc->toBinary();
		self::assertSame('II', substr($bytes, 0, 2));

		$scanned = TIFFDocument::scanStream(TestIOHelper::dataResource($bytes));
		self::assertFalse($scanned->getIsBigEndian());
		self::assertSame('LittleCam', $scanned->getIfd(0)->getTagValue(271));
		self::assertSame(1024, $scanned->getIfd(0)->getTagValue(256));
		self::assertSame([], $scanned->getWarnings());
	}

	public function testScanMalformedHeadersThrow()
	{
		$cases = [
			'',                                    // nothing at all
			'M',                                   // half a byte-order mark
			"XX\x00\x2A\x00\x00\x00\x08",          // no MM/II
			"MM\x00\x2B\x00\x00\x00\x08",          // wrong magic number
			"MM\x00\x2A",                          // no first-IFD pointer
		];
		foreach ($cases as $bad) {
			try {
				TIFFDocument::scanStream(TestIOHelper::dataResource($bad));
				self::fail('scanStream accepted ' . bin2hex($bad));
			} catch (\RuntimeException $e) {
				self::assertInstanceOf(\UnexpectedValueException::class, $e);
			}
		}
	}

	public function testScanWarnsOnALoopingIfdChain()
	{
		// IFD0 at 8 chains to IFD1 at 26, whose next pointer loops back to IFD0.
		$bytes = "MM\x00\x2A\x00\x00\x00\x08"
			. "\x00\x01" . "\x01\x0F\x00\x02\x00\x00\x00\x04" . "abc\0" . "\x00\x00\x00\x1A"
			. "\x00\x01" . "\x01\x10\x00\x02\x00\x00\x00\x04" . "def\0" . "\x00\x00\x00\x08";

		$scanned = TIFFDocument::scanStream(TestIOHelper::dataResource($bytes));
		self::assertCount(2, $scanned->getIfds());
		self::assertSame('abc', $scanned->getIfd(0)->getTagValue(271));
		self::assertSame('def', $scanned->getIfd(1)->getTagValue(272));
		self::assertNotEmpty(array_filter($scanned->getWarnings(), fn ($w) => str_contains($w, 'loops back to offset 8')));
	}

	public function testScanWarnsOnAnIfdOutsideTheData()
	{
		// A header whose first-IFD pointer addresses the very end of the stream.
		$scanned = TIFFDocument::scanStream(TestIOHelper::dataResource("MM\x00\x2A\x00\x00\x00\x08"));
		self::assertSame([], $scanned->getIfds());
		self::assertSame(['IFD offset 8 is outside the data'], $scanned->getWarnings());
	}

	public function testScanToleratesPsr7SeekFailures()
	{
		// A stream that raises the PSR-7 \RuntimeException from its own seek is tolerated
		// the same way: the scan warns and carries on with what it can read.
		$stream = new TestBoundedSeekStream(("MM\x00\x2A\x00\x00\x00\x40"));
		$scanned = TIFFDocument::scanStream($stream);
		self::assertSame(1, $stream->refusals);
		self::assertSame([], $scanned->getIfds());
		self::assertSame(['IFD offset 64 is outside the data'], $scanned->getWarnings());

		// The same for a tag whose value area lies past the end of the stream.
		$bytes = "MM\x00\x2A\x00\x00\x00\x08"
			. "\x00\x02"
			. "\x01\x0F\x00\x02\x00\x00\x00\x20\x00\x00\xFF\x00"
			. "\x01\x10\x00\x02\x00\x00\x00\x04" . "def\0"
			. "\x00\x00\x00\x00";

		$stream = new TestBoundedSeekStream(($bytes));
		$scanned = TIFFDocument::scanStream($stream);
		self::assertSame(1, $stream->refusals);
		self::assertNull($scanned->getIfd(0)->getTag(271));
		self::assertSame('def', $scanned->getIfd(0)->getTagValue(272));
		self::assertSame(['tag 271 value at 65280 runs past the data'], $scanned->getWarnings());
	}

	public function testScanSkipsAnUnknownDataType()
	{
		$bytes = "MM\x00\x2A\x00\x00\x00\x08"
			. "\x00\x02"
			. "\x01\x0F\x00\x63\x00\x00\x00\x04\x00\x00\x00\x00"    // tag 271, data type 99
			. "\x01\x10\x00\x02\x00\x00\x00\x04" . "def\0"
			. "\x00\x00\x00\x00";

		$scanned = TIFFDocument::scanStream(TestIOHelper::dataResource($bytes));
		self::assertNull($scanned->getIfd(0)->getTag(271));
		self::assertSame('def', $scanned->getIfd(0)->getTagValue(272));
		self::assertSame(['tag 271 has unknown data type 99'], $scanned->getWarnings());
	}

	public function testScanSkipsAValueAreaPastTheEnd()
	{
		// A 32-byte Ascii value declared at offset 0xFF00, far beyond the stream.
		$bytes = "MM\x00\x2A\x00\x00\x00\x08"
			. "\x00\x02"
			. "\x01\x0F\x00\x02\x00\x00\x00\x20\x00\x00\xFF\x00"
			. "\x01\x10\x00\x02\x00\x00\x00\x04" . "def\0"
			. "\x00\x00\x00\x00";

		$scanned = TIFFDocument::scanStream(TestIOHelper::dataResource($bytes));
		self::assertNull($scanned->getIfd(0)->getTag(271));
		self::assertSame('def', $scanned->getIfd(0)->getTagValue(272));
		self::assertSame(['tag 271 value at 65280 runs past the data'], $scanned->getWarnings());
	}
}
