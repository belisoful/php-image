<?php

use Belisoful\Image\Compression\PackBitsCompressor;

class PackBitsCompressorTest extends PHPUnit\Framework\TestCase
{
	public function testEmpty()
	{
		self::assertSame('', PackBitsCompressor::compress(''));
		self::assertSame('', PackBitsCompressor::decompress(''));
	}

	public function testKnownRepeat()
	{
		// 3x 'A' -> header (257-3)=254 then 'A'
		self::assertSame(chr(254) . 'A', PackBitsCompressor::compress('AAA'));
		self::assertSame('AAA', PackBitsCompressor::decompress(chr(254) . 'A'));
	}

	public function testKnownLiteral()
	{
		self::assertSame(chr(2) . 'ABC', PackBitsCompressor::compress('ABC'));
		self::assertSame('ABC', PackBitsCompressor::decompress(chr(2) . 'ABC'));
	}

	public function testNoOpHeaderSkipped()
	{
		self::assertSame('', PackBitsCompressor::decompress(chr(128)));
	}

	public function testRoundTripMixed()
	{
		$data = 'AAAAABCDEEEEEEFG' . str_repeat('Z', 300) . PseudoRandomBytes::bytes(500, 'packbits-1');
		self::assertSame($data, PackBitsCompressor::decompress(PackBitsCompressor::compress($data)));
	}

	public function testRoundTripAllBytes()
	{
		$data = '';
		for ($i = 0; $i < 256; $i++) {
			$data .= str_repeat(chr($i), $i % 5 + 1);
		}
		self::assertSame($data, PackBitsCompressor::decompress(PackBitsCompressor::compress($data)));
	}

	public function testRunLongerThanMaxSplits()
	{
		$data = str_repeat('Q', 300);   // > 128, must split into multiple repeat packets
		self::assertSame($data, PackBitsCompressor::decompress(PackBitsCompressor::compress($data)));
	}
}
