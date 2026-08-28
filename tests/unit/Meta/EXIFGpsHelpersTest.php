<?php

use Belisoful\Image\Meta\EXIF;

class EXIFGpsHelpersTest extends PHPUnit\Framework\TestCase
{
	public function testCoordinateRoundTripAllQuadrants()
	{
		foreach ([
			[60.392990, 5.324383],     // Bergen: N/E
			[-33.868820, 151.209290],  // Sydney: S/E
			[34.052235, -118.243683],  // Los Angeles: N/W
			[-22.906847, -43.172897],  // Rio: S/W
		] as [$lat, $lon]) {
			$exif = new EXIF();
			$exif->setLatitude($lat);
			$exif->setLongitude($lon);

			$reparsed = EXIF::fromSegment($exif->toBinary());
			self::assertEqualsWithDelta($lat, $reparsed->getLatitude(), 1e-6, "lat $lat");
			self::assertEqualsWithDelta($lon, $reparsed->getLongitude(), 1e-6, "lon $lon");
		}
	}

	public function testWrittenTagsAreSpecShaped()
	{
		$exif = new EXIF();
		$exif->setLatitude(-33.868820);
		$gps = $exif->getGpsIfd();

		self::assertSame([2, 3, 0, 0], $gps->getTag(0)->getValues());   // GPSVersionID seeded
		self::assertSame('S', $gps->getTag(1)->getValue());
		$dms = $gps->getTag(2)->getValues();
		self::assertSame([33, 1], $dms[0]);
		self::assertSame([52, 1], $dms[1]);
		self::assertSame(10000, $dms[2][1]);

		// The human-readable DMS interpretation stays coherent with the helper.
		self::assertStringContainsString('33° 52\'', $exif->getTextByName('GPSLatitude'));
	}

	public function testAltitudeAboveAndBelowSeaLevel()
	{
		$exif = new EXIF();
		$exif->setAltitude(1280.5);
		self::assertEqualsWithDelta(1280.5, EXIF::fromSegment($exif->toBinary())->getAltitude(), 1e-6);

		$exif->setAltitude(-42.25);
		$reparsed = EXIF::fromSegment($exif->toBinary());
		self::assertEqualsWithDelta(-42.25, $reparsed->getAltitude(), 1e-6);
		self::assertSame(1, $reparsed->getGpsIfd()->getTagValue(5));   // below-sea-level ref

		$exif->setAltitude(null);
		self::assertNull(EXIF::fromSegment($exif->toBinary())->getAltitude());
	}

	public function testGpsTimestampConvertsToUtc()
	{
		$exif = new EXIF();
		$local = new DateTimeImmutable('2026-07-17 14:30:05', new DateTimeZone('America/Los_Angeles'));
		$exif->setGpsTimestamp($local);

		$reparsed = EXIF::fromSegment($exif->toBinary());
		$utc = $reparsed->getGpsTimestamp();
		self::assertNotNull($utc);
		self::assertSame('2026:07:17 21:30:05', $utc->format('Y:m:d H:i:s'));
		self::assertSame('UTC', $utc->getTimezone()->getName());
		self::assertSame('2026:07:17', $reparsed->getGpsIfd()->getTagValue(29));

		$reparsed->setGpsTimestamp(null);
		self::assertNull(EXIF::fromSegment($reparsed->toBinary())->getGpsTimestamp());
	}

	public function testRemovalAndAbsence()
	{
		$exif = new EXIF();
		self::assertNull($exif->getLatitude());
		self::assertNull($exif->getLongitude());
		self::assertNull($exif->getAltitude());
		self::assertNull($exif->getGpsTimestamp());

		$exif->setLatitude(10.5);
		$exif->setLongitude(20.25);
		$exif->setLatitude(null);
		$exif->setLongitude(null);
		$reparsed = EXIF::fromSegment($exif->toBinary());
		self::assertNull($reparsed->getLatitude());
		self::assertNull($reparsed->getLongitude());
	}

	public function testGpsTimestampWithADateStampThatIsNotADate()
	{
		// Both tags are there and the time is well formed, but the date stamp is not in
		// the spec's 'YYYY:MM:DD' shape: no instant is invented from it.
		$exif = new EXIF();
		$gps = $exif->getGpsIfd(true);
		$gps->setTagValues(29, Belisoful\Image\TIFF\TIFFDataType::Ascii, "not-a-date\0");
		$gps->setTagValues(7, Belisoful\Image\TIFF\TIFFDataType::URational, [[14, 1], [30, 1], [5, 1]]);
		self::assertSame('14:30:05', $exif->getTextByName('GPSTimeStamp'));
		self::assertNull($exif->getGpsTimestamp());
	}

	public function testDegreesOnlyCoordinateReads()
	{
		// A file storing only whole degrees (count 1) still reads.
		$exif = new EXIF();
		$gps = $exif->getGpsIfd(true);
		$gps->setTagValues(1, Belisoful\Image\TIFF\TIFFDataType::Ascii, "N\0");
		$gps->setTagValues(2, Belisoful\Image\TIFF\TIFFDataType::URational, [[60, 1]]);
		self::assertEqualsWithDelta(60.0, $exif->getLatitude(), 1e-9);
	}
}
