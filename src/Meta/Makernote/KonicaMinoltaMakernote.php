<?php

/**
 * KonicaMinoltaMakernote class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\Meta\Makernote;

/**
 * KonicaMinoltaMakernote class.
 *
 * The Konica/Minolta makernote: the header-less form decodes as a standard IFD sharing
 * the Olympus tag group (and its embedded thumbnail tags); the legacy `MLY`, `KC`,
 * `+M+M+M+M`, and `MINOL` signatures are recognized but carry no decodable IFD.
 * {@see getCameraSettings()} expands the packed camera-settings block (tag
 * 0x0001/0x0003, one unsigned long per setting) through
 * {@see MakernoteTables::MinoltaCameraSettings} — exposure/flash/white-balance modes,
 * sizes, drive modes, and the APEX-derived values.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class KonicaMinoltaMakernote extends Makernote
{
	/** The standard camera-settings block tag. */
	public const CameraSettingsTag = 0x0001;

	/** The 7Hi camera-settings block tag. */
	public const CameraSettings7HiTag = 0x0003;

	/**
	 * Expands the packed camera-settings block to name => text.
	 * @return array<string, string> The decoded settings.
	 */
	public function getCameraSettings(): array
	{
		$tag = $this->getIfd()?->getTag(self::CameraSettingsTag)
			?? $this->getIfd()?->getTag(self::CameraSettings7HiTag);
		if ($tag === null) {
			return [];
		}
		$values = $tag->getValues();
		if (is_string($values)) {
			// Some models store the block as Undefined bytes: 4-byte big-endian words.
			$values = array_values(unpack('N*', substr($values, 0, strlen($values) - (strlen($values) % 4))) ?: []);
		}
		$settings = [];
		foreach ($values as $index => $value) {
			$def = MakernoteTables::MinoltaCameraSettings[$index] ?? null;
			if ($def === null) {
				continue;
			}
			if (isset($def['lookup'])) {
				$settings[$def['name']] = $def['lookup'][$value] ?? "Unknown ($value)";
			} else {
				$settings[$def['name']] = (string) $value;
			}
		}
		return $settings;
	}
}
