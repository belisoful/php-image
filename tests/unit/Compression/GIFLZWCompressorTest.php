<?php

use Belisoful\Image\Compression\CompressorInterface;
use Belisoful\Image\Compression\GIFLZWCompressor;

class GIFLZWCompressorTest extends PHPUnit\Framework\TestCase
{
	public function testIsACompressor()
	{
		self::assertContains(CompressorInterface::class, class_implements(GIFLZWCompressor::class));
	}

	public function testCanonicalOnePixelVector()
	{
		// The classic 1x1 GIF image block at minimum code size 2: Clear (4), pixel 0, EOI (5)
		// packed least-significant-bit first over 3-bit codes is exactly 44 01.
		self::assertSame("\x44\x01", GIFLZWCompressor::compress("\x00", 2));
		self::assertSame("\x00", GIFLZWCompressor::decompress("\x44\x01", 2));
	}

	public function testEmptyRoundTrips()
	{
		self::assertSame('', GIFLZWCompressor::decompress(GIFLZWCompressor::compress('', 2), 2));
		self::assertSame('', GIFLZWCompressor::decompress(GIFLZWCompressor::compress('', 8), 8));
	}

	public function testEmptyDataEndingOnAByteBoundaryPadsNothing()
	{
		// Empty data is Clear then EOI and nothing else, so the output is only as long as
		// those two codes: at minimum code size 3 they are two four-bit codes (8 then 9,
		// least-significant-bit first) filling exactly one byte, with no partial byte to
		// flush behind them.
		self::assertSame("\x98", GIFLZWCompressor::compress('', 3));
		self::assertSame('', GIFLZWCompressor::decompress("\x98", 3));

		// The same at minimum code size 7, where the two codes are whole bytes each.
		self::assertSame("\x80\x81", GIFLZWCompressor::compress('', 7));
		self::assertSame('', GIFLZWCompressor::decompress("\x80\x81", 7));

		// A code size whose two codes do not fill whole bytes still flushes the remainder:
		// at 2, Clear (4) and EOI (5) are six bits and the output is a padded byte longer.
		self::assertSame("\x2C", GIFLZWCompressor::compress('', 2));
	}

	public function testRoundTripsAcrossWidthsAndClearsPerMinCodeSize()
	{
		foreach ([2, 3, 4, 8] as $mcs) {
			$symbols = 1 << $mcs;
			$data = '';
			for ($i = 0; $i < 60000; $i++) {
				$data .= chr(($i * 31 + ($i >> 3)) % $symbols);   // varied enough to fill and clear the dictionary
			}
			$encoded = GIFLZWCompressor::compress($data, $mcs);
			self::assertSame($data, GIFLZWCompressor::decompress($encoded, $mcs), "mcs={$mcs}");
			self::assertLessThan(strlen($data), strlen($encoded), "mcs={$mcs} compresses");
		}
	}

	public function testMissingEndOfInformationDecodesTolerantly()
	{
		$data = str_repeat("\x01\x02\x03\x00", 50);
		$encoded = GIFLZWCompressor::compress($data, 2);
		// Some encoders rely on the sub-block framing alone; padding after the last full code
		// must not corrupt the decode when EOI is absent.
		$truncated = substr($encoded, 0, -1);
		$decoded = GIFLZWCompressor::decompress($truncated, 2);
		self::assertSame(substr($data, 0, strlen($decoded)), $decoded, 'A stream cut before EOI decodes its complete codes.');
		self::assertGreaterThan(0, strlen($decoded));
	}

	public function testSymbolBeyondTheCodeSpaceThrows()
	{
		$this->expectException(\RuntimeException::class);
		GIFLZWCompressor::compress("\x04", 2);   // 4 does not fit 2-bit symbols (0..3)
	}

	public function testSymbolBeyondTheCodeSpaceAfterTheFirstThrows()
	{
		// The check applies to every symbol, not just the first: 4 does not fit 2-bit symbols.
		try {
			GIFLZWCompressor::compress("\x00\x04", 2);
			self::fail('A symbol beyond the code space is rejected.');
		} catch (\RuntimeException $e) {
			self::assertInstanceOf(\UnexpectedValueException::class, $e);
		}
	}

	public function testMinCodeSizeOutOfRangeThrows()
	{
		$this->expectException(\RuntimeException::class);
		GIFLZWCompressor::compress('', 1);
	}

	public function testCorruptCodeThrows()
	{
		// Clear (4) then 7 at width 3: 7 is beyond the dictionary (next is 6) and not the KwKwK code.
		// LSB-first: bits 001|111 -> byte 0b00111100 = 0x3C.
		$this->expectException(\RuntimeException::class);
		GIFLZWCompressor::decompress("\x3C", 2);
	}

