<?php

use Belisoful\Image\Compression\LZWCompressor;

class LZWCompressorTest extends PHPUnit\Framework\TestCase
{
	private function roundTrip(string $data): void
	{
		$encoded = LZWCompressor::compress($data);
		self::assertSame($data, LZWCompressor::decompress($encoded), 'round-trip for ' . strlen($data) . ' bytes');
	}

	public function testEmpty()
	{
		$this->roundTrip('');
	}

	public function testSingleByte()
	{
		$this->roundTrip('A');
	}

	public function testShortText()
	{
		$this->roundTrip('TOBEORNOTTOBEORTOBEORNOT');
	}

	public function testRepetitive()
	{
		$this->roundTrip(str_repeat('AB', 1000));
	}

	public function testCrossesNineToTenBits()
	{
		// enough distinct sequences to grow past 9 bits
		$this->roundTrip(str_repeat('abcdefghijklmnopqrstuvwxyz0123456789', 50));
	}

	public function testCrossesAllWidthsAndClears()
	{
		// 64 KB of pseudo-random + structured data forces 9->10->11->12 growth and clears
		$data = '';
		for ($i = 0; $i < 8192; $i++) {
			$data .= pack('N', $i * 2654435761 & 0xFFFFFFFF);
		}
		$this->roundTrip($data);
	}

	public function testRandomBytes()
	{
		$this->roundTrip(PseudoRandomBytes::bytes(20000, 'lzw-1'));
	}

	public function testAllByteValues()
	{
		$data = '';
		for ($i = 0; $i < 256; $i++) {
			$data .= chr($i);
		}
		$this->roundTrip(str_repeat($data, 40));
	}

	public function testCompressesRepetitiveData()
	{
		$data = str_repeat('PRADO', 2000);
		self::assertLessThan(strlen($data), strlen(LZWCompressor::compress($data)));
	}

	public function testInvalidCodeAfterAFirstSymbolThrows()
	{
		// 9-bit codes MSB-first: Clear (256), 'A' (65), then 300 - beyond the dictionary (next is 258).
		$bits = str_pad(decbin(256), 9, '0', STR_PAD_LEFT)
			. str_pad(decbin(65), 9, '0', STR_PAD_LEFT)
			. str_pad(decbin(300), 9, '0', STR_PAD_LEFT);
		$bytes = '';
		foreach (str_split(str_pad($bits, (int) ceil(strlen($bits) / 8) * 8, '0'), 8) as $octet) {
			$bytes .= chr(bindec($octet));
		}
		$this->expectException(\RuntimeException::class);
		LZWCompressor::decompress($bytes);
	}

	public function testInvalidFirstCodeThrows()
	{
		// Clear (256) then 258 as the very first data code; no dictionary entry can exist yet.
		$bits = str_pad(decbin(256), 9, '0', STR_PAD_LEFT) . str_pad(decbin(258), 9, '0', STR_PAD_LEFT);
		$bytes = '';
		foreach (str_split(str_pad($bits, 24, '0'), 8) as $octet) {
			$bytes .= chr(bindec($octet));
		}
		$this->expectException(\RuntimeException::class);
		LZWCompressor::decompress($bytes);
	}
}
