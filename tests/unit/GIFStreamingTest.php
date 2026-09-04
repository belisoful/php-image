<?php

use Belisoful\Image\GIFImage;
use Belisoful\Image\Meta\XMP;

/**
 * Unit tests for the streaming (lazy) GIF path: {@see GIFImage::fromStreamLazy()} reads the
 * block structure but keeps each frame's LZW image-data run as a deferred range into the
 * source, and {@see GIFImage::streamTo()} copies those runs straight through while
 * rebuilding the rest — a metadata edit rewrites the file without holding its pixels.
 */
class GIFStreamingTest extends PHPUnit\Framework\TestCase
{
	private function gifBytes(int $w = 24, int $h = 16): string
	{
		$gd = imagecreatetruecolor($w, $h);
		for ($y = 0; $y < $h; $y++) {
			for ($x = 0; $x < $w; $x++) {
				imagesetpixel($gd, $x, $y, imagecolorallocate($gd, ($x * 11) & 255, ($y * 17) & 255, (($x + $y) * 7) & 255));
			}
		}
		ob_start();
		imagegif($gd);
		$bytes = (string) ob_get_clean();
		imagedestroy($gd);
		return $bytes;
	}

	/** A GIF carrying a comment, a NETSCAPE loop extension, and a raw XMP application block. */
	private function richGif(): string
	{
		$gif = GIFImage::fromString($this->gifBytes());
		$gif->addComment('a streamed comment');
		$gif->setLoopCount(3);
		$xmp = XMP::blank();
		$xmp->setProperty(XMP::NS_DC, 'title', 'Streamed edit');
		$gif->setXMP($xmp);
		return (string) $gif->toBinary();
	}

	public function testStreamingRoundTripIsByteFaithful()
	{
		$bytes = $this->richGif();
		$source = TestIOHelper::dataResource($bytes);
		$gif = GIFImage::fromStreamLazy($source);

		$target = TestIOHelper::memoryResource();
		$written = $gif->streamTo($target);
		rewind($target);
		$out = (string) stream_get_contents($target);

		self::assertSame(strlen($out), $written);
		self::assertSame(bin2hex($bytes), bin2hex($out), 'A no-edit streamed rewrite is byte-identical.');
		fclose($source);
		fclose($target);
	}

	public function testAStreamedGifComposesByMaterializingItsDeferredPixels()
	{
		$bytes = $this->richGif();
		$source = TestIOHelper::dataResource($bytes);
		$gif = GIFImage::fromStreamLazy($source);
		self::assertSame(bin2hex($bytes), bin2hex((string) $gif->toBinary()));
		// A deferred frame still materializes its sub-blocks on demand.
		self::assertNotEmpty($gif->getFrames()[0]->getDataSubBlocks());
		fclose($source);
	}

	public function testStreamingAMetadataEditKeepsThePixels()
	{
		$bytes = $this->gifBytes();
		$source = TestIOHelper::dataResource($bytes);
		$gif = GIFImage::fromStreamLazy($source);
		self::assertSame([24, 16], [$gif->getWidth(), $gif->getHeight()]);

		$xmp = XMP::blank();
		$xmp->setProperty(XMP::NS_DC, 'title', 'Streamed edit');
		$gif->setXMP($xmp);

		$target = TestIOHelper::memoryResource();
		$gif->streamTo($target);
		rewind($target);
		$out = (string) stream_get_contents($target);
		fclose($source);
		fclose($target);

		$round = GIFImage::fromString($out);
		self::assertContains('Streamed edit', (array) $round->getXMP()?->getProperty(XMP::NS_DC, 'title'), 'dc:title is a LangAlt.');
		self::assertSame([24, 16], [$round->getWidth(), $round->getHeight()]);
	}

	public function testFromStreamLazyRejectsANonSeekableStream()
	{
		self::expectException(\RuntimeException::class);
		GIFImage::fromStreamLazy(new TestNonSeekableStream($this->gifBytes()));
	}

	public function testFromStreamLazyRejectsBytesThatAreNotGif()
	{
		self::expectException(\UnexpectedValueException::class);
		GIFImage::fromStreamLazy(TestIOHelper::dataResource('this is not a GIF at all'));
	}

	/** A 4x4 GIF header with a 2-colour global table. */
	private function craftedHeader(): string
	{
		return "GIF89a" . pack('v', 4) . pack('v', 4) . chr(0x80) . chr(0) . chr(0) . "\x00\x00\x00\xFF\xFF\xFF";
	}

	public function testLazyParseHandlesLocalTablesGraphicControlEmptyExtensionsAndTrailingBytes()
	{
		$emptyComment = "\x21\xFE\x00";                                  // an empty comment extension
		$gce = "\x21\xF9\x04\x00\x0A\x00\x00\x00";                       // graphic control, delay 10
		$frame = "\x2C" . pack('vvvv', 0, 0, 4, 4) . chr(0x80)           // image descriptor with a local table
			. "\x00\x00\x00\xFF\xFF\xFF"                                 // 2-colour local table
			. "\x02" . "\x02XX" . "\x00";                                // min-code-size + one sub-block + terminator
		$gif = $this->craftedHeader() . $emptyComment . $gce . $frame . "\x3B" . 'trailing';

		$source = TestIOHelper::dataResource($gif);
		$lazy = GIFImage::fromStreamLazy($source);
		$target = TestIOHelper::memoryResource();
		$lazy->streamTo($target);
		rewind($target);
		self::assertSame(bin2hex((string) GIFImage::fromString($gif)->toBinary()), bin2hex((string) stream_get_contents($target)));
		fclose($source);
		fclose($target);
	}

	public function testLazyParseRejectsAnUnexpectedBlockMarker()
	{
		self::expectException(\UnexpectedValueException::class);
		GIFImage::fromStreamLazy(TestIOHelper::dataResource($this->craftedHeader() . "\x99"));
	}
}
