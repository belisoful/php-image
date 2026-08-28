<?php

use Belisoful\Image\Compression\HorizontalPredictor;
use Belisoful\Image\Compression\HorizontalPredictorDecoder;
use Belisoful\Image\Compression\HorizontalPredictorEncoder;
use Belisoful\Image\Compression\StreamCodec;
use Belisoful\Image\Compression\LZWCompressor;

class HorizontalPredictorTest extends PHPUnit\Framework\TestCase
{
	public function testKnownSingleSampleVector()
	{
		// 'ABCD' differences to A, B-A, C-B, D-C.
		self::assertSame("\x41\x01\x01\x01", HorizontalPredictor::encode('ABCD', 4));
		self::assertSame('ABCD', HorizontalPredictor::decode("\x41\x01\x01\x01", 4));
	}

	public function testThreeSampleChannelsDifferencePerChannel()
	{
		// Two RGB pixels: (10,20,30) then (13,19,30) -> second pixel differences per channel.
		$raw = chr(10) . chr(20) . chr(30) . chr(13) . chr(19) . chr(30);
		$expected = chr(10) . chr(20) . chr(30) . chr(3) . chr(255) . chr(0);
		self::assertSame($expected, HorizontalPredictor::encode($raw, 2, 3));
		self::assertSame($raw, HorizontalPredictor::decode($expected, 2, 3));
	}

	public function testEachRowRestartsThePrediction()
	{
		// Two rows of two bytes: the third byte is a row start, so it stays verbatim.
		$raw = chr(100) . chr(101) . chr(200) . chr(201);
		$encoded = HorizontalPredictor::encode($raw, 2, 1);
		self::assertSame(chr(100) . chr(1) . chr(200) . chr(1), $encoded);
		self::assertSame($raw, HorizontalPredictor::decode($encoded, 2, 1));
	}

	public function testWrapAroundStaysModulo256()
	{
		$raw = chr(5) . chr(250);   // 250 - 5 = 245; 5 - 250 wraps
		$encoded = HorizontalPredictor::encode($raw . chr(5), 3, 1);
		self::assertSame(chr(5) . chr(245) . chr(11), $encoded);
		self::assertSame($raw . chr(5), HorizontalPredictor::decode($encoded, 3, 1));
	}

	public function testPartialTrailingRowTransforms()
	{
		$raw = 'ABCDEF' . 'GH';   // a 6-byte row then a 2-byte partial row
		$encoded = HorizontalPredictor::encode($raw, 6, 1);
		self::assertSame($raw, HorizontalPredictor::decode($encoded, 6, 1));
		self::assertSame('G', $encoded[6], 'The partial row restarts verbatim.');
	}

	public function testRandomRoundTrips()
	{
		foreach ([[64, 1], [64, 3], [17, 4]] as [$columns, $samples]) {
			$raw = PseudoRandomBytes::bytes($columns * $samples * 20 + 5, 'predictor-1');   // 20 rows and a partial
			$encoded = HorizontalPredictor::encode($raw, $columns, $samples);
			self::assertSame($raw, HorizontalPredictor::decode($encoded, $columns, $samples), "columns={$columns} samples={$samples}");
		}
	}

	public function testGeometryValidation()
	{
		$this->expectException(\RuntimeException::class);
		HorizontalPredictor::encode('data', 0);
	}

	public function testPredictionImprovesLzwCompression()
	{
		// A smooth gradient with a per-row shift: raw rows never repeat, while the differenced
		// bytes collapse to near-constant runs, so the predictor must win under LZW.
		$image = '';
		for ($y = 0; $y < 32; $y++) {
			for ($x = 0; $x < 256; $x++) {
				$image .= chr(($x + $y * 3) & 0xFF);
			}
		}
		$rawPacked = LZWCompressor::compress($image);
		$predictedPacked = LZWCompressor::compress(HorizontalPredictor::encode($image, 256));
		self::assertLessThan(strlen($rawPacked), strlen($predictedPacked), 'Differencing shrinks the LZW output on a gradient.');
	}

	// ---- The incremental engine -------------------------------------------------

	/**
	 * Drives a {@see StreamCodec} $chunk bytes at a time, so its incremental (unbuffered)
	 * state is exercised across chunk boundaries.
	 * @param StreamCodec $codec
	 * @param string $data
	 * @param int $chunk
	 */
	private function runCodec(StreamCodec $codec, string $data, int $chunk): string
	{
		$out = '';
		foreach (str_split($data ?: ' ', $chunk) as $piece) {
			if ($data === '') {
				break;
			}
			$out .= $codec->add($piece);
		}
		return $out . $codec->finish();
	}

	public function testEngineMatchesCodecAcrossChunkSizes()
	{
		$columns = 31;
		$samples = 3;
		$raw = PseudoRandomBytes::bytes($columns * $samples * 15 + 7, 'predictor-2');   // 15 rows and a partial tail
		$expected = HorizontalPredictor::encode($raw, $columns, $samples);
		foreach ([1, 7, 64, 8192] as $chunk) {
			$encoded = $this->runCodec(HorizontalPredictor::encoder($columns, $samples), $raw, $chunk);
			self::assertSame($expected, $encoded, "encode chunk={$chunk}");
			$decoded = $this->runCodec(HorizontalPredictor::decoder($columns, $samples), $encoded, $chunk);
			self::assertSame($raw, $decoded, "decode chunk={$chunk}");
		}
	}

	public function testEngineSamplesDefaultsToOne()
	{
		$raw = PseudoRandomBytes::bytes(64 * 10, 'predictor-3');
		$expected = HorizontalPredictor::encode($raw, 64, 1);
		self::assertSame($expected, $this->runCodec(HorizontalPredictor::encoder(64), $raw, 128));
	}

	public function testEngineRejectsNonPositiveGeometry()
	{
		self::expectException(\RuntimeException::class);
		HorizontalPredictor::encoder(0);
	}

	public function testEngineRejectsNonPositiveSamples()
	{
		self::expectException(\RuntimeException::class);
		new HorizontalPredictorDecoder(16, 0);
	}

	public function testEngineCarriesInputShorterThanARowToTheClose()
	{
		// Less than one row arrives, so no chunk completes a row and everything transforms in
		// finish(); the result still matches the whole-string partial-row handling.
		$raw = PseudoRandomBytes::bytes(10, 'predictor-5');
		$expected = HorizontalPredictor::encode($raw, 100, 1);
		self::assertSame($expected, $this->runCodec(HorizontalPredictor::encoder(100), $raw, 8192));
		self::assertSame($raw, $this->runCodec(HorizontalPredictor::decoder(100), $expected, 8192));
	}

	public function testEncoderAndDecoderTypes()
	{
		self::assertInstanceOf(HorizontalPredictorEncoder::class, HorizontalPredictor::encoder(4));
		self::assertInstanceOf(HorizontalPredictorDecoder::class, HorizontalPredictor::decoder(4));
	}
}
