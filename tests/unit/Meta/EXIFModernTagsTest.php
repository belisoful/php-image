<?php

use Belisoful\Image\Meta\EXIF;
use Belisoful\Image\Meta\EXIFTags;
use Belisoful\Image\TIFF\TIFFDataType;
use Belisoful\Image\TIFF\TIFFTag;

/**
 * The EXIF 2.3 / 2.31 / 2.32 / 3.0 era tags and the EXIF 3.0 UTF-8 field type.
 */
class EXIFModernTagsTest extends PHPUnit\Framework\TestCase
{
	public function testModernTagNamesResolve()
	{
		$expected = [
			36880 => 'OffsetTime', 36881 => 'OffsetTimeOriginal', 36882 => 'OffsetTimeDigitized',
			37888 => 'Temperature', 37889 => 'Humidity', 37890 => 'Pressure',
			37891 => 'WaterDepth', 37892 => 'Acceleration', 37893 => 'CameraElevationAngle',
			42032 => 'CameraOwnerName', 42033 => 'BodySerialNumber', 42034 => 'LensSpecification',
			42035 => 'LensMake', 42036 => 'LensModel', 42037 => 'LensSerialNumber',
			42080 => 'CompositeImage', 42081 => 'SourceImageNumberOfCompositeImage',
			42082 => 'SourceExposureTimesOfCompositeImage',
			42038 => 'Title', 42039 => 'Photographer', 42040 => 'ImageEditor',
			42041 => 'CameraFirmware', 42042 => 'RAWDevelopingSoftware',
			42043 => 'ImageEditingSoftware', 42044 => 'MetadataEditingSoftware',
		];
		foreach ($expected as $id => $name) {
			self::assertSame($name, EXIFTags::nameOf(EXIFTags::EXIF, $id), "tag $id");
			self::assertSame([EXIFTags::EXIF, $id], EXIFTags::findByName($name), $name);
		}
		self::assertSame('GPSHPositioningError', EXIFTags::nameOf(EXIFTags::GPS, 31));
	}

	public function testModernTagInterpretation()
	{
		$temperature = new TIFFTag(37888, TIFFDataType::SRational, [[-52, 10]]);
		self::assertSame('-5.2 degrees Celsius', EXIFTags::textValue($temperature, EXIFTags::EXIF));

		$composite = new TIFFTag(42080, TIFFDataType::UShort, [2]);
		self::assertSame('General composite image', EXIFTags::textValue($composite, EXIFTags::EXIF));

		$lens = new TIFFTag(42036, TIFFDataType::Ascii, "RF24-105mm F4 L IS USM\0");
		self::assertSame('RF24-105mm F4 L IS USM', EXIFTags::textValue($lens, EXIFTags::EXIF));

		$error = new TIFFTag(31, TIFFDataType::URational, [[35, 10]]);
		self::assertSame('3.5 metres', EXIFTags::textValue($error, EXIFTags::GPS));
	}

	public function testModernTagsRoundTripThroughExif()
	{
		$exif = new EXIF();
		self::assertTrue($exif->setValueByName('LensModel', 'RF50mm F1.8 STM'));
		self::assertTrue($exif->setValueByName('BodySerialNumber', '123456789'));
		self::assertTrue($exif->setValueByName('OffsetTimeOriginal', '+02:00'));
		self::assertTrue($exif->setValueByName('GPSHPositioningError', 5));

		$reparsed = EXIF::fromSegment($exif->toBinary());
		self::assertSame('RF50mm F1.8 STM', $reparsed->getValueByName('LensModel'));
		self::assertSame('+02:00', $reparsed->getValueByName('OffsetTimeOriginal'));
		self::assertSame(5, $reparsed->getValueByName('GPSHPositioningError'));
	}

	public function testUtf8FieldTypeRoundTrip()
	{
		// EXIF 3.0 type 129: UTF-8 string semantics through the whole engine.
		self::assertTrue(TIFFDataType::isValid(TIFFDataType::Utf8));
		self::assertSame(1, TIFFDataType::getSize(TIFFDataType::Utf8));

		$text = "Fotografía nórdica ✓\0";
		$exif = new EXIF();
		$exif->getExifIfd(true)->setTagValues(42039, TIFFDataType::Utf8, $text);

		$reparsed = EXIF::fromSegment($exif->toBinary());
		$tag = $reparsed->getExifIfd()->getTag(42039);
		self::assertSame(TIFFDataType::Utf8, $tag->getType());
		self::assertSame('Fotografía nórdica ✓', $tag->getValue());
		self::assertSame('Fotografía nórdica ✓', EXIFTags::textValue($tag, EXIFTags::EXIF));

		// Both byte orders carry it unchanged.
		$exif->getTiff()->setIsBigEndian(false);
		$again = EXIF::fromSegment($exif->toBinary());
		self::assertSame('Fotografía nórdica ✓', $again->getExifIfd()->getTag(42039)->getValue());
	}

