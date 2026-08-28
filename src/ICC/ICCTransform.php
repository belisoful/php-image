<?php

/**
 * ICCTransform class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\ICC;

/**
 * ICCTransform class.
 *
 * Converts RGB pixels from one ICC profile's color space to another's, in pure PHP, so a
 * color transform does not require ImageMagick.  This is what gives
 * {@see \Belisoful\Image\ImageGraphicsGD} the {@see \Belisoful\Image\ImageGraphicsLibraryInterface::CapabilityICCTransform}
 * capability that GD itself has no API for.
 *
 * Only **matrix/TRC** profiles are transformed ({@see ICCProfile::getIsMatrixShaper()}):
 * the source's tone curves linearize each channel, its colorant matrix takes the result
 * to the D50 profile connection space, and the inverse of the destination's matrix and
 * curves bring it back to device values.  Both profiles' matrices are D50-relative by
 * specification, so no chromatic adaptation stands between them.
 *
 * Profiles whose conversion lives in multi-dimensional lookup tables — CMYK and printer
 * profiles — are **not** transformed: {@see supports()} answers false for them and
 * {@see rgbPixels()} returns null, rather than producing plausible but wrong color.  Send
 * those through {@see \Belisoful\Image\ImageGraphicsImagick}, whose lcms handles them.
 *
 * ```php
 * $transform = ICCTransform::between($sourceProfile, $destinationProfile);
 * $converted = $transform?->rgbPixels($rgb24);
 * ```
 *
 * The curves are evaluated into lookup tables once per transform — 256 entries forward
 * (the input is 8-bit) and 4096 inverse entries per channel — so converting a pixel costs
 * three lookups, nine multiplies, and three more lookups.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @see https://www.color.org/icc_specs2.xalter
 */
class ICCTransform
{
	/** @var int The number of samples in each inverse (linear to device) lookup table. */
	public const InverseSamples = 4096;

	/** @var array<int, array<int, float>> The source device-to-linear tables, by channel. */
	private array $_toLinear = [];

	/** @var array<int, array<int, int>> The destination linear-to-device tables, by channel. */
	private array $_toDevice = [];

	/** @var array The 3x3 matrix taking the source's linear RGB to the destination's. */
	private array $_matrix;

	/**
	 * A transform is built by {@see between()}; the constructor is final so that factory
	 * can answer `new static()` in a subclass.
	 */
	final public function __construct()
	{
	}

	/**
	 * Indicates whether a transform between two profiles can be evaluated here: both must
	 * be RGB matrix/TRC profiles.
	 * @param ICCProfile $source The source profile.
	 * @param ICCProfile $destination The destination profile.
	 * @return bool Whether {@see between()} will build a transform.
	 */
	public static function supports(ICCProfile $source, ICCProfile $destination): bool
	{
		return $source->getIsMatrixShaper() && $destination->getIsMatrixShaper();
	}

	/**
	 * Builds the transform between two profiles.
	 * @param ICCProfile $source The source profile.
	 * @param ICCProfile $destination The destination profile.
	 * @return ?static The transform, or null when either profile is not a matrix/TRC
	 *   profile or the destination's matrix cannot be inverted.
	 */
	public static function between(ICCProfile $source, ICCProfile $destination): ?static
	{
		if (!static::supports($source, $destination)) {
			return null;
		}
		$inverse = static::invert((array) $destination->getMatrix());
		if ($inverse === null) {
			return null;
		}
		$transform = new static();
		$transform->_matrix = static::multiply($inverse, (array) $source->getMatrix());
		foreach ((array) $source->getToneCurves() as $channel => $curve) {
			$transform->_toLinear[$channel] = static::forwardTable($curve);
		}
		foreach ((array) $destination->getToneCurves() as $channel => $curve) {
			$transform->_toDevice[$channel] = static::inverseTable($curve);
		}
		return $transform;
	}

