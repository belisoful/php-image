<?php

/**
 * Builds ICC profiles in memory for the unit tests, so the color-management paths are
 * exercised without binary fixtures in the repository (the same convention `ext-gd`
 * follows for image fixtures).
 *
 * The primaries and curve parameters are the published colorimetry of the named color
 * spaces, D50-adapted the way an ICC profile stores them.
 */
class ICCProfileBuilder
{
	/** Encodes a signed 15.16 fixed-point number. */
	public static function s15(float $value): string
	{
		return pack('N', ((int) round($value * 65536)) & 0xFFFFFFFF);
	}

	/** An `XYZType` tag holding one XYZNumber. */
	public static function xyzTag(float $x, float $y, float $z): string
	{
		return 'XYZ ' . "\0\0\0\0" . self::s15($x) . self::s15($y) . self::s15($z);
	}

	/** A `curveType` tag with no entries: the identity. */
	public static function curvIdentity(): string
	{
		return 'curv' . "\0\0\0\0" . pack('N', 0);
	}

	/** A `curveType` tag with one entry: a u8Fixed8 gamma. */
	public static function curvGamma(float $gamma): string
	{
		return 'curv' . "\0\0\0\0" . pack('N', 1) . pack('n', (int) round($gamma * 256));
	}

	/**
	 * A sampled `curveType` tag.
	 * @param array $samples The linear values, 0 to 1, of equally spaced device values.
	 */
	public static function curvTable(array $samples): string
	{
		$tag = 'curv' . "\0\0\0\0" . pack('N', count($samples));
		foreach ($samples as $sample) {
			$tag .= pack('n', (int) round($sample * 65535));
		}
		return $tag;
	}

	/**
	 * A `parametricCurveType` tag.
	 * @param int $function The function type, 0 to 4.
	 * @param array $parameters The parameters in specification order.
	 */
	public static function paraCurve(int $function, array $parameters): string
	{
		$tag = 'para' . "\0\0\0\0" . pack('n', $function) . "\0\0";
		foreach ($parameters as $parameter) {
			$tag .= self::s15($parameter);
		}
		return $tag;
	}

	/** A version 2 `textDescriptionType` tag. */
	public static function descTag(string $text): string
	{
		return 'desc' . "\0\0\0\0" . pack('N', strlen($text) + 1) . $text . "\0" . str_repeat("\0", 78);
	}

	/** A version 4 `multiLocalizedUnicodeType` tag holding one en-US string. */
	public static function mlucTag(string $text): string
	{
		$utf16 = mb_convert_encoding($text, 'UTF-16BE', 'UTF-8');
		return 'mluc' . "\0\0\0\0" . pack('N', 1) . pack('N', 12)
			. 'enUS' . pack('N', strlen($utf16)) . pack('N', 28) . $utf16;
	}

	/** A `textType` tag. */
	public static function textTag(string $text): string
	{
		return 'text' . "\0\0\0\0" . $text . "\0";
	}

	/** An `lut16Type` A2B0 tag, structurally valid but not evaluated by the library. */
	public static function lutTag(): string
	{
		return 'mft2' . "\0\0\0\0" . chr(4) . chr(3) . chr(2) . "\0"
			. str_repeat(self::s15(1.0) . self::s15(0.0) . self::s15(0.0), 3)
			. pack('nn', 2, 2) . str_repeat("\0\0", 4 * 2 + 2 * 2 * 2 * 2 * 2 * 3 + 3 * 2);
	}

