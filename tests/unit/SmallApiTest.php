<?php

use Belisoful\Image\Compression\CCITTFaxCompressor;
use Belisoful\Image\Meta\EXIF;
use Belisoful\Image\Meta\PhotoshopFileInfo;
use Belisoful\Image\Meta\IPTC;
use Belisoful\Image\Meta\PrintIM;
use Belisoful\Image\Meta\XMP;
use Belisoful\Image\ImageGraphicsMode;
use Belisoful\Image\TIFF\TIFFDataType;
use Belisoful\Image\TIFF\TIFFDocument;
use Belisoful\Image\TIFF\TIFFIfd;
use Belisoful\Image\ImageChunk;

class SmallApiTest extends PHPUnit\Framework\TestCase
{
	public function testCcittStaticInterfaceWrappers()
	{
		$rowBytes = 8;
		$data = str_repeat("\xF0", $rowBytes) . str_repeat("\x0F", $rowBytes);
		$encoded = CCITTFaxCompressor::compress($data, 64, CCITTFaxCompressor::Group4);
		self::assertSame(bin2hex($data), bin2hex(CCITTFaxCompressor::decompress($encoded, 64, CCITTFaxCompressor::Group4)));
		self::assertSame(bin2hex(substr($data, 0, $rowBytes)), bin2hex(CCITTFaxCompressor::decompress($encoded, 64, CCITTFaxCompressor::Group4, 1)));

		$codec = new CCITTFaxCompressor(64, CCITTFaxCompressor::Group3);
		self::assertSame(64, $codec->getColumns());
		self::assertSame(CCITTFaxCompressor::Group3, $codec->getMode());
		self::assertSame(8, $codec->getRowBytes());
	}

	public function testTiffDocumentAndIfdCollectionApi()
	{
		$tiff = new TIFFDocument();
		$ifd0 = new TIFFIfd();
		$ifd0->setTagValues(271, TIFFDataType::Ascii, "A\0");
		$ifd1 = new TIFFIfd();
		$ifd1->setTagValues(305, TIFFDataType::Ascii, "B\0");
		$tiff->addIfd($ifd0);
		$tiff->addIfd($ifd1);

		self::assertSame($ifd1, $tiff->removeIfd(1));
		self::assertNull($tiff->removeIfd(5));
		self::assertCount(1, $tiff->getIfds());

		self::assertSame(1, count($ifd0));
		$tags = [];
		foreach ($ifd0 as $id => $tag) {
			$tags[$id] = $tag->getValue();
		}
		self::assertSame([271 => 'A'], $tags);
	}

	public function testImageChunkAccessors()
	{
		$chunk = new ImageChunk('IDAT', 5, 1234, 'bytes');
		self::assertSame('IDAT', $chunk->getType());
		self::assertSame(5, $chunk->getSize());
		self::assertSame(1234, $chunk->getOffset());
		self::assertSame('bytes', $chunk->getData());
	}

	public function testXmpDomAndBinaryAccessors()
	{
		$xmp = XMP::blank();
		$xmp->setTitle('Dom');
		self::assertInstanceOf(DOMDocument::class, $xmp->getDom());
		self::assertSame($xmp->toPacketText(), $xmp->toBinary());
		self::assertStringContainsString('Dom', $xmp->toBinary());
	}

	public function testFileInfoFieldsDump()
	{
		$info = new PhotoshopFileInfo();
		$info['title'] = 'T';
		$info['keywords'] = ['k'];
		self::assertSame(['title' => 'T', 'keywords' => ['k']], $info->getFields());
	}

	public function testIptcCommonMetadataBridges()
	{
		$iptc = new IPTC();
		self::assertFalse($iptc->hasXMP());
		self::assertNull($iptc->getXMP());
		self::assertFalse($iptc->setXMP('ignored'));
	}

	public function testExifSignatureAndInteropCreation()
	{
		$exif = new EXIF();
		self::assertSame(EXIF::ExifSignature, $exif->getSignature());
		$exif->setSignature(EXIF::MetaSignature);
		self::assertTrue($exif->getIsMeta());
		$exif->setSignature('');
		self::assertSame('', $exif->getSignature());

		self::assertNull($exif->getInteropIfd());
		$interop = $exif->getInteropIfd(true);
		self::assertNotNull($interop);
		$interop->setTagValues(1, TIFFDataType::Ascii, "R98\0");
		$reparsed = EXIF::fromSegment(EXIF::ExifSignature . $exif->toBinary());
		self::assertSame('R98', $reparsed->getInteropIfd()->getTagValue(1));

		self::expectException(\InvalidArgumentException::class);
		$exif->setSignature('BOGUS');
	}

	public function testGraphicsLibraryEnumeration()
	{
		self::assertSame('GD', ImageGraphicsMode::GD);
		self::assertSame('Imagick', ImageGraphicsMode::Imagick);
	}

	public function testPimVersionAndTruncation()
	{
		$pim = new PrintIM();
		$pim->setVersion('0250');
		self::assertSame('0250', $pim->getVersion());

		// A truncated block (signature only) parses to an empty, versioned PIM.
		$truncated = PrintIM::parse(PrintIM::Signature . '0300');
		self::assertNotFalse($truncated);
		self::assertSame([], $truncated->getEntries());
	}

