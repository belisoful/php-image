<?php

use Belisoful\Image\Util\BitReader;
use Belisoful\Image\Util\BitWriter;

/**
 * Unit tests for {@see \Belisoful\Image\Util\BitWriter} and
 * {@see \Belisoful\Image\Util\BitReader}, the string-backed bit IO behind the LZW and
 * CCITT fax codecs.  Fields are unsigned and most-significant-bit first.
 */
class BitIOTest extends PHPUnit\Framework\TestCase
{
	public function testWriteAndReadAcrossByteBoundaries()
	{
		$w = new BitWriter();
		$w->writeBits(0xA, 4);
		$w->writeBits(0xBCD, 12);
		self::assertSame("\xAB\xCD", $w->getBytes());

		$r = new BitReader("\xAB\xCD");
		self::assertSame(0xA, $r->readBits(4));
		self::assertSame(0xBCD, $r->readBits(12));
	}

	public function testWholeByteRoundTrip()
	{
		$w = new BitWriter();
		foreach ([0x00, 0x7F, 0x80, 0xFF] as $byte) {
			$w->writeBits($byte, 8);
		}
		self::assertSame("\x00\x7F\x80\xFF", $w->getBytes());

		$r = new BitReader($w->getBytes());
		foreach ([0x00, 0x7F, 0x80, 0xFF] as $byte) {
			self::assertSame($byte, $r->readBits(8));
		}
	}

	public function testWideFieldRoundTrip()
	{
		$w = new BitWriter();
		$w->writeBits(0x0123456789ABCDEF, 64);
		self::assertSame(0x0123456789ABCDEF, (new BitReader($w->getBytes()))->readBits(64));
	}

	public function testFullWidthTopBitReadsBackAsTheRawPattern()
	{
		// PHP has no unsigned int, so a full-width field with the top bit set comes back
		// negative, carrying the same bit pattern.
		$w = new BitWriter();
		$w->writeBits(-1, 64);
		self::assertSame(str_repeat("\xFF", 8), $w->getBytes());
		self::assertSame(-1, (new BitReader($w->getBytes()))->readBits(64));
	}

	public function testOnlyTheLowBitsOfAValueAreWritten()
	{
		$w = new BitWriter();
		$w->writeBits(0xFF, 4);     // truncated to 0xF
		$w->writeBits(-1, 4);       // two's-complement low bits, also 0xF
		self::assertSame("\xFF", $w->getBytes());
	}

	public function testZeroWidthIsANoOp()
	{
		$w = new BitWriter();
		$w->writeBits(0xFF, 0);
		self::assertSame('', $w->getBytes());
		self::assertSame(0, $w->getCurrentBitIndex());
		self::assertSame(0, (new BitReader("\xFF"))->readBits(0));
	}

	public function testFlushPadsTheTrailingByteWithZeros()
	{
		$w = new BitWriter();
		$w->writeBits(0b101, 3);
		self::assertSame('', $w->getBytes(), 'Pending bits are not emitted until flushed.');
		self::assertSame(1, $w->flush());
		self::assertSame(chr(0b10100000), $w->getBytes());
		self::assertSame(8, $w->getCurrentBitIndex(), 'The padding counts toward the bit index.');
		self::assertSame(0, $w->flush(), 'A second flush has nothing pending.');
	}

	public function testWriterAlign()
	{
		$w = new BitWriter();
		$w->writeBits(0b1, 1);
		$w->align();
		self::assertSame(8, $w->getCurrentBitIndex());
		self::assertSame(chr(0b10000000), $w->getBytes());

		$w->align();
		self::assertSame(8, $w->getCurrentBitIndex(), 'Already on the boundary writes nothing.');

		$w->align(0);
		self::assertSame(8, $w->getCurrentBitIndex(), 'An alignment below 1 writes nothing.');
	}

	public function testWriterAlignToAWideBoundary()
	{
		$w = new BitWriter();
		$w->writeBits(0b1, 1);
		$w->align(128);   // wider than one pass, so the loop runs more than once
		self::assertSame(128, $w->getCurrentBitIndex());
		self::assertSame(16, strlen($w->getBytes()));
	}

	public function testReaderAlign()
	{
		$r = new BitReader("\xFF\xAB");
		$r->readBits(1);
		self::assertTrue($r->align());
		self::assertSame(8, $r->getCurrentBitIndex());
		self::assertSame(0xAB, $r->readBits(8));

		self::assertTrue($r->align(), 'Already on the boundary reads nothing.');
		self::assertFalse($r->align(0), 'An alignment below 1 reports false.');
	}

	public function testReaderAlignReportsFalseWhenTheDataEndsFirst()
	{
		$r = new BitReader("\xFF");
		$r->readBits(4);
		self::assertFalse($r->align(128), 'The data ends before the boundary is reached.');
	}

	public function testReadPastTheEndReturnsFalseAndKeepsThePosition()
	{
		$r = new BitReader("\xAB");
		self::assertSame(0xA, $r->readBits(4));
		self::assertFalse($r->readBits(12), 'Not enough bits remain.');
		self::assertSame(4, $r->getCurrentBitIndex(), 'The failed read consumed nothing.');
		self::assertSame(0xB, $r->readBits(4), 'The remaining bits are still readable.');
		self::assertFalse($r->readBits(1), 'Now genuinely exhausted.');
	}

	public function testEmptyReaderIsImmediatelyExhausted()
	{
		self::assertFalse((new BitReader())->readBits(1));
	}

	/**
	 * @dataProvider badWidthProvider
	 * @param int $numBits
	 */
	public function testWriterRejectsAnOutOfRangeWidth(int $numBits)
	{
		self::expectException(\InvalidArgumentException::class);
		(new BitWriter())->writeBits(0, $numBits);
	}

	/**
	 * @dataProvider badWidthProvider
	 * @param int $numBits
	 */
	public function testReaderRejectsAnOutOfRangeWidth(int $numBits)
	{
		self::expectException(\InvalidArgumentException::class);
		(new BitReader("\xFF"))->readBits($numBits);
	}

	public static function badWidthProvider(): array
	{
		return [
			'negative' => [-1],
			'wider than the platform integer' => [PHP_INT_SIZE * 8 + 1],
		];
	}
}
