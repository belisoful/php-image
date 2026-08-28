<?php

use Belisoful\Image\Compression\CCITTFaxCompressor;

class CCITTFaxCompressorTest extends PHPUnit\Framework\TestCase
{
	/**
	 * Packs rows of '0'/'1' strings (1 = black) into MSB-first row bytes.
	 * @param array $rows
	 */
	private function packBits(array $rows): string
	{
		$out = '';
		foreach ($rows as $row) {
			$bytes = str_repeat("\0", intdiv(strlen($row) + 7, 8));
			foreach (str_split($row) as $x => $bit) {
				if ($bit === '1') {
					$bytes[$x >> 3] = chr(ord($bytes[$x >> 3]) | (0x80 >> ($x & 7)));
				}
			}
			$out .= $bytes;
		}
		return $out;
	}

	private function sampleRows(int $columns, int $rows): array
	{
		$out = [];
		for ($y = 0; $y < $rows; $y++) {
			$row = '';
			for ($x = 0; $x < $columns; $x++) {
				// Text-like content: black bars, diagonals, and speckles.
				$row .= ($x > 3 && $x < 12) || (($x + $y) % 17 === 0) || ($y === 2 && $x % 2 === 0) ? '1' : '0';
			}
			$out[] = $row;
		}
		return $out;
	}

	public function testRoundTripAllEncodeModes()
	{
		foreach ([CCITTFaxCompressor::ModifiedHuffman, CCITTFaxCompressor::Group3, CCITTFaxCompressor::Group4] as $mode) {
			foreach ([[64, 8], [61, 5], [200, 12]] as [$columns, $rows]) {
				$codec = new CCITTFaxCompressor($columns, $mode);
				$data = $this->packBits($this->sampleRows($columns, $rows));
				$decoded = $codec->decode($codec->encode($data));
				self::assertSame(bin2hex($data), bin2hex($decoded), "mode $mode {$columns}x{$rows}");
			}
		}
	}

	public function testAllWhiteAndAllBlackRows()
	{
		foreach ([CCITTFaxCompressor::ModifiedHuffman, CCITTFaxCompressor::Group3, CCITTFaxCompressor::Group4] as $mode) {
			$codec = new CCITTFaxCompressor(80, $mode);
			$data = $this->packBits([str_repeat('0', 80), str_repeat('1', 80), str_repeat('0', 80)]);
			self::assertSame(bin2hex($data), bin2hex($codec->decode($codec->encode($data))), "mode $mode");
		}
	}

	public function testLongRunsUseMakeupChains()
	{
		// Wider than 2560: forces chained make-up codes.
		$columns = 3000;
		$codec = new CCITTFaxCompressor($columns, CCITTFaxCompressor::ModifiedHuffman);
		$rows = [str_repeat('0', $columns), str_repeat('1', $columns)];
		$data = $this->packBits($rows);
		self::assertSame(bin2hex($data), bin2hex($codec->decode($codec->encode($data))));
	}

	public function testGroup4CompressionIsCompact()
	{
		// Repeating rows compress extremely well vertically in G4.
		$columns = 1728;
		$row = str_repeat('0', 100) . str_repeat('1', 200) . str_repeat('0', $columns - 300);
		$codec = new CCITTFaxCompressor($columns, CCITTFaxCompressor::Group4);
		$data = $this->packBits(array_fill(0, 64, $row));
		$encoded = $codec->encode($data);
		self::assertLessThan(strlen($data) / 50, strlen($encoded));
		self::assertSame(bin2hex($data), bin2hex($codec->decode($encoded)));
	}

	public function testKnownCodewords()
	{
		// Spec vectors (ITU-T T.4): one row of 64 columns, 63 white then 1 black.
		// White 63 = 00110100 (8 bits); black 1 = 010 (3 bits); MH pads the row to a byte.
		$codec = new CCITTFaxCompressor(64, CCITTFaxCompressor::ModifiedHuffman);
		$encoded = $codec->encode($this->packBits([str_repeat('0', 63) . '1']));
		self::assertSame("\x34\x40", $encoded);

		// An all-white 1728 row is the single 9-bit make-up 010011011 plus white 0 (00110101).
		$codec = new CCITTFaxCompressor(1728, CCITTFaxCompressor::ModifiedHuffman);
		$encoded = $codec->encode($this->packBits([str_repeat('0', 1728)]));
		self::assertSame("\x4D\x9A\x80", $encoded);
	}

