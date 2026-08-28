<?php

use Belisoful\Image\Meta\JUMBF\JUMBFBox;
use Belisoful\Image\Meta\EXIF;
use Belisoful\Image\Meta\IPTCTags;
use Belisoful\Image\Meta\JFIF;
use Belisoful\Image\Meta\JFXX;
use Belisoful\Image\Meta\PictureInfo;
use Belisoful\Image\Meta\XMP;
use Belisoful\Image\TIFF\TIFFDataType;
use Belisoful\Image\JPEGImage;
use Belisoful\Image\Photoshop8BIM;

/**
 * Segment-level tests for the {@see JPEGImage} marker walk and the compose side: malformed
 * and unusual marker layouts, the multi-segment carriers (extended XMP, JUMBF, the
 * legacy Photoshop IPTC block), and the segment kinds whose metadata was dropped or
 * newly injected between the parse and the rewrite.
 */
class JPEGSegmentsTest extends PHPUnit\Framework\TestCase
{
	/** @var string A start-of-frame: 8-bit precision, height 16, width 24, one component. */
	private const SOF = "\xFF\xC0\x00\x0B\x08\x00\x10\x00\x18\x01\x01\x11\x00";

	/** @var string A start-of-scan header for the one component. */
	private const SOS = "\xFF\xDA\x00\x08\x01\x01\x00\x00\x3F\x00";

	/** Builds a minimal 24x16 JPEG, optionally with segments injected after the SOI. */
	private function minimalJpeg(string $segments = ''): string
	{
		return "\xFF\xD8" . $segments . self::SOF . self::SOS . 'scandata' . "\xFF\xD9";
	}

	/** Builds one marker segment (marker, length, payload). */
	private function segment(int $marker, string $payload): string
	{
		return chr(JPEGImage::MARKER_PREFIX) . chr($marker) . pack('n', strlen($payload) + 2) . $payload;
	}

