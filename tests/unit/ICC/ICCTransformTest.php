<?php

use Belisoful\Image\ICC\ICCProfile;
use Belisoful\Image\ICC\ICCTransform;

class ICCTransformTest extends PHPUnit\Framework\TestCase
{
	private function sRgb(): ICCProfile
	{
		return ICCProfile::parse(ICCProfileBuilder::sRgb());
	}

	private function wide(): ICCProfile
	{
		return ICCProfile::parse(ICCProfileBuilder::wideGamut());
	}

	/**
	 * Packs pixels given as [r, g, b] triples.
	 * @param array $pixels
	 */
	private function pack(array $pixels): string
	{
		$out = '';
		foreach ($pixels as $pixel) {
			$out .= chr($pixel[0]) . chr($pixel[1]) . chr($pixel[2]);
		}
		return $out;
	}

	/**
	 * Unpacks RGB24 bytes back into [r, g, b] triples.
	 * @param string $rgb
	 */
	private function unpack(string $rgb): array
	{
		$pixels = [];
		for ($i = 0, $count = intdiv(strlen($rgb), 3); $i < $count; $i++) {
			$pixels[] = [ord($rgb[$i * 3]), ord($rgb[$i * 3 + 1]), ord($rgb[$i * 3 + 2])];
		}
		return $pixels;
	}

	public function testSupportsOnlyMatrixShaperProfiles()
	{
		$sRgb = $this->sRgb();
		$lut = ICCProfile::parse(ICCProfileBuilder::cmykLut());

		self::assertTrue(ICCTransform::supports($sRgb, $this->wide()));
		self::assertFalse(ICCTransform::supports($sRgb, $lut));
		self::assertFalse(ICCTransform::supports($lut, $sRgb));

		// A lookup-table profile yields no transform at all rather than wrong color.
		self::assertNull(ICCTransform::between($sRgb, $lut));
		self::assertNull(ICCTransform::between($lut, $sRgb));
		self::assertInstanceOf(ICCTransform::class, ICCTransform::between($sRgb, $this->wide()));
	}

	public function testSameProfileIsAnIdentity()
	{
		$transform = ICCTransform::between($this->sRgb(), $this->sRgb());
		self::assertTrue($transform->getIsIdentity());

		// Every 8-bit level survives a transform to its own space.
		$ramp = '';
		for ($value = 0; $value < 256; $value++) {
			$ramp .= chr($value) . chr(255 - $value) . chr(($value * 7) % 256);
		}
		self::assertSame(bin2hex($ramp), bin2hex($transform->rgbPixels($ramp)));

		self::assertFalse(ICCTransform::between($this->sRgb(), $this->wide())->getIsIdentity());
	}

	public function testBlackWhiteAndNeutralsArePreserved()
	{
		$transform = ICCTransform::between($this->sRgb(), $this->wide());

		// The endpoints are exact, and a neutral stays neutral: the strongest signal that
		// the matrix and its inverse agree.
		$converted = $this->unpack($transform->rgbPixels($this->pack([
			[0, 0, 0], [255, 255, 255], [128, 128, 128], [64, 64, 64], [200, 200, 200],
		])));
		self::assertSame([0, 0, 0], $converted[0]);
		self::assertSame([255, 255, 255], $converted[1]);
		foreach ([2, 3, 4] as $index) {
			self::assertSame($converted[$index][0], $converted[$index][1], "pixel $index stays neutral");
			self::assertSame($converted[$index][1], $converted[$index][2], "pixel $index stays neutral");
		}
	}

	public function testPrimariesMoveTowardTheWiderGamut()
	{
		$transform = ICCTransform::between($this->sRgb(), $this->wide());
		$converted = $this->unpack($transform->rgbPixels($this->pack([[255, 0, 0], [0, 255, 0], [0, 0, 255]])));

		// The published Adobe RGB (1998) encodings of the three sRGB primaries. Red and
		// blue sit inside the wider gamut, so they need less of their own primary; sRGB
		// green is brighter than Adobe's green primary alone, so it needs all of the green
		// plus some red and blue.
		self::assertEqualsWithDelta([219, 0, 0], $converted[0], 2);
		self::assertEqualsWithDelta([144, 255, 60], $converted[1], 2);
		self::assertEqualsWithDelta([0, 0, 250], $converted[2], 2);
	}