	/**
	 * Returns whether this transform is an identity: the same profile on both sides
	 * produces a matrix within rounding of the identity and matching curves.
	 * @return bool Whether the transform leaves pixels unchanged.
	 */
	public function getIsIdentity(): bool
	{
		for ($row = 0; $row < 3; $row++) {
			for ($column = 0; $column < 3; $column++) {
				if (abs($this->_matrix[$row][$column] - ($row === $column ? 1.0 : 0.0)) > 1e-6) {
					return false;
				}
			}
		}
		for ($channel = 0; $channel < 3; $channel++) {
			for ($value = 0; $value < 256; $value++) {
				if ($this->_toDevice[$channel][(int) round($this->_toLinear[$channel][$value] * (self::InverseSamples - 1))] !== $value) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Converts RGB24 pixel bytes from the source profile's space to the destination's.
	 * @param string $rgb The RGB24 pixel bytes, three per pixel.
	 * @return string The converted RGB24 pixel bytes.
	 */
	public function rgbPixels(string $rgb): string
	{
		$matrix = $this->_matrix;
		$toLinear = $this->_toLinear;
		$toDevice = $this->_toDevice;
		$last = self::InverseSamples - 1;
		$out = '';
		$count = intdiv(strlen($rgb), 3);
		for ($i = 0; $i < $count; $i++) {
			$r = $toLinear[0][ord($rgb[$i * 3])];
			$g = $toLinear[1][ord($rgb[$i * 3 + 1])];
			$b = $toLinear[2][ord($rgb[$i * 3 + 2])];
			for ($channel = 0; $channel < 3; $channel++) {
				$linear = $matrix[$channel][0] * $r + $matrix[$channel][1] * $g + $matrix[$channel][2] * $b;
				$index = (int) round(($linear < 0 ? 0 : ($linear > 1 ? 1 : $linear)) * $last);
				$out .= chr($toDevice[$channel][$index]);
			}
		}
		return $out;
	}

	/**
	 * Builds the 256-entry device-to-linear table of a curve, for 8-bit input.
	 * @param array $curve A {@see ICCProfile::decodeCurve()} description.
	 * @return array<int, float> The linear value of each 8-bit device value.
	 */
	protected static function forwardTable(array $curve): array
	{
		$table = [];
		for ($value = 0; $value < 256; $value++) {
			$table[$value] = static::evaluate($curve, $value / 255);
		}
		return $table;
	}

	/**
	 * Builds the linear-to-device table of a curve by sampling its forward direction and
	 * walking the samples, which a tone curve's monotonicity makes exact enough.
	 * @param array $curve A {@see ICCProfile::decodeCurve()} description.
	 * @return array<int, int> The 8-bit device value of each linear sample.
	 */
	protected static function inverseTable(array $curve): array
	{
		$forward = [];
		for ($value = 0; $value < 256; $value++) {
			$forward[$value] = static::evaluate($curve, $value / 255);
		}
		$table = [];
		$device = 0;
		for ($sample = 0; $sample < self::InverseSamples; $sample++) {
			$linear = $sample / (self::InverseSamples - 1);
			while ($device < 255 && $forward[$device + 1] < $linear) {
				$device++;
			}
			// Pick whichever of the bracketing device values lands closer.
			$table[$sample] = $device < 255 && abs($forward[$device + 1] - $linear) < abs($forward[$device] - $linear)
				? $device + 1
				: $device;
		}
		return $table;
	}

	/**
	 * Evaluates a curve in the device-to-linear direction.
	 * @param array $curve A {@see ICCProfile::decodeCurve()} description.
	 * @param float $x The device value, 0 to 1.
	 * @return float The linear value, 0 to 1.
	 */
	protected static function evaluate(array $curve, float $x): float
	{
		$x = $x < 0 ? 0.0 : ($x > 1 ? 1.0 : $x);
		switch ($curve['type']) {
			case 'identity':
				return $x;
			case 'gamma':
				return $x ** $curve['gamma'];
			case 'table':
				$samples = $curve['samples'];
				$count = count($samples);
				if ($count < 2) {
					return $x;
				}
				$position = $x * ($count - 1);
				$low = (int) floor($position);
				if ($low >= $count - 1) {
					return $samples[$count - 1];
				}
				return $samples[$low] + ($samples[$low + 1] - $samples[$low]) * ($position - $low);
			default:
				return static::parametric((int) $curve['function'], $curve['parameters'], $x);
		}
	}

	/**
	 * Evaluates a `parametricCurveType` function, types 0 through 4 of ICC.1.
	 * @param int $function The function type.
	 * @param array $p The parameters, in specification order.
	 * @param float $x The device value, 0 to 1.
	 * @return float The linear value.
	 */
	protected static function parametric(int $function, array $p, float $x): float
	{
		switch ($function) {
			case 0:   // Y = X^g
				return $x ** $p[0];
			case 1:   // Y = (aX + b)^g for X >= -b/a, else 0
				return $p[1] * $x + $p[2] >= 0 ? ($p[1] * $x + $p[2]) ** $p[0] : 0.0;
			case 2:   // as type 1 with an offset c
				return $p[1] * $x + $p[2] >= 0 ? ($p[1] * $x + $p[2]) ** $p[0] + $p[3] : $p[3];
			case 3:   // the sRGB form: a linear segment below d
				return $x >= $p[4] ? ($p[1] * $x + $p[2]) ** $p[0] : $p[3] * $x;
			default:  // type 4: as type 3 with offsets e and f
				return $x >= $p[4] ? ($p[1] * $x + $p[2]) ** $p[0] + $p[5] : $p[3] * $x + $p[6];
		}
	}

	/**
	 * Multiplies two 3x3 matrices.
	 * @param array $a The left matrix.
	 * @param array $b The right matrix.
	 * @return array The product.
	 */
	protected static function multiply(array $a, array $b): array
	{
		$product = [];
		for ($row = 0; $row < 3; $row++) {
			for ($column = 0; $column < 3; $column++) {
				$product[$row][$column] = $a[$row][0] * $b[0][$column]
					+ $a[$row][1] * $b[1][$column]
					+ $a[$row][2] * $b[2][$column];
			}
		}
		return $product;
	}

	/**
	 * Inverts a 3x3 matrix by its adjugate.
	 * @param array $m The matrix.
	 * @return ?array The inverse, or null when the matrix is singular.
	 */
	protected static function invert(array $m): ?array
	{
		$determinant = $m[0][0] * ($m[1][1] * $m[2][2] - $m[1][2] * $m[2][1])
			- $m[0][1] * ($m[1][0] * $m[2][2] - $m[1][2] * $m[2][0])
			+ $m[0][2] * ($m[1][0] * $m[2][1] - $m[1][1] * $m[2][0]);
		if (abs($determinant) < 1e-12) {
			return null;
		}
		$inverse = [];
		for ($row = 0; $row < 3; $row++) {
			for ($column = 0; $column < 3; $column++) {
				// The cofactor of the transposed position, over the determinant.
				$r1 = ($column + 1) % 3;
				$r2 = ($column + 2) % 3;
				$c1 = ($row + 1) % 3;
				$c2 = ($row + 2) % 3;
				$inverse[$row][$column] = ($m[$r1][$c1] * $m[$r2][$c2] - $m[$r1][$c2] * $m[$r2][$c1]) / $determinant;
			}
		}
		return $inverse;
	}
}
