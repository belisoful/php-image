<?php

/**
 * CanonMakernote class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\Meta\Makernote;

/**
 * CanonMakernote class.
 *
 * The Canon makernote: a header-less standard IFD whose interesting tags pack many
 * settings into positional unsigned-short blocks.  Beyond the generic decode,
 * {@see getCameraSettings()} expands Camera Settings blocks 1 and 2 (tags
 * 0x0001/0x0004) through {@see MakernoteTables} — macro/quality/flash/drive/focus
 * modes, the self-timer duration, the focal-length range in lens units, the flash
 * activity bit-flags, and the focus-point selection — and {@see getCustomFunctions()}
 * expands the Custom Functions block (tag 0x000F).
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class CanonMakernote extends Makernote
{
	/** The Camera Settings 1 tag. */
	public const CameraSettings1Tag = 0x0001;

	/** The Camera Settings 2 tag. */
	public const CameraSettings2Tag = 0x0004;

	/** The Custom Functions tag. */
	public const CustomFunctionsTag = 0x000F;

	/**
	 * Expands the positional Camera Settings blocks to name => text.
	 * @return array<string, string> The decoded settings.
	 */
	public function getCameraSettings(): array
	{
		return array_merge(
			$this->decodeBlock(self::CameraSettings1Tag, MakernoteTables::CanonCameraSettings1),
			$this->decodeBlock(self::CameraSettings2Tag, MakernoteTables::CanonCameraSettings2),
		);
	}

	/**
	 * Expands the Custom Functions block to name => text.  Each element packs the
	 * function number in its high byte and the setting in its low byte.
	 * @return array<string, string> The decoded custom functions.
	 */
	public function getCustomFunctions(): array
	{
		$tag = $this->getIfd()?->getTag(self::CustomFunctionsTag);
		if ($tag === null || !is_array($values = $tag->getValues())) {
			return [];
		}
		$functions = [];
		foreach (array_slice($values, 1) as $value) {
			$number = ($value >> 8) & 0xFF;
			$setting = $value & 0xFF;
			$def = MakernoteTables::CanonCustomFunctions[$number] ?? null;
			if ($def === null) {
				$functions["Custom Function $number"] = (string) $setting;
			} else {
				$functions[$def['name']] = $def['lookup'][$setting] ?? "Unknown ($setting)";
			}
		}
		return $functions;
	}

	/**
	 * Decodes one positional settings block through its table.
	 * @param int $tagId The block tag id.
	 * @param array $table The positional definitions (index => name/lookup/special).
	 * @return array<string, string> The decoded settings.
	 */
	protected function decodeBlock(int $tagId, array $table): array
	{
		$tag = $this->getIfd()?->getTag($tagId);
		if ($tag === null || !is_array($values = $tag->getValues())) {
			return [];
		}
		$settings = [];
		foreach ($values as $index => $value) {
			$def = $table[$index] ?? null;
			if ($def === null) {
				continue;
			}
			if (isset($def['lookup'])) {
				$settings[$def['name']] = $def['lookup'][$value] ?? "Unknown ($value)";
			} elseif (($def['special'] ?? '') !== '' && str_starts_with($def['special'], 'Size field')) {
				continue;
			} elseif ($def['name'] === 'Self Timer Length') {
				$settings[$def['name']] = $value === 0 ? 'Not used' : ($value / 10) . ' seconds';
			} else {
				$settings[$def['name']] = (string) $value;
			}
		}
		return $settings;
	}
}
