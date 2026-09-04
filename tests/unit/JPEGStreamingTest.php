<?php

use Belisoful\Image\JPEGImage;
use Belisoful\Image\Meta\XMP;

/**
 * Unit tests for the streaming (lazy) JPEG path: {@see JPEGImage::fromStreamLazy()} reads
 * the segments but keeps the entropy scan (SOS to end) as a deferred range into the source,
 * and {@see JPEGImage::streamTo()} rebuilds the segments and copies the scan straight
 * through — a metadata edit rewrites the file without holding its pixels.
 */
class JPEGStreamingTest extends PHPUnit\Framework\TestCase
{
	private function jpegBytes(int $w = 24, int $h = 16): string
	{
		$gd = imagecreatetruecolor($w, $h);
		for ($y = 0; $y < $h; $y++) {
			for ($x = 0; $x < $w; $x++) {
				imagesetpixel($gd, $x, $y, imagecolorallocate($gd, ($x * 11) & 255, ($y * 17) & 255, (($x + $y) * 7) & 255));
			}
		}
		ob_start();
		imagejpeg($gd, null, 90);
		$bytes = (string) ob_get_clean();
		imagedestroy($gd);
		return $bytes;
	}

	public function testStreamingProducesTheSameBytesAsAWholeParseCompose()
	{
		$bytes = $this->jpegBytes();
		$source = TestIOHelper::dataResource($bytes);
		$lazy = JPEGImage::fromStreamLazy($source);

		$target = TestIOHelper::memoryResource();
		$written = $lazy->streamTo($target);
		rewind($target);
		$out = (string) stream_get_contents($target);

		self::assertSame(strlen($out), $written);
		self::assertSame(bin2hex((string) JPEGImage::fromString($bytes)->toBinary()), bin2hex($out), 'streamed == whole-parse compose');
		fclose($source);
		fclose($target);
	}

	public function testAStreamedJpegComposesByMaterializingItsDeferredScan()
	{
		$bytes = $this->jpegBytes();
		$source = TestIOHelper::dataResource($bytes);
		$lazy = JPEGImage::fromStreamLazy($source);
		self::assertSame(
			bin2hex((string) JPEGImage::fromString($bytes)->toBinary()),
			bin2hex((string) $lazy->toBinary()),
			'toBinary() materializes the deferred scan',
		);
		fclose($source);
	}

	public function testStreamingAMetadataEditKeepsThePixels()
	{
		$bytes = $this->jpegBytes();
		$source = TestIOHelper::dataResource($bytes);
		$jpeg = JPEGImage::fromStreamLazy($source);
		self::assertSame([24, 16], [$jpeg->getWidth(), $jpeg->getHeight()]);

		$xmp = XMP::blank();
		$xmp->setProperty(XMP::NS_DC, 'title', 'Streamed edit');
		$jpeg->setXMP($xmp);

		$target = TestIOHelper::memoryResource();
		$jpeg->streamTo($target);
		rewind($target);
		$out = (string) stream_get_contents($target);
		fclose($source);
		fclose($target);

		$round = JPEGImage::fromString($out);
		self::assertContains('Streamed edit', (array) $round->getXMP()?->getProperty(XMP::NS_DC, 'title'), 'dc:title is a LangAlt.');
		self::assertSame([24, 16], [$round->getWidth(), $round->getHeight()]);
	}

	public function testFromStreamLazyRejectsANonSeekableStream()
	{
		self::expectException(\RuntimeException::class);
		JPEGImage::fromStreamLazy(new TestNonSeekableStream($this->jpegBytes()));
	}

	public function testFromStreamLazyRejectsBytesThatAreNotJpeg()
	{
		self::expectException(\UnexpectedValueException::class);
		JPEGImage::fromStreamLazy(TestIOHelper::dataResource('this is not a JPEG file'));
	}

	/** SOF0 for a 24x16 frame. */
	private const SOF = "\xFF\xC0\x00\x0B\x08\x00\x10\x00\x18\x01\x01\x11\x00";

	/** A minimal SOS header. */
	private const SOS = "\xFF\xDA\x00\x08\x01\x01\x00\x00\x3F\x00";

	private function minimalJpeg(string $segments = ''): string
	{
		return "\xFF\xD8" . $segments . self::SOF . self::SOS . 'scandata' . "\xFF\xD9";
	}

	public function testLazyParseSkipsAStandaloneMarkerAndReadsDnl()
	{
		// A standalone TEM (no length field) before the frame is skipped, and a DNL segment is
		// read — both the same as the whole-parse, so the streamed output matches it.
		$dnl = "\xFF\xDC\x00\x04\x00\x10";   // DNL declaring 16 lines
		$jpeg = $this->minimalJpeg("\xFF\x01" . $dnl);
		$source = TestIOHelper::dataResource($jpeg);
		$lazy = JPEGImage::fromStreamLazy($source);

		$target = TestIOHelper::memoryResource();
		$lazy->streamTo($target);
		rewind($target);
		self::assertSame(bin2hex((string) JPEGImage::fromString($jpeg)->toBinary()), bin2hex((string) stream_get_contents($target)));
		fclose($source);
		fclose($target);
	}

	public function testLazyParseToleratesAHeaderThatEndsBeforeTheScan()
	{
		// SOI + frame, no SOS/EOI: the walk stops at end of stream with no deferred scan.
		$jpeg = "\xFF\xD8" . self::SOF;
		$source = TestIOHelper::dataResource($jpeg);
		$lazy = JPEGImage::fromStreamLazy($source);
		self::assertSame([24, 16], [$lazy->getWidth(), $lazy->getHeight()]);

		$target = TestIOHelper::memoryResource();
		$lazy->streamTo($target);
		rewind($target);
		self::assertSame(bin2hex((string) JPEGImage::fromString($jpeg)->toBinary()), bin2hex((string) stream_get_contents($target)));
		fclose($source);
		fclose($target);
	}
}