	public function testRoundTripThroughBothDirections()
	{
		$there = ICCTransform::between($this->sRgb(), $this->wide());
		$back = ICCTransform::between($this->wide(), $this->sRgb());

		$original = $this->pack([
			[0, 0, 0], [255, 255, 255], [128, 128, 128], [255, 0, 0], [0, 128, 255],
			[17, 200, 90], [240, 120, 30], [5, 5, 250],
		]);
		$returned = $this->unpack($back->rgbPixels($there->rgbPixels($original)));

		foreach ($this->unpack($original) as $index => $pixel) {
			foreach ($pixel as $channel => $value) {
				// Two 8-bit quantizations stand between the two conversions, and the dark
				// end is where the two curve shapes disagree most: sRGB has a linear toe
				// where Adobe RGB is a pure 2.2 gamma, so a step there is worth several.
				self::assertEqualsWithDelta($value, $returned[$index][$channel], 4, "pixel $index channel $channel");
			}
		}
	}

	public function testCurveFormsAllEvaluate()
	{
		// A gamma curve, a sampled table, and each parametric function type reach the
		// endpoints they must, whichever form the profile stores.
		$primaries = [
			'wtpt' => ICCProfileBuilder::xyzTag(0.9642, 1.0, 0.8249),
			'rXYZ' => ICCProfileBuilder::xyzTag(0.4360, 0.2225, 0.0139),
			'gXYZ' => ICCProfileBuilder::xyzTag(0.3851, 0.7169, 0.0971),
			'bXYZ' => ICCProfileBuilder::xyzTag(0.1431, 0.0606, 0.7141),
		];
		$samples = [];
		for ($i = 0; $i < 32; $i++) {
			$samples[] = ($i / 31) ** 2.2;
		}
		$curves = [
			'identity' => ICCProfileBuilder::curvIdentity(),
			'gamma' => ICCProfileBuilder::curvGamma(2.2),
			'table' => ICCProfileBuilder::curvTable($samples),
			'para0' => ICCProfileBuilder::paraCurve(0, [2.2]),
			'para1' => ICCProfileBuilder::paraCurve(1, [2.2, 1.0, 0.0]),
			'para2' => ICCProfileBuilder::paraCurve(2, [2.2, 1.0, 0.0, 0.0]),
			'para3' => ICCProfileBuilder::paraCurve(3, [2.4, 1 / 1.055, 0.055 / 1.055, 1 / 12.92, 0.04045]),
			'para4' => ICCProfileBuilder::paraCurve(4, [2.4, 1 / 1.055, 0.055 / 1.055, 1 / 12.92, 0.04045, 0.0, 0.0]),
		];
		foreach ($curves as $name => $curve) {
			$profile = ICCProfile::parse(ICCProfileBuilder::build(
				$primaries + ['rTRC' => $curve, 'gTRC' => $curve, 'bTRC' => $curve],
			));
			self::assertTrue($profile->getIsMatrixShaper(), $name);

			$transform = ICCTransform::between($profile, $profile);
			self::assertInstanceOf(ICCTransform::class, $transform, $name);
			$converted = $this->unpack($transform->rgbPixels($this->pack([[0, 0, 0], [255, 255, 255], [128, 128, 128]])));
			self::assertSame([0, 0, 0], $converted[0], $name);
			self::assertSame([255, 255, 255], $converted[1], $name);
			self::assertEqualsWithDelta(128, $converted[2][0], 1, $name);

			// And the curve is invertible against a differently shaped space.
			$crossed = ICCTransform::between($profile, $this->wide());
			self::assertInstanceOf(ICCTransform::class, $crossed, $name);
			$ends = $this->unpack($crossed->rgbPixels($this->pack([[0, 0, 0], [255, 255, 255]])));
			self::assertSame([0, 0, 0], $ends[0], $name);
			self::assertSame([255, 255, 255], $ends[1], $name);
		}
	}