	public function testExif23SensitivityBlock()
	{
		foreach ([
			34864 => 'SensitivityType', 34865 => 'StandardOutputSensitivity',
			34866 => 'RecommendedExposureIndex', 34867 => 'ISOSpeed',
			34868 => 'ISOSpeedLatitudeyyy', 34869 => 'ISOSpeedLatitudezzz',
		] as $id => $name) {
			self::assertSame($name, EXIFTags::nameOf(EXIFTags::EXIF, $id), "tag $id");
			self::assertSame([EXIFTags::EXIF, $id], EXIFTags::findByName($name), $name);
		}

		$type = new TIFFTag(34864, TIFFDataType::UShort, [3]);
		self::assertSame('ISO speed', EXIFTags::textValue($type, EXIFTags::EXIF));

		// 34855 carries its post-2.21 spec name, with the historic alias still resolving.
		self::assertSame('PhotographicSensitivity', EXIFTags::nameOf(EXIFTags::EXIF, 34855));
		self::assertSame([EXIFTags::EXIF, 34855], EXIFTags::findByName('PhotographicSensitivity'));
		self::assertSame([EXIFTags::EXIF, 34855], EXIFTags::findByName('ISOSpeedRatings'));

		$exif = new EXIF();
		self::assertTrue($exif->setValueByName('ISOSpeedRatings', 400));   // alias writes too
		self::assertTrue($exif->setValueByName('ISOSpeed', 409600));
		$reparsed = EXIF::fromSegment($exif->toBinary());
		self::assertSame(400, $reparsed->getValueByName('PhotographicSensitivity'));
		self::assertSame(409600, $reparsed->getValueByName('ISOSpeed'));
	}

	public function testExif31TagsResolveAndInterpret()
	{
		// CIPA DC-008-2026 (Exif 3.1) additions.
		$expected = [
			37511 => 'LearningOptOutIn',
			41997 => 'DevelopmentType', 41998 => 'DevelopmentTypeDescription',
			41999 => 'DistortionCorrection', 42000 => 'ChromaticAberrationCorrection',
			42001 => 'ShadingCorrection', 42002 => 'NoiseReduction',
		];
		foreach ($expected as $id => $name) {
			self::assertSame($name, EXIFTags::nameOf(EXIFTags::EXIF, $id), "tag $id");
			self::assertSame([EXIFTags::EXIF, $id], EXIFTags::findByName($name), $name);
		}

		$development = new TIFFTag(41997, TIFFDataType::UShort, [0x0201]);
		$text = (string) EXIFTags::textValue($development, EXIFTags::EXIF);
		self::assertStringContainsString('without extreme difference', $text);
		self::assertStringContainsString('factory default development', $text);

		$noise = new TIFFTag(42002, TIFFDataType::UShort, [2]);
		self::assertSame('Normal strength noise reduction', EXIFTags::textValue($noise, EXIFTags::EXIF));

		$distortion = new TIFFTag(41999, TIFFDataType::UShort, [1]);
		self::assertSame('Applied', EXIFTags::textValue($distortion, EXIFTags::EXIF));
	}

	public function testExif31LightSourceLedValues()
	{
		foreach ([16 => 'Warm white fluorescent', 25 => 'Daylight light source', 30 => 'Daylight LED', 34 => 'Warm white LED', 24 => 'ISO studio tungsten'] as $value => $prefix) {
			$tag = new TIFFTag(37384, TIFFDataType::UShort, [$value]);
			self::assertStringStartsWith($prefix, (string) EXIFTags::textValue($tag, EXIFTags::EXIF), "value $value");
		}
	}

	public function testExif31RoundTrip()
	{
		$exif = new EXIF();
		self::assertTrue($exif->setValueByName('NoiseReduction', 3));
		self::assertTrue($exif->setValueByName('DevelopmentTypeDescription', 'AI にじみ低減'));   // UTF-8 per spec
		$exif->getExifIfd(true)->setTagValues(37511, TIFFDataType::Undefined, "\x00\x00\x00\x02");

		$reparsed = EXIF::fromSegment($exif->toBinary());
		self::assertSame(3, $reparsed->getValueByName('NoiseReduction'));
		self::assertSame('AI にじみ低減', $reparsed->getValueByName('DevelopmentTypeDescription'));
		self::assertSame(TIFFDataType::Utf8, $reparsed->getTagByName('DevelopmentTypeDescription')->getType());
		self::assertSame("\x00\x00\x00\x02", $reparsed->getExifIfd()->getTag(37511)->getValues());
	}