	public function testCompressorEngineFactories()
	{
		self::assertInstanceOf(Belisoful\Image\Compression\LZWEncoder::class, Belisoful\Image\Compression\LZWCompressor::encoder());
		self::assertInstanceOf(Belisoful\Image\Compression\LZWDecoder::class, Belisoful\Image\Compression\LZWCompressor::decoder());
		self::assertInstanceOf(Belisoful\Image\Compression\PackBitsEncoder::class, Belisoful\Image\Compression\PackBitsCompressor::encoder());
		self::assertInstanceOf(Belisoful\Image\Compression\PackBitsDecoder::class, Belisoful\Image\Compression\PackBitsCompressor::decoder());
		self::assertInstanceOf(Belisoful\Image\Compression\StreamCodec::class, Belisoful\Image\Compression\HorizontalPredictor::encoder(8));
	}

	public function testExifTiffFileAndXmpBridge()
	{
		$exif = new EXIF();
		$exif->setValueByName('Make', 'FileCam');
		$xmp = XMP::blank();
		$xmp->setTitle('Embedded XMP');
		$exif->setXMP($xmp);

		$path = tempnam(sys_get_temp_dir(), 'exiftiff');
		try {
			file_put_contents($path, $exif->getTiff()->toBinary());
			$fromFile = EXIF::fromTiffFile($path);
			self::assertSame('FileCam', $fromFile->getMake());
			self::assertNotNull($fromFile->getXMP());
			self::assertSame('Embedded XMP', $fromFile->getXMP()->getTitle());

			$fromFile->setXMP(null);
			self::assertNull($fromFile->getXmpText());
		} finally {
			@unlink($path);
		}

		self::expectException(\RuntimeException::class);
		EXIF::fromTiffFile('/nonexistent/nope.tif');
	}

	public function testTtiffExposesUnderlyingDocument()
	{
		$exif = new EXIF();
		$exif->getIfd0()->setTagValues(Belisoful\Image\TIFFImage::WidthTag, TIFFDataType::ULong, [5]);
		$exif->getIfd0()->setTagValues(Belisoful\Image\TIFFImage::HeightTag, TIFFDataType::ULong, [5]);
		$tiff = Belisoful\Image\TIFFImage::fromString($exif->getTiff()->toBinary());
		self::assertInstanceOf(TIFFDocument::class, $tiff->getTiff());
		self::assertSame(5, $tiff->getTiff()->getIfd(0)->getTagValue(256));
	}

	public function testGraphicsClosestPaletteIndex()
	{
		// The over-budget color mapping of the Imagick quantizer; pure arithmetic, so it
		// runs without the extension.
		$exposed = new class () extends Belisoful\Image\ImageGraphicsImagick {
			public function closest(string $palette, int $r, int $g, int $b): int
			{
				return $this->closestPaletteIndex($palette, $r, $g, $b);
			}
		};
		$palette = "\xFF\x00\x00" . "\x00\xFF\x00" . "\x00\x00\xFF";
		self::assertSame(0, $exposed->closest($palette, 250, 10, 10));
		self::assertSame(1, $exposed->closest($palette, 10, 250, 10));
		self::assertSame(2, $exposed->closest($palette, 40, 40, 220));
	}

	public function testTiffWriterWidensShortByteCounts()
	{
		// A UShort StripByteCounts must widen to ULong when a strip outgrows 65535.
		$strip = str_repeat("\xCD", 70000);
		$tiff = new TIFFDocument();
		$ifd = new TIFFIfd();
		$offsets = $ifd->setTagValues(273, TIFFDataType::ULong, [0]);
		$ifd->setTagValues(279, TIFFDataType::UShort, [0]);
		$offsets->setExternalData([$strip]);
		$tiff->addIfd($ifd);

		$reparsed = TIFFDocument::fromString($tiff->toBinary());
		$counts = $reparsed->getIfd(0)->getTag(279);
		self::assertSame(TIFFDataType::ULong, $counts->getType());
		self::assertSame([70000], $counts->getValues());
		self::assertSame([$strip], $reparsed->getIfd(0)->getTag(273)->getExternalData());
	}

	public function testTiffWriterIgnoresInvalidPin()
	{
		// A pin below the 8-byte header cannot be honored and is placed normally.
		$tiff = new TIFFDocument();
		$ifd = new TIFFIfd();
		$tag = $ifd->setTagValues(37500, TIFFDataType::Undefined, str_repeat("\xEE", 32));
		$tag->setOffset(2);
		$tag->setPreserveOffset(true);
		$tiff->addIfd($ifd);

		$reparsed = TIFFDocument::fromString($tiff->toBinary());
		self::assertSame(str_repeat("\xEE", 32), $reparsed->getIfd(0)->getTag(37500)->getValues());
		self::assertGreaterThanOrEqual(8, $reparsed->getIfd(0)->getTag(37500)->getOffset());
	}
}