	public function testGroup3StreamHasEols()
	{
		$codec = new CCITTFaxCompressor(64, CCITTFaxCompressor::Group3);
		$encoded = $codec->encode($this->packBits([str_repeat('0', 64)]));
		// Leads with the 12-bit EOL 000000000001.
		self::assertSame(0x00, ord($encoded[0]));
		self::assertSame(0x10, ord($encoded[1]) & 0xF0);
	}

	public function testRowLimitAndInvalidInput()
	{
		$codec = new CCITTFaxCompressor(64, CCITTFaxCompressor::Group4);
		$data = $this->packBits($this->sampleRows(64, 6));
		$encoded = $codec->encode($data);
		self::assertSame(substr($data, 0, 8 * 2), $codec->decode($encoded, 2));

		self::expectException(\InvalidArgumentException::class);
		new CCITTFaxCompressor(0);
	}

	public function testGroup3TwoDRoundTripAndTagBits()
	{
		$codec = new CCITTFaxCompressor(64, CCITTFaxCompressor::Group3TwoD);
		$data = $this->packBits($this->sampleRows(64, 10));
		$encoded = $codec->encode($data);
		self::assertSame(bin2hex($data), bin2hex($codec->decode($encoded)));

		// Bits 0-11 are the EOL and bit 12 is the tag bit, set for the first row's
		// one-dimensional coding.
		self::assertSame(0x00, ord($encoded[0]));
		self::assertSame(0x01, ord($encoded[1]) >> 4);      // the EOL's trailing 1
		self::assertSame(0x08, ord($encoded[1]) & 0x08);    // the 1D tag bit

		// Coding rows against their predecessor beats the one-dimensional Group 3 on a
		// repeating image (both still pay an EOL per row). Speckled content like
		// sampleRows() expands under any fax mode, so the gain is measured here.
		$repeating = $this->packBits(array_fill(0, 32, str_repeat('0', 20) . str_repeat('1', 24) . str_repeat('0', 20)));
		$twoD = strlen($codec->encode($repeating));
		$oneD = strlen((new CCITTFaxCompressor(64, CCITTFaxCompressor::Group3))->encode($repeating));
		self::assertLessThan($oneD, $twoD);
		self::assertLessThan(strlen($repeating) / 2, $twoD);
	}

	public function testUnknownModeIsRejected()
	{
		try {
			new CCITTFaxCompressor(64, CCITTFaxCompressor::Group4 + 1);
			self::fail('A coding mode outside the four codings is rejected.');
		} catch (\InvalidArgumentException $e) {
			self::assertInstanceOf(\InvalidArgumentException::class, $e);
		}
	}

	public function testEncodingPartialRowsIsRejected()
	{
		// 64 columns are eight bytes per row, so twelve bytes are a row and a half.
		$codec = new CCITTFaxCompressor(64, CCITTFaxCompressor::Group4);
		try {
			$codec->encode(str_repeat("\0", 12));
			self::fail('Data that is not whole rows is rejected.');
		} catch (\RuntimeException $e) {
			self::assertInstanceOf(\UnexpectedValueException::class, $e);
		}
	}

	public function testGroup3WithoutAnEolDecodesToNothing()
	{
		// All-ones bits never accumulate the eleven zeros an EOL needs, so the scan runs the
		// data out and the decode ends with no rows rather than inventing one.
		$codec = new CCITTFaxCompressor(64, CCITTFaxCompressor::Group3);
		self::assertSame('', $codec->decode("\xFF\xFF"));
	}

	public function testGroup3TwoDEndingOnAnEolDecodesToNothing()
	{
		// Fifteen zeros and a one end on an EOL with no tag bit behind it: the row is absent.
		$codec = new CCITTFaxCompressor(64, CCITTFaxCompressor::Group3TwoD);
		self::assertSame('', $codec->decode("\x00\x01"));
	}

	public function testVerticalModesTwoAndThreeCodeTheirDeltas()
	{
		// Each row's black edge steps two then three pixels right of the row above — exactly
		// the deltas coded by VR2 (000011) and VR3 (0000011).
		$codec = new CCITTFaxCompressor(64, CCITTFaxCompressor::Group4);
		$data = $this->packBits([
			str_repeat('0', 10) . str_repeat('1', 54),
			str_repeat('0', 12) . str_repeat('1', 52),
			str_repeat('0', 15) . str_repeat('1', 49),
		]);
		$encoded = $codec->encode($data);

		// Row 1 is horizontal (001) + white 10 (00111) + black 54 (000000111000); rows 2 and 3
		// are VR2 + V0 and VR3 + V0; the EOFB is two EOLs.
		self::assertSame('270380e0e0020020', bin2hex($encoded));
		self::assertSame(bin2hex($data), bin2hex($codec->decode($encoded)));
	}

