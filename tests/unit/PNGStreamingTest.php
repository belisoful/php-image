<?php

use Belisoful\Image\Meta\XMP;
use Belisoful\Image\PNGImage;

/**
 * Unit tests for the streaming (lazy) PNG path: {@see PNGImage::fromStreamLazy()} reads the
 * chunk framing and small metadata but keeps each `IDAT` as a deferred range into the
 * source, and {@see PNGImage::streamTo()} copies those pixel chunks straight through while
 * rebuilding the metadata — so a metadata edit rewrites the file without holding its pixels.
 */
class PNGStreamingTest extends PHPUnit\Framework\TestCase
{
	private function pngBytes(int $w = 24, int $h = 16): string
	{
		$gd = imagecreatetruecolor($w, $h);
		for ($y = 0; $y < $h; $y++) {
			for ($x = 0; $x < $w; $x++) {
				imagesetpixel($gd, $x, $y, imagecolorallocate($gd, ($x * 11) & 255, ($y * 17) & 255, (($x + $y) * 7) & 255));
			}
		}
		ob_start();
		imagepng($gd);
		$bytes = (string) ob_get_clean();
		imagedestroy($gd);
		return $bytes;
	}

	public function testStreamingRoundTripIsByteFaithful()
	{
		$bytes = $this->pngBytes();
		$source = TestIOHelper::dataResource($bytes);
		$png = PNGImage::fromStreamLazy($source);

		$target = TestIOHelper::memoryResource();
		$written = $png->streamTo($target);
		rewind($target);
		$out = (string) stream_get_contents($target);

		self::assertSame(strlen($out), $written);
		self::assertSame(bin2hex($bytes), bin2hex($out), 'A no-edit streamed rewrite is byte-identical.');
		fclose($source);
		fclose($target);
	}

	public function testAStreamedPngComposesByMaterializingItsDeferredPixels()
	{
		$bytes = $this->pngBytes();
		$source = TestIOHelper::dataResource($bytes);
		$png = PNGImage::fromStreamLazy($source);

		// toBinary() composes the whole string, materializing the deferred IDAT chunks.
		self::assertSame(bin2hex($bytes), bin2hex((string) $png->toBinary()));
		fclose($source);
	}

	public function testStreamingAMetadataEditKeepsThePixels()
	{
		$bytes = $this->pngBytes();
		$source = TestIOHelper::dataResource($bytes);
		$png = PNGImage::fromStreamLazy($source);
		self::assertSame([24, 16], [$png->getWidth(), $png->getHeight()]);

		$xmp = XMP::blank();
		$xmp->setProperty(XMP::NS_DC, 'title', 'Streamed edit');
		$png->setXMP($xmp);

		$target = TestIOHelper::memoryResource();
		$png->streamTo($target);
		rewind($target);
		$out = (string) stream_get_contents($target);
		fclose($source);
		fclose($target);

		// Re-read the streamed output the whole-string way: the edit landed and the pixels survived.
		$round = PNGImage::fromString($out);
		self::assertContains('Streamed edit', (array) $round->getXMP()?->getProperty(XMP::NS_DC, 'title'), 'dc:title is a LangAlt.');
		self::assertSame([24, 16], [$round->getWidth(), $round->getHeight()]);
	}

	public function testFromStreamLazyRejectsANonSeekableStream()
	{
		self::expectException(\RuntimeException::class);
		PNGImage::fromStreamLazy(new TestNonSeekableStream($this->pngBytes()));
	}

	public function testFromStreamLazyRejectsBytesThatAreNotPng()
	{
		self::expectException(\UnexpectedValueException::class);
		PNGImage::fromStreamLazy(TestIOHelper::dataResource('this is not a PNG file'));
	}

	public function testFromStreamLazyToleratesATailWithoutIend()
	{
		// Strip the 12-byte IEND so the chunk stream ends at a boundary; the lazy parse reads
		// the chunks it has and stops at end-of-stream instead of throwing.
		$source = TestIOHelper::dataResource(substr($this->pngBytes(), 0, -12));
		$png = PNGImage::fromStreamLazy($source);
		self::assertNotEmpty($png->getChunks());
		fclose($source);
	}
}
