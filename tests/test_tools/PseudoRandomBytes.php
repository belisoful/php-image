<?php

/**
 * Deterministic stand-in for random_bytes() in tests.
 *
 * Random test data makes coverage non-reproducible: a codec branch that depends on the
 * shape of its input — PackBits filling a 128-byte literal, LZW exhausting its code space
 * — is taken on most draws and missed on the rest, so the coverage gate passes or fails by
 * luck. These bytes are just as unpredictable to the codec under test but identical on
 * every run, which keeps both the assertions and the coverage stable.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */
class PseudoRandomBytes
{
	/**
	 * Produces high-entropy bytes that are the same on every run.  The stream is a
	 * SHA-256 chain, so it neither repeats within the requested length nor compresses.
	 * @param int $length The number of bytes.
	 * @param string $seed The seed; a different seed gives a different, equally stable stream.
	 * @return string The bytes.
	 */
	public static function bytes(int $length, string $seed = 'prado-image'): string
	{
		$out = '';
		$block = $seed;
		while (strlen($out) < $length) {
			$block = hash('sha256', $block, true);
			$out .= $block;
		}
		return substr($out, 0, $length);
	}
}