	public function testTrailingZeroFillEndsAOneDimensionalDecode()
	{
		// Fifteen or more zero bits are a fill region, not a code word: the rows before the
		// fill still decode and no exception is raised.
		$codec = new CCITTFaxCompressor(64, CCITTFaxCompressor::ModifiedHuffman);
		$data = $this->packBits([
			str_repeat('0', 30) . str_repeat('1', 34),
			str_repeat('1', 20) . str_repeat('0', 44),
		]);
		$encoded = $codec->encode($data);
		self::assertSame(bin2hex($data), bin2hex($codec->decode($encoded . "\x00\x00\x00")));
	}

	public function testUnmatchedRunCodeThrows()
	{
		// Thirteen zeros then two ones are fifteen bits matching no white run code, and the
		// non-zero code word rules out a fill region.
		$codec = new CCITTFaxCompressor(64, CCITTFaxCompressor::ModifiedHuffman);
		try {
			$codec->decode("\x00\x06");
			self::fail('An unmatched run code word is rejected.');
		} catch (\RuntimeException $e) {
			self::assertInstanceOf(\UnexpectedValueException::class, $e);
		}
	}

	public function testTruncatedHorizontalRunEndsTheDecode()
	{
		// A horizontal mode code (001) with no complete run behind it: the truncated row is
		// dropped rather than decoded from missing bits.
		$codec = new CCITTFaxCompressor(64, CCITTFaxCompressor::Group4);
		self::assertSame('', $codec->decode("\x20"));
	}

	public function testTruncatedOneDimensionalRowKeepsItsDecodedRuns()
	{
		// The white run 30 (00000011) is a whole code word, and the data ends inside the black
		// run behind it: the row keeps the run it did decode and the rest of the row is the
		// colour that run left, rather than the whole row being dropped.
		$codec = new CCITTFaxCompressor(64, CCITTFaxCompressor::ModifiedHuffman);
		$expected = $this->packBits([str_repeat('0', 30) . str_repeat('1', 34)]);
		self::assertSame(bin2hex($expected), bin2hex($codec->decode("\x03")));
	}

	public function testTruncatedTwoDimensionalRowKeepsItsDecodedChanges()
	{
		// VL1 (010) places the row's single change one pixel left of the reference edge, then
		// the data runs out mid-row: the change already read is kept as the row.
		$codec = new CCITTFaxCompressor(64, CCITTFaxCompressor::Group4);
		$expected = $this->packBits([str_repeat('0', 63) . '1']);
		self::assertSame(bin2hex($expected), bin2hex($codec->decode("\x40")));
	}

	public function testTwoDimensionalRowEndingOnAnEolKeepsItsChanges()
	{
		// VL1 (010), an EOL (000000000001), then a second VL1: the EOL ends the first row at
		// the change it had read instead of discarding it, and decoding carries on into the
		// next row, which codes its own change against the first row as its reference.
		$codec = new CCITTFaxCompressor(64, CCITTFaxCompressor::Group4);
		$expected = $this->packBits([
			str_repeat('0', 63) . '1',
			str_repeat('0', 62) . '11',
		]);
		self::assertSame(bin2hex($expected), bin2hex($codec->decode("\x40\x02\x80")));
	}

	public function testTruncatedHorizontalRunKeepsEarlierChanges()
	{
		// VL1 (010) then a horizontal code (001) whose two runs are missing: the row keeps the
		// change the vertical mode had already placed.
		$codec = new CCITTFaxCompressor(64, CCITTFaxCompressor::Group4);
		$expected = $this->packBits([str_repeat('0', 63) . '1']);
		self::assertSame(bin2hex($expected), bin2hex($codec->decode("\x44")));
	}

	public function testUnknownTwoDimensionalModeCodeThrows()
	{
		// Twelve zero bits are neither a mode code nor the EOL (000000000001).
		$codec = new CCITTFaxCompressor(64, CCITTFaxCompressor::Group4);
		try {
			$codec->decode("\x00\x00");
			self::fail('An unmatched two-dimensional mode code is rejected.');
		} catch (\RuntimeException $e) {
			self::assertInstanceOf(\UnexpectedValueException::class, $e);
		}
	}
}
