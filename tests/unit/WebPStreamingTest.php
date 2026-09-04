<?php

use Belisoful\Image\Meta\XMP;
use Belisoful\Image\WebPImage;

/**
 * Unit tests for the streaming (lazy) WebP path: {@see WebPImage::fromStreamLazy()} reads
 * the RIFF framing and small metadata chunks but keeps each large pixel chunk as a deferred
 * range into the source, and {@see WebPImage::streamTo()} copies those chunks straight
 * through while rebuilding the metadata — a metadata edit rewrites the file without pixels.
 */
class WebPStreamingTest extends PHPUnit\Framework\TestCase
{
	private function webpBytes(int $w = 24, int $h = 16): string
	{
		if (!function_exists('imagewebp')) {
			self::markTestSkipped('GD lacks WebP support.');
		}
		$gd = imagecreatetruecolor($w, $h);
		for ($y = 0; $y < $h; $y++) {
			for ($x = 0; $x < $w; $x++) {
				imagesetpixel($gd, $x, $y, imagecolorallocate($gd, ($x * 11) & 255, ($y * 17) & 255, (($x + $y) * 7) & 255));
			}
		}
		ob_start();
		imagewebp($gd);
		$bytes = (string) ob_get_clean();
		imagedestroy($gd);
		return $bytes;
	}

	public function testStreamingRoundTripIsByteFaithful()
	{
		$bytes = $this->webpBytes();
		$source = TestIOHelper::dataResource($bytes);
		$webp = WebPImage::fromStreamLazy($source);

		$target = TestIOHelper::memoryResource();
		$written = $webp->streamTo($target);
		rewind($target);
		$out = (string) stream_get_contents($target);

		self::assertSame(strlen($out), $written);
		self::assertSame(bin2hex($bytes), bin2hex($out), 'A no-edit streamed rewrite is byte-identical.');
		fclose($source);
		fclose($target);
	}

	public function testAStreamedWebPComposesByMaterializingItsDeferredPixels()
	{
		$bytes = $this->webpBytes();
		$source = TestIOHelper::dataResource($bytes);
		$webp = WebPImage::fromStreamLazy($source);
		self::assertSame(bin2hex($bytes), bin2hex((string) $webp->toBinary()));
		fclose($source);
	}

	public function testStreamingAMetadataEditKeepsThePixels()
	{
		$bytes = $this->webpBytes();
		$source = TestIOHelper::dataResource($bytes);
		$webp = WebPImage::fromStreamLazy($source);
		self::assertSame([24, 16], [$webp->getWidth(), $webp->getHeight()]);

		$xmp = XMP::blank();
		$xmp->setProperty(XMP::NS_DC, 'title', 'Streamed edit');
		$webp->setXMP($xmp);

		$target = TestIOHelper::memoryResource();
		$webp->streamTo($target);
		rewind($target);
		$out = (string) stream_get_contents($target);
		fclose($source);
		fclose($target);

		$round = WebPImage::fromString($out);
		self::assertContains('Streamed edit', (array) $round->getXMP()?->getProperty(XMP::NS_DC, 'title'), 'dc:title is a LangAlt.');
		self::assertSame([24, 16], [$round->getWidth(), $round->getHeight()]);
	}

	public function testFromStreamLazyRejectsANonSeekableStream()
	{
		self::expectException(\RuntimeException::class);
		WebPImage::fromStreamLazy(new TestNonSeekableStream($this->webpBytes()));
	}

	public function testFromStreamLazyRejectsBytesWithoutARiffHeader()
	{
		self::expectException(\UnexpectedValueException::class);
		WebPImage::fromStreamLazy(TestIOHelper::dataResource('not a riff container'));
	}

	public function testFromStreamLazyRejectsANonWebpRiffForm()
	{
		// A valid RIFF whose form type is not WEBP is rejected by WebPImage.
		self::expectException(\UnexpectedValueException::class);
		WebPImage::fromStreamLazy(TestIOHelper::dataResource('RIFF' . pack('V', 4) . 'WAVE'));
	}

	public function testStreamToOnAnUnparsedWebPFallsBackToItsBytes()
	{
		$webp = new WebPImage();   // never parsed: _riff is null
		$target = TestIOHelper::memoryResource();
		self::assertSame(0, $webp->streamTo($target));
		fclose($target);
	}

	/** Assembles a WEBP RIFF from `[id, data]` chunks (GD only makes plain lossy VP8). */
	private function riffWebp(array $chunks): string
	{
		$body = 'WEBP';
		foreach ($chunks as [$id, $data]) {
			$body .= $id . pack('V', strlen($data)) . $data;
			if (strlen($data) & 1) {
				$body .= "\0";
			}
		}
		return 'RIFF' . pack('V', strlen($body)) . $body;
	}

	public function testLazyParseOfAWebPWithMetadataChunks()
	{
		// VP8X carries the canvas size (24x16); the odd-length ICCP exercises the loaded-chunk
		// and even-length pad paths, and VP8 is the deferred pixel chunk.
		$vp8x = "\x00\x00\x00\x00\x17\x00\x00\x0f\x00\x00";
		$webp = $this->riffWebp([['VP8X', $vp8x], ['ICCP', 'odd12'], ['VP8 ', 'lossy-pixel-bytes']]);
		$source = TestIOHelper::dataResource($webp);
		$image = WebPImage::fromStreamLazy($source);
		self::assertSame([24, 16], [$image->getWidth(), $image->getHeight()]);

		$target = TestIOHelper::memoryResource();
		$image->streamTo($target);
		rewind($target);
		self::assertSame(bin2hex($webp), bin2hex((string) stream_get_contents($target)), 'metadata + deferred pixel chunk round-trip.');
		fclose($source);
		fclose($target);
	}

	public function testLazyParseOfALosslessWebP()
	{
		$vp8l = "\x2f" . pack('V', 23 | (15 << 14));   // VP8L header encoding 24x16
		$webp = $this->riffWebp([['VP8L', $vp8l]]);
		$source = TestIOHelper::dataResource($webp);
		$image = WebPImage::fromStreamLazy($source);
		self::assertSame([24, 16], [$image->getWidth(), $image->getHeight()]);

		$target = TestIOHelper::memoryResource();
		$image->streamTo($target);
		rewind($target);
		self::assertSame(bin2hex($webp), bin2hex((string) stream_get_contents($target)));
		fclose($source);
		fclose($target);
	}
}