	/**
	 * Assembles a profile from its tags.
	 * @param array<string, string> $tags The tag content, by four-character signature.
	 * @param array $header Overrides: 'class', 'space', 'pcs', 'intent', 'version',
	 *   'platform', 'manufacturer', 'model', 'creator', 'date', 'id'.
	 */
	public static function build(array $tags, array $header = []): string
	{
		$offset = 132 + count($tags) * 12;
		$table = pack('N', count($tags));
		$data = '';
		foreach ($tags as $signature => $content) {
			$table .= $signature . pack('N', $offset) . pack('N', strlen($content));
			$padding = (4 - strlen($content) % 4) % 4;
			$data .= $content . str_repeat("\0", $padding);
			$offset += strlen($content) + $padding;
		}
		$body = $table . $data;

		$date = $header['date'] ?? [2026, 1, 1, 0, 0, 0];
		$bytes = pack('N', 128 + strlen($body))                      // 0: size
			. '    '                                                 // 4: preferred CMM
			. pack('N', $header['version'] ?? 0x02300000)            // 8: version
			. ($header['class'] ?? 'mntr')                           // 12: device class
			. ($header['space'] ?? 'RGB ')                           // 16: data color space
			. ($header['pcs'] ?? 'XYZ ')                             // 20: connection space
			. pack('nnnnnn', ...$date)                               // 24: creation date
			. 'acsp'                                                 // 36: file signature
			. ($header['platform'] ?? 'APPL')                        // 40: primary platform
			. pack('N', 0)                                           // 44: profile flags
			. ($header['manufacturer'] ?? '    ')                    // 48: device manufacturer
			. ($header['model'] ?? '    ')                           // 52: device model
			. str_repeat("\0", 8)                                    // 56: device attributes
			. pack('N', $header['intent'] ?? 0)                      // 64: rendering intent
			. self::s15(0.9642) . self::s15(1.0) . self::s15(0.8249) // 68: PCS illuminant
			. ($header['creator'] ?? '    ')                         // 80: profile creator
			. ($header['id'] ?? str_repeat("\0", 16))                // 84: profile id
			. str_repeat("\0", 28);                                  // 100: reserved
		return $bytes . $body;
	}

	/**
	 * An sRGB profile: the IEC 61966-2.1 primaries with the piecewise curve as a
	 * `parametricCurveType` of function 3.
	 */
	public static function sRgb(): string
	{
		$curve = self::paraCurve(3, [2.4, 1 / 1.055, 0.055 / 1.055, 1 / 12.92, 0.04045]);
		return self::build([
			'desc' => self::descTag('sRGB built for tests'),
			'wtpt' => self::xyzTag(0.9642, 1.0, 0.8249),
			'rXYZ' => self::xyzTag(0.4360, 0.2225, 0.0139),
			'gXYZ' => self::xyzTag(0.3851, 0.7169, 0.0971),
			'bXYZ' => self::xyzTag(0.1431, 0.0606, 0.7141),
			'rTRC' => $curve,
			'gTRC' => $curve,
			'bTRC' => $curve,
			'cprt' => self::textTag('Public Domain'),
		]);
	}

	/**
	 * An Adobe RGB (1998) style profile: wider primaries and the single 2.19921875 gamma,
	 * so a transform against {@see sRgb()} has real work to do.
	 */
	public static function wideGamut(): string
	{
		$curve = self::curvGamma(2.19921875);
		return self::build([
			'desc' => self::mlucTag('Wide gamut built for tests'),
			'wtpt' => self::xyzTag(0.9642, 1.0, 0.8249),
			'rXYZ' => self::xyzTag(0.6097, 0.3111, 0.0195),
			'gXYZ' => self::xyzTag(0.2052, 0.6257, 0.0609),
			'bXYZ' => self::xyzTag(0.1492, 0.0632, 0.7448),
			'rTRC' => $curve,
			'gTRC' => $curve,
			'bTRC' => $curve,
		], ['version' => 0x04300000]);
	}

	/** A CMYK output profile whose conversion is a lookup table. */
	public static function cmykLut(): string
	{
		return self::build([
			'desc' => self::descTag('CMYK lookup built for tests'),
			'wtpt' => self::xyzTag(0.9642, 1.0, 0.8249),
			'A2B0' => self::lutTag(),
			'B2A0' => self::lutTag(),
		], ['class' => 'prtr', 'space' => 'CMYK', 'intent' => 1]);
	}
}