	public function testCorruptCodeAfterAFirstSymbolThrows()
	{
		// Clear (4), pixel 0, then 7 at width 3: with a previous code in hand, 7 is still
		// beyond the dictionary (next is 6) and is not the encoder's just-added entry.
		// LSB-first: 4, 0, 7 over 3-bit codes pack to C4 01.
		try {
			GIFLZWCompressor::decompress("\xC4\x01", 2);
			self::fail('A code beyond the dictionary is rejected.');
		} catch (\RuntimeException $e) {
			self::assertInstanceOf(\UnexpectedValueException::class, $e);
		}
	}

	public function testDecodesGdProducedImageData()
	{
		if (!extension_loaded('gd')) {
			self::markTestSkipped('The gd extension is not available.');
		}
		$im = imagecreate(63, 47);
		for ($i = 0; $i < 7; $i++) {
			imagecolorallocate($im, $i * 30, 255 - $i * 25, $i * 11);
		}
		for ($y = 0; $y < 47; $y++) {
			for ($x = 0; $x < 63; $x++) {
				imagesetpixel($im, $x, $y, ($x * 7 + $y * 3) % 7);
			}
		}
		ob_start();
		imagegif($im);
		[$w, $h, $mcs, $lzw] = $this->parseGif((string) ob_get_clean());

		$want = '';
		for ($y = 0; $y < $h; $y++) {
			for ($x = 0; $x < $w; $x++) {
				$want .= chr(imagecolorat($im, $x, $y));
			}
		}
		self::assertSame($want, GIFLZWCompressor::decompress($lzw, $mcs), 'The codec decodes a real GD-encoded image.');
	}

	public function testGdDecodesOurEncoding()
	{
		if (!extension_loaded('gd')) {
			self::markTestSkipped('The gd extension is not available.');
		}
		$w = 40;
		$h = 30;
		$indexes = '';
		for ($y = 0; $y < $h; $y++) {
			for ($x = 0; $x < $w; $x++) {
				$indexes .= chr(($x + $y) % 4);
			}
		}
		$gif = 'GIF89a' . pack('v', $w) . pack('v', $h) . chr(0x80 | 0x01) . chr(0) . chr(0)
			. "\x00\x00\x00\xFF\x00\x00\x00\xFF\x00\xFF\xFF\x00"                          // a 4-color table
			. "\x2C" . pack('v', 0) . pack('v', 0) . pack('v', $w) . pack('v', $h) . chr(0)
			. chr(2);
		foreach (str_split(GIFLZWCompressor::compress($indexes, 2), 255) as $block) {
			$gif .= chr(strlen($block)) . $block;
		}
		$gif .= "\x00\x3B";

		$im = imagecreatefromstring($gif);
		self::assertNotFalse($im, 'GD accepts the composed GIF.');
		for ($y = 0; $y < $h; $y++) {
			for ($x = 0; $x < $w; $x++) {
				self::assertSame(($x + $y) % 4, imagecolorat($im, $x, $y), "pixel {$x},{$y}");
			}
		}
	}

	/**
	 * Extracts the first image block from a GIF: width, height, LZW minimum code size, and
	 * the de-blocked LZW bytes.
	 * @param string $gif The GIF file bytes.
	 * @return array{0: int, 1: int, 2: int, 3: string} Width, height, min code size, LZW data.
	 */
	private function parseGif(string $gif): array
	{
		$p = 6;
		$w = unpack('v', $gif, $p)[1];
		$h = unpack('v', $gif, $p + 2)[1];
		$flags = ord($gif[$p + 4]);
		$p += 7;
		if ($flags & 0x80) {
			$p += 3 * (2 << ($flags & 0x07));
		}
		while (true) {
			$b = ord($gif[$p]);
			if ($b === 0x21) {                       // an extension block: label + sub-blocks
				$p += 2;
				while (($n = ord($gif[$p])) !== 0) {
					$p += 1 + $n;
				}
				$p++;
				continue;
			}
			if ($b === 0x2C) {                       // the image descriptor
				$iflags = ord($gif[$p + 9]);
				$p += 10;
				if ($iflags & 0x80) {
					$p += 3 * (2 << ($iflags & 0x07));
				}
				$mcs = ord($gif[$p++]);
				$lzw = '';
				while (($n = ord($gif[$p])) !== 0) {
					$lzw .= substr($gif, $p + 1, $n);
					$p += 1 + $n;
				}
				return [$w, $h, $mcs, $lzw];
			}
			self::fail('No image block found in the GIF.');
		}
	}
}