	public function testParametricLinearSegmentAndClamping()
	{
		// Type 1 and 2 go to zero (or to the offset c) below -b/a, where the power
		// function has no real value.
		// Each case is [curve, the sRGB encoding of its black, and of its white].
		$cases = [
			'below-zero' => [ICCProfileBuilder::paraCurve(1, [2.2, 1.0, -0.5]), 0, 128],
			'offset' => [ICCProfileBuilder::paraCurve(2, [2.2, 1.0, -0.5, 0.1]), 89, 153],
		];
		$primaries = [
			'wtpt' => ICCProfileBuilder::xyzTag(0.9642, 1.0, 0.8249),
			'rXYZ' => ICCProfileBuilder::xyzTag(0.4360, 0.2225, 0.0139),
			'gXYZ' => ICCProfileBuilder::xyzTag(0.3851, 0.7169, 0.0971),
			'bXYZ' => ICCProfileBuilder::xyzTag(0.1431, 0.0606, 0.7141),
		];
		foreach ($cases as $name => [$curve, $black, $white]) {
			$profile = ICCProfile::parse(ICCProfileBuilder::build(
				$primaries + ['rTRC' => $curve, 'gTRC' => $curve, 'bTRC' => $curve],
			));
			$transform = ICCTransform::between($profile, $this->sRgb());
			self::assertInstanceOf(ICCTransform::class, $transform, $name);

			// A dark input lands in the flat region, so it must not blow up or wrap.
			$converted = $this->unpack($transform->rgbPixels($this->pack([[0, 0, 0], [10, 10, 10], [255, 255, 255]])));
			foreach ($converted as $pixel) {
				foreach ($pixel as $value) {
					self::assertGreaterThanOrEqual(0, $value, $name);
					self::assertLessThanOrEqual(255, $value, $name);
				}
			}
			// Neither curve reaches the connection space's endpoints: type 1 floors at
			// zero below -b/a while type 2 floors at its offset c, and both cap well under
			// white because a = 1 with b = -0.5. The transform must carry that faithfully
			// rather than stretch it back to black and white.
			self::assertEqualsWithDelta($black, $converted[0][0], 2, $name);
			self::assertEqualsWithDelta($black, $converted[1][0], 2, $name);
			self::assertEqualsWithDelta($white, $converted[2][0], 2, $name);
			self::assertLessThanOrEqual($converted[2][0], $converted[1][0], $name);
		}
	}

	public function testSingularMatrixYieldsNoTransform()
	{
		// Three identical colorants cannot be inverted.
		$degenerate = ICCProfile::parse(ICCProfileBuilder::build([
			'wtpt' => ICCProfileBuilder::xyzTag(0.9642, 1.0, 0.8249),
			'rXYZ' => ICCProfileBuilder::xyzTag(0.4360, 0.2225, 0.0139),
			'gXYZ' => ICCProfileBuilder::xyzTag(0.4360, 0.2225, 0.0139),
			'bXYZ' => ICCProfileBuilder::xyzTag(0.4360, 0.2225, 0.0139),
			'rTRC' => ICCProfileBuilder::curvGamma(2.2),
			'gTRC' => ICCProfileBuilder::curvGamma(2.2),
			'bTRC' => ICCProfileBuilder::curvGamma(2.2),
		]));
		self::assertTrue($degenerate->getIsMatrixShaper());
		self::assertNull(ICCTransform::between($this->sRgb(), $degenerate));

		// As the source it is usable: only the destination has to be inverted.
		self::assertInstanceOf(ICCTransform::class, ICCTransform::between($degenerate, $this->sRgb()));
	}

	public function testOutOfGamutColorsClampRatherThanWrap()
	{
		// Wide-gamut saturated colors fall outside sRGB; they must clamp into range.
		$transform = ICCTransform::between($this->wide(), $this->sRgb());
		$converted = $this->unpack($transform->rgbPixels($this->pack([[255, 0, 0], [0, 255, 0], [0, 0, 255]])));
		foreach ($converted as $index => $pixel) {
			foreach ($pixel as $channel => $value) {
				self::assertGreaterThanOrEqual(0, $value, "pixel $index channel $channel");
				self::assertLessThanOrEqual(255, $value, "pixel $index channel $channel");
			}
		}
		// The saturated primary saturates its own channel in the narrower space.
		self::assertSame(255, $converted[0][0]);
		self::assertSame(255, $converted[1][1]);
	}