	private function gdImage(int $w, int $h)
	{
		$im = imagecreatetruecolor($w, $h);
		imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, 10, 120, 200));
		return $im;
	}

	/** Builds one IPTC IIM dataset record for record 2 (application). */
	private function iimRecord(int $dataset, string $value): string
	{
		return "\x1C\x02" . chr($dataset) . pack('n', strlen($value)) . $value;
	}

	private function jpegBytes(int $w = 24, int $h = 24): string
	{
		$im = $this->gdImage($w, $h);
		ob_start();
		imagejpeg($im, null, 90);
		imagedestroy($im);
		return ob_get_clean();
	}

	public function testMarkerWalkSkipsFillBytesAndStandaloneMarkers()
	{
		// Padding that is not a marker is stepped over one byte at a time...
		$padded = JPEGImage::fromString("\xFF\xD8" . "\x00\x00" . substr($this->minimalJpeg(), 2));
		self::assertSame(24, $padded->getWidth());
		self::assertSame(16, $padded->getHeight());
		self::assertCount(1, $padded->getSegments());   // only the SOF was recorded
		self::assertSame(bin2hex($this->minimalJpeg()), bin2hex($padded->toBinary()));

		// ...and a standalone marker (no length field, no payload) two bytes at a time.
		$standalone = JPEGImage::fromString($this->minimalJpeg("\xFF\x01"));   // TEM
		self::assertSame(24, $standalone->getWidth());
		self::assertSame(16, $standalone->getHeight());
		self::assertCount(1, $standalone->getSegments());
		self::assertSame(bin2hex($this->minimalJpeg()), bin2hex($standalone->toBinary()));
	}

	public function testTruncatedSegmentHeaderStopsTheWalk()
	{
		// An APP0 marker with no room for its length field: both walks stop there.
		$jpeg = JPEGImage::fromString("\xFF\xD8\xFF\xE0");
		self::assertNull($jpeg->getWidth());
		self::assertNull($jpeg->getHeight());
		self::assertSame([], $jpeg->getSegments());
		self::assertSame('', $jpeg->getScan());
		self::assertSame(bin2hex("\xFF\xD8"), bin2hex($jpeg->toBinary()));
	}

	public function testUnterminatedScanRunsToEndOfFile()
	{
		// Entropy data with no following marker and no EOI: the scan is the rest of the file.
		$bytes = "\xFF\xD8" . self::SOF . self::SOS . 'scandata-without-a-marker';
		$jpeg = JPEGImage::fromString($bytes);
		self::assertSame(24, $jpeg->getWidth());
		self::assertSame(16, $jpeg->getHeight());
		self::assertStringStartsWith("\xFF\xDA", $jpeg->getScan());
		self::assertSame(bin2hex($bytes), bin2hex($jpeg->toBinary()));
	}

	public function testCommentAppendedWhenNoneExists()
	{
		$jpeg = JPEGImage::fromString($this->minimalJpeg());
		self::assertNull($jpeg->getComment());

		$jpeg->setComment('appended comment');
		$reloaded = JPEGImage::fromString($jpeg->toBinary());
		self::assertSame('appended comment', $reloaded->getComment());
		self::assertSame(24, $reloaded->getWidth());   // the frame is untouched
	}

	public function testExtendedXmpFragmentTooShortIsIgnored()
	{
		// An extension segment shorter than the digest/length/offset header carries nothing.
		$payload = JPEGImage::XMP_EXTENSION_IDENTIFIER . 'tooshort';
		$jpeg = JPEGImage::fromString($this->minimalJpeg($this->segment(JPEGImage::APP1, $payload)));
		self::assertNull($jpeg->getXmpText());
		self::assertNull($jpeg->getXMP());
		self::assertSame(bin2hex($this->minimalJpeg()), bin2hex($jpeg->toBinary()));
	}

	public function testExtendedXmpUnparsablePacketIsIgnored()
	{
		// A well-formed extension header whose reassembled body is not an XMP packet.
		$body = 'not xml at all';
		$payload = JPEGImage::XMP_EXTENSION_IDENTIFIER . str_repeat('A', 32)
			. pack('N', strlen($body)) . pack('N', 0) . $body;
		$jpeg = JPEGImage::fromString($this->minimalJpeg($this->segment(JPEGImage::APP1, $payload)));
		self::assertNull($jpeg->getXmpText());
		self::assertSame(bin2hex($this->minimalJpeg()), bin2hex($jpeg->toBinary()));
	}

	public function testExtendedXmpBecomesTheMainPacketWhenThereIsNone()
	{
		$xmp = XMP::blank();
		$xmp->setProperty(XMP::NS_DC, 'title', 'Extended Title');
		$text = $xmp->toPacketText(false);
		$half = (int) ceil(strlen($text) / 2);
		$digest = strtoupper(md5($text));
		$chunk = fn (int $offset, string $part): string => JPEGImage::XMP_EXTENSION_IDENTIFIER
			. $digest . pack('N', strlen($text)) . pack('N', $offset) . $part;

		// The fragments arrive out of order and there is no main APP1 XMP segment.
		$jpeg = JPEGImage::fromString($this->minimalJpeg(
			$this->segment(JPEGImage::APP1, $chunk($half, substr($text, $half)))
			. $this->segment(JPEGImage::APP1, $chunk(0, substr($text, 0, $half))),
		));
		self::assertSame(['Extended Title'], $jpeg->getXMP()?->getProperty(XMP::NS_DC, 'title'));

		// It is written back as one standard XMP segment, since it now fits in one.
		$out = $jpeg->toBinary();
		self::assertStringContainsString(JPEGImage::XMP_IDENTIFIER, $out);
		self::assertStringNotContainsString(JPEGImage::XMP_EXTENSION_IDENTIFIER, $out);
		self::assertSame(['Extended Title'], JPEGImage::fromString($out)->getXMP()?->getProperty(XMP::NS_DC, 'title'));
	}

	public function testJumbfFragmentTooShortIsIgnored()
	{
		// An APP11 that is box-signed but shorter than the instance/sequence/box header.
		$jpeg = JPEGImage::fromString($this->minimalJpeg(
			$this->segment(JPEGImage::APP11, JPEGImage::JUMBF_IDENTIFIER . "\x00\x01\x00\x00"),
		));
		self::assertSame([], $jpeg->getJumbfBoxes());
		self::assertSame(bin2hex($this->minimalJpeg()), bin2hex($jpeg->toBinary()));
	}

	public function testMalformedKodakMetaSegmentStaysRaw()
	{
		// The APP3 carries the Meta signature but no readable TIFF structure behind it.
		$payload = EXIF::MetaSignature . 'garbage!!';
		$bytes = $this->minimalJpeg($this->segment(JPEGImage::APP3, $payload));
		$jpeg = JPEGImage::fromString($bytes);
		self::assertNull($jpeg->getMeta());

		// Kept verbatim as a raw segment, so the file still round-trips byte for byte.
		$segments = $jpeg->getSegments();
		self::assertSame(JPEGImage::APP3, $segments[0]['marker']);
		self::assertSame('raw', $segments[0]['kind']);
		self::assertSame($payload, $segments[0]['payload']);
		self::assertSame(bin2hex($bytes), bin2hex($jpeg->toBinary()));
	}

	public function testLegacyPhotoshopIptcSegmentIsReadAndRewritten()
	{
		// A Photoshop 2.5 APP13: not the 3.0 IRB signature, but an IPTC 8BIM resource.
		$iim = $this->iimRecord(0x78, 'Legacy');   // 2#120 Caption-Abstract
		$payload = str_replace("Photoshop 3.0\x00", "Photoshop 2.5\x00", Photoshop8BIM::iptcEncode($iim));

		$jpeg = JPEGImage::fromString($this->minimalJpeg($this->segment(JPEGImage::APP13, $payload)));
		self::assertNull($jpeg->getPhotoshopIRB());   // no image-resource block, just the records
		self::assertTrue($jpeg->hasIPTC());
		self::assertSame('Legacy', $jpeg->getIPTC()[IPTCTags::CaptionAbstract]);

		// The records are re-emitted from the live object as a single APP13.
		$jpeg->getIPTC()[IPTCTags::CaptionAbstract] = 'Rewritten';
		$reloaded = JPEGImage::fromString($jpeg->toBinary());
		self::assertSame('Rewritten', $reloaded->getIPTC()[IPTCTags::CaptionAbstract]);
		self::assertSame(24, $reloaded->getWidth());
	}

	public function testJfxxSegmentRecomposedFromItsParsedKind()
	{
		$jfxx = new JFXX();
		$thumb = $this->gdImage(8, 8);
		$jfxx->setImage($thumb, JFXX::COLOR_THUMB);
		imagedestroy($thumb);

		$jpeg = JPEGImage::fromString($this->jpegBytes());
		$jpeg->setJFXX($jfxx);                             // injected: no jfxx segment yet
		$parsed = JPEGImage::fromString($jpeg->toBinary());    // now the segment list has one
		$again = JPEGImage::fromString($parsed->toBinary());   // rewritten from the parsed kind

		self::assertSame(JFXX::COLOR_THUMB, $again->getJFXX()?->getFormat());
		self::assertSame(8, $again->getJFXX()->getXThumbnail());
		self::assertSame(bin2hex($parsed->toBinary()), bin2hex($again->toBinary()));
	}

	public function testDroppedIccProfileEmitsNoSegment()
	{
		$jpeg = JPEGImage::fromString($this->jpegBytes());
		$jpeg->setICCProfile(str_repeat('IC', 100));
		$withProfile = JPEGImage::fromString($jpeg->toBinary());
		self::assertTrue($withProfile->hasICCProfile());

		$withProfile->setICCProfile(null);   // the icc segment kind stays, its payload is gone
		$out = $withProfile->toBinary();
		self::assertStringNotContainsString('ICC_PROFILE', $out);
		self::assertFalse(JPEGImage::fromString($out)->hasICCProfile());
		self::assertSame(24, JPEGImage::fromString($out)->getWidth());
	}

	public function testDroppedPhotoshopIrbEmitsNoSegment()
	{
		$path = tempnam(sys_get_temp_dir(), 'irb');
		try {
			file_put_contents($path, $this->jpegBytes());
			$embedded = iptcembed($this->iimRecord(0x78, 'Original'), $path);
		} finally {
			@unlink($path);
		}
		self::assertNotFalse($embedded);

		$jpeg = JPEGImage::fromString($embedded);
		self::assertNotNull($jpeg->getPhotoshopIRB());

		$jpeg->setPhotoshopIRB(null);
		$jpeg->setIPTC(null);
		$out = $jpeg->toBinary();
		self::assertStringNotContainsString('Photoshop', $out);
		self::assertNull(JPEGImage::fromString($out)->getPhotoshopIRB());
		self::assertFalse(JPEGImage::fromString($out)->hasIPTC());
		self::assertSame(24, JPEGImage::fromString($out)->getWidth());
	}

	public function testJfifInjectedIntoAJpegWithoutApp0()
	{
		$jpeg = JPEGImage::fromString($this->minimalJpeg());
		self::assertNull($jpeg->getJFIF());

		$jfif = new JFIF();
		$jfif->setUnits(JFIF::UNITS_PPI);
		$jfif->setXDensity(150);
		$jfif->setYDensity(150);
		$jpeg->setJFIF($jfif);

		$reloaded = JPEGImage::fromString($jpeg->toBinary());
		self::assertInstanceOf(JFIF::class, $reloaded->getJFIF());
		self::assertSame(150, $reloaded->getJFIF()->getXDensity());
		self::assertSame(JFIF::UNITS_PPI, $reloaded->getJFIF()->getUnits());
		self::assertSame(24, $reloaded->getWidth());
	}

	public function testUnparsableXmpPacketReadsAsNoXmpButIsKeptVerbatim()
	{
		// The APP1 is XMP-signed, but the packet behind the identifier is not XML.
		$text = '<x:xmpmeta>truncated';
		$bytes = $this->minimalJpeg($this->segment(JPEGImage::APP1, JPEGImage::XMP_IDENTIFIER . $text));
		$jpeg = JPEGImage::fromString($bytes);

		self::assertSame($text, $jpeg->getXmpText());   // the raw text is still there...
		self::assertNull($jpeg->getXMP());              // ...but there is no DOM to answer
		self::assertSame(bin2hex($bytes), bin2hex($jpeg->toBinary()));

		// A packet that does parse answers the DOM from the same accessor.
		$xmp = XMP::blank();
		$xmp->setProperty(XMP::NS_DC, 'title', 'Readable');
		$jpeg->setXmpText($xmp->toPacketText(false));
		self::assertSame(['Readable'], $jpeg->getXMP()?->getProperty(XMP::NS_DC, 'title'));
	}

	public function testExtendedXmpMergedWhenTheMainPacketNamesNoDigest()
	{
		// The main packet carries no xmpNote:HasExtendedXMP, so the only reassembled
		// packet is taken as the extension rather than being dropped for want of a name.
		$main = XMP::blank();
		$main->setProperty(XMP::NS_DC, 'title', 'Main Title');
		$extended = XMP::blank();
		$extended->setProperty(XMP::NS_DC, 'description', 'From The Extension');
		$text = $extended->toPacketText(false);
		$chunk = JPEGImage::XMP_EXTENSION_IDENTIFIER . strtoupper(md5($text))
			. pack('N', strlen($text)) . pack('N', 0) . $text;

		$jpeg = JPEGImage::fromString($this->minimalJpeg(
			$this->segment(JPEGImage::APP1, JPEGImage::XMP_IDENTIFIER . $main->toPacketText(false))
			. $this->segment(JPEGImage::APP1, $chunk),
		));
		self::assertSame(['Main Title'], $jpeg->getXMP()?->getProperty(XMP::NS_DC, 'title'));
		self::assertSame(['From The Extension'], $jpeg->getXMP()?->getProperty(XMP::NS_DC, 'description'));
	}

	public function testExtendedXmpPrefersThePacketTheMainOneNames()
	{
		// Two complete extension packets arrive; the digest in xmpNote:HasExtendedXMP
		// picks the second one although the first is the earlier segment.
		$packet = function (string $description): array {
			$xmp = XMP::blank();
			$xmp->setProperty(XMP::NS_DC, 'description', $description);
			$text = $xmp->toPacketText(false);
			return [strtoupper(md5($text)), $text];
		};
		[$otherDigest, $otherText] = $packet('Other Packet');
		[$namedDigest, $namedText] = $packet('Named Packet');
		$chunk = fn (string $digest, string $text): string => JPEGImage::XMP_EXTENSION_IDENTIFIER
			. $digest . pack('N', strlen($text)) . pack('N', 0) . $text;

		$main = XMP::blank();
		$main->setProperty(XMP::NS_NOTE, 'HasExtendedXMP', $namedDigest);
		$jpeg = JPEGImage::fromString($this->minimalJpeg(
			$this->segment(JPEGImage::APP1, JPEGImage::XMP_IDENTIFIER . $main->toPacketText(false))
			. $this->segment(JPEGImage::APP1, $chunk($otherDigest, $otherText))
			. $this->segment(JPEGImage::APP1, $chunk($namedDigest, $namedText)),
		));
		self::assertSame(['Named Packet'], $jpeg->getXMP()?->getProperty(XMP::NS_DC, 'description'));
		self::assertNull($jpeg->getXMP()?->getProperty(XMP::NS_NOTE, 'HasExtendedXMP'));
	}

	public function testEmptyJumbfBoxEmitsOneHeaderOnlySegment()
	{
		// A box with no payload still has to be written: one APP11 carrying the eight
		// header bytes and nothing else.
		$jpeg = JPEGImage::fromString($this->minimalJpeg());
		$jpeg->setJumbfBoxes([new JUMBFBox('json', '')]);

		$expected = $this->minimalJpeg($this->segment(
			JPEGImage::APP11,
			JPEGImage::JUMBF_IDENTIFIER . pack('n', 1) . pack('N', 1) . pack('N', 8) . 'json',
		));
		self::assertSame(bin2hex($expected), bin2hex($jpeg->toBinary()));

		$reloaded = JPEGImage::fromString($jpeg->toBinary());
		self::assertCount(1, $reloaded->getJumbfBoxes());
		self::assertSame('json', $reloaded->getJumbfBoxes()[0]->getType());
		self::assertSame('', $reloaded->getJumbfBoxes()[0]->getPayload());
	}

	public function testTruncatedJfifSegmentIsUnparsableAndDropped()
	{
		// The APP0 carries the JFIF identifier but stops before the density fields.
		$bytes = $this->minimalJpeg($this->segment(JPEGImage::APP0, JFIF::IDENTIFIER . "\x01\x01\x00"));
		$jpeg = JPEGImage::fromString($bytes);
		self::assertNull($jpeg->getJFIF());
		self::assertSame('jfif', $jpeg->getSegments()[0]['kind']);

		// The kind stays in the list, but there is no JFIF left to write for it.
		self::assertSame(bin2hex($this->minimalJpeg()), bin2hex($jpeg->toBinary()));

		// A complete APP0 is re-emitted from the parsed object.
		$intact = JPEGImage::fromString($this->jpegBytes());
		self::assertNotNull($intact->getJFIF());
		self::assertStringContainsString(JFIF::IDENTIFIER, $intact->toBinary());
	}

	public function testTruncatedJfxxSegmentIsUnparsableAndDropped()
	{
		// The APP0 carries the JFXX identifier and not one byte more.
		$bytes = $this->minimalJpeg($this->segment(JPEGImage::APP0, JFXX::IDENTIFIER));
		$jpeg = JPEGImage::fromString($bytes);
		self::assertNull($jpeg->getJFXX());
		self::assertSame('jfxx', $jpeg->getSegments()[0]['kind']);
		self::assertSame(bin2hex($this->minimalJpeg()), bin2hex($jpeg->toBinary()));
	}

	public function testMalformedLegacyIptcSegmentIsUnparsableAndDropped()
	{
		// A Photoshop 2.5 IPTC resource whose payload does not start with the IIM tag
		// marker: the block is recognized, the records behind it are not.
		$payload = str_replace(
			"Photoshop 3.0\x00",
			"Photoshop 2.5\x00",
			Photoshop8BIM::iptcEncode("\x00\x00\x00\x00"),
		);
		$bytes = $this->minimalJpeg($this->segment(JPEGImage::APP13, $payload));
		$jpeg = JPEGImage::fromString($bytes);

		self::assertNull($jpeg->getIPTC());
		self::assertFalse($jpeg->hasIPTC());
		self::assertSame('iptc', $jpeg->getSegments()[0]['kind']);
		self::assertSame(bin2hex($this->minimalJpeg()), bin2hex($jpeg->toBinary()));
	}

	public function testKodakMetaSegmentIsRewrittenAndDroppable()
	{
		$meta = new EXIF();
		$meta->setSignature(EXIF::MetaSignature);
		$meta->getIfd0(true)->setTagValues(0x010E, TIFFDataType::Ascii, "Kodak Meta\0");
		$jpeg = JPEGImage::fromString($this->minimalJpeg($this->segment(JPEGImage::APP3, $meta->toBinary())));

		self::assertNotNull($jpeg->getMeta());
		self::assertSame('meta', $jpeg->getSegments()[0]['kind']);

		// Written back from the live object...
		$rewritten = JPEGImage::fromString($jpeg->toBinary());
		self::assertSame('Kodak Meta', $rewritten->getMeta()?->getIfd0()?->getTagValue(0x010E));

		// ...and the APP3 disappears once the Meta block is dropped.
		$rewritten->setMeta(null);
		$out = $rewritten->toBinary();
		self::assertStringNotContainsString(EXIF::MetaSignature, $out);
		self::assertSame(bin2hex($this->minimalJpeg()), bin2hex($out));
		self::assertNull(JPEGImage::fromString($out)->getMeta());
	}

	public function testPictureInfoSegmentIsRewrittenAndDroppable()
	{
		$info = new PictureInfo();
		$info->setHeader('[picture info]');
		$info->setText("\r\nResolution=1024x768\r\n[end]");
		$payload = $info->toBinary();
		$jpeg = JPEGImage::fromString($this->minimalJpeg($this->segment(JPEGImage::APP12, $payload)));

		self::assertSame(['Resolution' => '1024x768'], $jpeg->getPictureInfo()?->getFields());
		self::assertSame('pictureinfo', $jpeg->getSegments()[0]['kind']);

		// The APP12 is regenerated from the parsed object, byte for byte...
		self::assertSame(
			bin2hex($this->minimalJpeg($this->segment(JPEGImage::APP12, $payload))),
			bin2hex($jpeg->toBinary()),
		);

		// ...and vanishes when the picture info is dropped.
		$jpeg->setPictureInfo(null);
		self::assertSame(bin2hex($this->minimalJpeg()), bin2hex($jpeg->toBinary()));
	}
}