	public function testSetValueByNameSelectsUtf8ForMultibyteText()
	{
		$exif = new EXIF();
		$exif->setValueByName('Artist', 'Plain Ascii Name');
		$exif->setValueByName('Photographer', 'Åse Ødegård');   // EXIF 3.0: non-ASCII => type 129

		self::assertSame(TIFFDataType::Ascii, $exif->getTagByName('Artist')->getType());
		self::assertSame(TIFFDataType::Utf8, $exif->getTagByName('Photographer')->getType());

		$reparsed = EXIF::fromSegment($exif->toBinary());
		self::assertSame('Åse Ødegård', $reparsed->getValueByName('Photographer'));
		self::assertSame(TIFFDataType::Utf8, $reparsed->getTagByName('Photographer')->getType());
	}

	public function testLearningOptOutInCodec()
	{
		// Exif 3.1 Figure 16: a count of sets, then usage/intention short pairs.
		$block = EXIFTags::encodeLearningOptOut([0 => 2, 1 => 1, 4 => 0]);
		self::assertSame("\x00\x03\x00\x00\x00\x02\x00\x01\x00\x01\x00\x04\x00\x00", $block);
		self::assertSame([0 => 2, 1 => 1, 4 => 0], EXIFTags::decodeLearningOptOut($block));

		// The mandatory usage 0 set leads regardless of the map's order.
		$ordered = EXIFTags::encodeLearningOptOut([3 => 0, 0 => 1]);
		self::assertSame("\x00\x02\x00\x00\x00\x01\x00\x03\x00\x00", $ordered);

		// Little-endian files pack the same fields the other way around.
		$little = EXIFTags::encodeLearningOptOut([0 => 2, 2 => 0], false);
		self::assertSame("\x02\x00\x00\x00\x02\x00\x02\x00\x00\x00", $little);
		self::assertSame([0 => 2, 2 => 0], EXIFTags::decodeLearningOptOut($little, false));

		// Truncated, empty, and count-overrunning blocks decode to nothing.
		self::assertSame([], EXIFTags::decodeLearningOptOut(''));
		self::assertSame([], EXIFTags::decodeLearningOptOut("\x00\x01\x00\x00"));
		self::assertSame([], EXIFTags::decodeLearningOptOut("\x00\x00\x00\x00\x00\x02"));

		$tag = new TIFFTag(37511, TIFFDataType::Undefined, $block);
		$text = (string) EXIFTags::textValue($tag, EXIFTags::EXIF);
		self::assertStringContainsString('All / Individual usage is not specified: Unspecified', $text);
		self::assertStringContainsString('Non-Generative AI/ML Training: Opt-in', $text);
		self::assertStringContainsString('Input to Foundation Model (Trained AI/ML Model): Opt-out', $text);
	}

	public function testLearningIntentionsThroughExif()
	{
		$exif = new EXIF();
		self::assertSame([], $exif->getLearningIntentions());

		$exif->setLearningIntentions([0 => 2, 2 => 0]);
		$reparsed = EXIF::fromSegment($exif->toBinary());
		self::assertSame([0 => 2, 2 => 0], $reparsed->getLearningIntentions());

		// Little-endian EXIF writes and reads the sets in its own byte order.
		$little = new EXIF();
		$little->getTiff()->setIsBigEndian(false);
		$little->setLearningIntentions([0 => 1, 3 => 0]);
		self::assertSame([0 => 1, 3 => 0], EXIF::fromSegment($little->toBinary())->getLearningIntentions());

		// An empty map removes the tag.
		$reparsed->setLearningIntentions([]);
		self::assertNull($reparsed->getExifIfd()->getTag(37511));
		self::assertSame([], $reparsed->getLearningIntentions());

		// The spec requires the usage 0 set.
		self::expectException(\InvalidArgumentException::class);
		$exif->setLearningIntentions([1 => 0]);
	}

	public function testExif31SpecialDecodersRejectUnreadableValues()
	{
		// A LearningOptOutIn block whose set count is zero carries no intentions, so it
		// has no text form at all rather than an empty list.
		$empty = new TIFFTag(37511, TIFFDataType::Undefined, "\x00\x00\x00\x00\x00\x00");
		self::assertNull(EXIFTags::textValue($empty, EXIFTags::EXIF));
		self::assertSame([], EXIFTags::decodeLearningOptOut("\x00\x00\x00\x00\x00\x00"));

		// DevelopmentType packs both fields into one short: a multi-value tag is not
		// a development type and is reported as having no text.
		$multi = new TIFFTag(41997, TIFFDataType::UShort, [1, 2]);
		self::assertNull(EXIFTags::textValue($multi, EXIFTags::EXIF));
	}

	public function testLensSpecificationNumericForm()
	{
		$spec = new TIFFTag(42034, TIFFDataType::URational, [[24, 1], [105, 1], [4, 1], [4, 1]]);
		$text = EXIFTags::textValue($spec, EXIFTags::EXIF);
		self::assertStringContainsString('24, 105, 4, 4', (string) $text);
		self::assertStringContainsString('min focal length', (string) $text);
	}
}