	public function testIdentityNeedsMatchingCurvesAsWellAsMatrices()
	{
		// The same colorants with different tone curves: the matrix is the identity, so
		// only the curves can tell the two spaces apart -- and they must.
		$primaries = [
			'wtpt' => ICCProfileBuilder::xyzTag(0.9642, 1.0, 0.8249),
			'rXYZ' => ICCProfileBuilder::xyzTag(0.4360, 0.2225, 0.0139),
			'gXYZ' => ICCProfileBuilder::xyzTag(0.3851, 0.7169, 0.0971),
			'bXYZ' => ICCProfileBuilder::xyzTag(0.1431, 0.0606, 0.7141),
		];
		$gamma = function (float $value) use ($primaries): ICCProfile {
			$curve = ICCProfileBuilder::curvGamma($value);
			return ICCProfile::parse(ICCProfileBuilder::build(
				$primaries + ['rTRC' => $curve, 'gTRC' => $curve, 'bTRC' => $curve],
			));
		};

		$transform = ICCTransform::between($gamma(2.2), $gamma(1.8));
		self::assertFalse($transform->getIsIdentity());

		// And the conversion really re-encodes: a 2.2-encoded midtone is lighter in
		// linear light than a 1.8-encoded one, so the device value has to drop.
		$converted = $this->unpack($transform->rgbPixels($this->pack([[0, 0, 0], [128, 128, 128], [255, 255, 255]])));
		self::assertSame([0, 0, 0], $converted[0]);
		self::assertSame([255, 255, 255], $converted[2]);
		self::assertEqualsWithDelta(109, $converted[1][0], 2);
	}

	public function testDegenerateSampledCurveEvaluatesAsThePassThrough()
	{
		// A one-entry curveType is a gamma, not a table, so a table of fewer than two
		// samples can only reach the evaluator from a caller building the description
		// itself; it has no interval to interpolate over and must pass the value through
		// rather than divide by zero.
		$evaluator = new class () extends ICCTransform {
			public static function evaluateCurve(array $curve, float $x): float
			{
				return static::evaluate($curve, $x);
			}
		};

		self::assertSame(0.25, $evaluator::evaluateCurve(['type' => 'table', 'samples' => [0.5]], 0.25));
		self::assertSame(0.0, $evaluator::evaluateCurve(['type' => 'table', 'samples' => []], 0.0));

		// Two samples is the smallest table that does interpolate.
		self::assertSame(0.125, $evaluator::evaluateCurve(['type' => 'table', 'samples' => [0.0, 0.5]], 0.25));
	}

	public function testDeviceValuesOutsideTheUnitRangeClampToTheEndpoints()
	{
		// The curve tables sample the device range, so a caller building a description
		// itself is the only way past the ends -- and there the evaluator must answer the
		// endpoints. Neither result is reachable without the clamp: a negative raised to
		// a fractional power is not a number, a value past white overshoots it, and a
		// sampled table would index outside its own samples.
		$evaluator = new class () extends ICCTransform {
			public static function evaluateCurve(array $curve, float $x): float
			{
				return static::evaluate($curve, $x);
			}
		};
		$gamma = ['type' => 'gamma', 'gamma' => 2.2];
		self::assertNan((-0.5) ** 2.2);
		self::assertSame(0.0, $evaluator::evaluateCurve($gamma, -0.5));
		self::assertGreaterThan(1.0, 1.5 ** 2.2);
		self::assertSame(1.0, $evaluator::evaluateCurve($gamma, 1.5));

		$table = ['type' => 'table', 'samples' => [0.0, 0.5, 1.0]];
		self::assertSame(0.0, $evaluator::evaluateCurve($table, -2.0));
		self::assertSame(1.0, $evaluator::evaluateCurve($table, 4.0));

		$identity = ['type' => 'identity'];
		self::assertSame(0.0, $evaluator::evaluateCurve($identity, -1.0));
		self::assertSame(1.0, $evaluator::evaluateCurve($identity, 2.0));
	}

	public function testEmptyAndPartialPixelData()
	{
		$transform = ICCTransform::between($this->sRgb(), $this->wide());
		self::assertSame('', $transform->rgbPixels(''));
		// A trailing partial pixel is ignored rather than read past the end.
		self::assertSame(3, strlen($transform->rgbPixels("\x10\x20\x30\x40")));
	}
}
