<?php

use Belisoful\Image\Meta\PhotoshopFileInfo;
use Belisoful\Image\Meta\IPTC;
use Belisoful\Image\Meta\IPTCTags;
use Belisoful\Image\Meta\XMP;
use Belisoful\Image\JPEGImage;
use Belisoful\Image\PhotoshopIRB;
use Belisoful\Image\PhotoshopResource;

class PhotoshopFileInfoTest extends PHPUnit\Framework\TestCase
{
	private function jpeg(): JPEGImage
	{
		$im = imagecreatetruecolor(16, 12);
		ob_start();
		imagejpeg($im);
		imagedestroy($im);
		return JPEGImage::fromString(ob_get_clean());
	}

	public function testFieldAccessAndValidation()
	{
		$info = new PhotoshopFileInfo();
		$info['title'] = 'A Title';
		$info['keywords'] = ['one', 'two'];
		self::assertSame('A Title', $info['Title']);   // case-insensitive
		self::assertTrue(isset($info['title']));
		self::assertFalse(isset($info['caption']));
		unset($info['title']);
		self::assertNull($info['title']);

		self::expectException(\InvalidArgumentException::class);
		$info['nosuchfield'] = 'x';
	}

	public function testApplyToWritesAllThreeStores()
	{
		$jpeg = $this->jpeg();
		$info = new PhotoshopFileInfo();
		$info['title'] = 'Sunset over Bergen';
		$info['author'] = 'A. Photographer';
		$info['caption'] = 'Long evening light.';
		$info['keywords'] = ['sunset', 'norway'];
		$info['city'] = 'Bergen';
		$info['country'] = 'Norway';
		$info['copyrightstatus'] = PhotoshopFileInfo::Copyrighted;
		$info['copyrightnotice'] = '© 2026 A. Photographer';
		$info['ownerurl'] = 'https://example.org';
		$info['date'] = '2026-07-17';
		$info['urgency'] = '5';
		$info['jobname'] = 'Job 42';
		$info->applyTo($jpeg);

		$reparsed = JPEGImage::fromString($jpeg->toBinary());

		// IPTC (inside the IRB)
		$iptc = $reparsed->getIPTC();
		self::assertSame('Sunset over Bergen', $iptc[IPTCTags::ObjectName]);
		self::assertSame(['A. Photographer'], $iptc[IPTCTags::ByLine]);
		self::assertSame(['sunset', 'norway'], $iptc[IPTCTags::Keywords]);
		self::assertSame('Bergen', $iptc[IPTCTags::City]);
		self::assertSame('20260717', $iptc[IPTCTags::DateCreated]);
		self::assertSame(5, (int) $iptc[IPTCTags::Urgency]);

		// XMP
		$xmp = $reparsed->getXMP();
		self::assertSame('Sunset over Bergen', $xmp->getTitle());
		self::assertSame(['A. Photographer'], $xmp->getCreators());
		self::assertSame(['sunset', 'norway'], $xmp->getKeywords());
		self::assertSame('True', $xmp->getProperty($xmp::NS_RIGHTS, 'Marked'));
		self::assertSame('https://example.org', $xmp->getProperty($xmp::NS_RIGHTS, 'WebStatement'));
		self::assertSame(['name' => 'Job 42'], $xmp->getProperty($xmp::NS_BJ, 'JobRef'));

		// EXIF
		self::assertSame('A. Photographer', $reparsed->getEXIF()->getValueByName('Artist'));
		self::assertSame('Long evening light.', $reparsed->getEXIF()->getValueByName('ImageDescription'));

		// IRB
		self::assertTrue($reparsed->getPhotoshopIRB()->getResource(PhotoshopResource::CopyrightFlag)->decodeBoolean());
		self::assertSame('https://example.org', $reparsed->getPhotoshopIRB()->getResource(PhotoshopResource::Url)->decodeText());
	}

	public function testFromJpegMergesAcrossStores()
	{
		$jpeg = $this->jpeg();
		$info = new PhotoshopFileInfo();
		$info['title'] = 'Merged Title';
		$info['author'] = 'Author One';
		$info['keywords'] = ['alpha', 'beta'];
		$info['headline'] = 'The Headline';
		$info['copyrightstatus'] = PhotoshopFileInfo::PublicDomain;
		$info->applyTo($jpeg);

		$merged = PhotoshopFileInfo::fromJpeg(JPEGImage::fromString($jpeg->toBinary()));
		self::assertSame('Merged Title', $merged['title']);
		self::assertSame('Author One', $merged['author']);
		self::assertSame(['alpha', 'beta'], $merged['keywords']);
		self::assertSame('The Headline', $merged['headline']);
		self::assertSame(PhotoshopFileInfo::PublicDomain, $merged['copyrightstatus']);
	}

	public function testIptcLengthLimits()
	{
		$jpeg = $this->jpeg();
		$info = new PhotoshopFileInfo();
		$info['title'] = str_repeat('T', 100);
		$info->applyTo($jpeg);
		$reparsed = JPEGImage::fromString($jpeg->toBinary());
		self::assertSame(64, strlen($reparsed->getIPTC()[IPTCTags::ObjectName]));
		self::assertSame(str_repeat('T', 100), $reparsed->getXMP()->getTitle());   // XMP keeps the full value
	}

	public function testFromJpegReadsIptcDateUrgencyAndTheIrbCopyrightFlag()
	{
		$jpeg = $this->jpeg();
		$iptc = new IPTC();
		$iptc[IPTCTags::DateCreated] = '20260717';
		$iptc[IPTCTags::Urgency] = 5;
		$jpeg->setIPTC($iptc);
		$irb = new PhotoshopIRB();
		$irb->setResource(new PhotoshopResource(PhotoshopResource::CopyrightFlag, "\x01"));
		$jpeg->setPhotoshopIRB($irb);

		// No XMP names any of these, so the IPTC and IRB stores supply them.
		$info = PhotoshopFileInfo::fromJpeg(JPEGImage::fromString($jpeg->toBinary()));
		self::assertSame('2026-07-17', $info['date']);
		self::assertSame('5', $info['urgency']);
		self::assertSame(PhotoshopFileInfo::Copyrighted, $info['copyrightstatus']);

		// A cleared flag reads as an unknown status, not as public domain.
		$other = $this->jpeg();
		$clearIrb = new PhotoshopIRB();
		$clearIrb->setResource(new PhotoshopResource(PhotoshopResource::CopyrightFlag, "\x00"));
		$other->setPhotoshopIRB($clearIrb);
		self::assertSame(
			PhotoshopFileInfo::CopyrightUnknown,
			PhotoshopFileInfo::fromJpeg(JPEGImage::fromString($other->toBinary()))['copyrightstatus'],
		);
	}

	public function testFromJpegTakesThePrimaryValueOfArrayXmpProperties()
	{
		$jpeg = $this->jpeg();
		$xmp = XMP::blank();
		$xmp->setProperty(XMP::NS_PHOTOSHOP, 'DateCreated', ['2026-07-17', '2020-01-01'], 'Bag');
		$xmp->setProperty(XMP::NS_PHOTOSHOP, 'Urgency', ['level' => '5']);   // a structure has no primary string
		$jpeg->setXMP($xmp);

		$info = PhotoshopFileInfo::fromJpeg(JPEGImage::fromString($jpeg->toBinary()));
		self::assertSame('2026-07-17', $info['date']);
		self::assertNull($info['urgency']);
	}

	public function testListValuedFieldsJoinForSingleValuedStores()
	{
		$jpeg = $this->jpeg();
		$info = new PhotoshopFileInfo();
		$info['title'] = ['First', 'Second'];
		$info->applyTo($jpeg);

		$reparsed = JPEGImage::fromString($jpeg->toBinary());
		self::assertSame('First, Second', $reparsed->getXMP()->getTitle());
		self::assertSame('First', $reparsed->getIPTC()[IPTCTags::ObjectName]);
	}

	public function testFromJpegReadsArrayValuedXmpPropertiesAndStructures()
	{
		$jpeg = $this->jpeg();
		$xmp = XMP::blank();
		$xmp->setProperty(XMP::NS_PHOTOSHOP, 'City', ['Bergen', 'Oslo'], 'Bag');   // an array field takes its first item
		$xmp->setProperty(XMP::NS_PHOTOSHOP, 'Credit', ['by' => 'nobody']);        // a structure has no first item
		$xmp->setProperty(XMP::NS_BJ, 'JobRef', ['name' => 'Job 42']);
		$jpeg->setXMP($xmp);

		$info = PhotoshopFileInfo::fromJpeg(JPEGImage::fromString($jpeg->toBinary()));
		self::assertSame('Bergen', $info['city']);
		self::assertNull($info['credit']);
		self::assertSame('Job 42', $info['jobname']);

		// A job reference structure with no name field names no job.
		$other = $this->jpeg();
		$otherXmp = XMP::blank();
		$otherXmp->setProperty(XMP::NS_BJ, 'JobRef', ['id' => '42']);
		$other->setXMP($otherXmp);
		self::assertNull(PhotoshopFileInfo::fromJpeg(JPEGImage::fromString($other->toBinary()))['jobname']);
	}

	public function testSupplementalCategoriesRoundTripThroughXmpAndIptc()
	{
		$jpeg = $this->jpeg();
		$info = new PhotoshopFileInfo();
		$info['supplementalcategories'] = ['news', 'wire'];
		$info->applyTo($jpeg);

		$reparsed = JPEGImage::fromString($jpeg->toBinary());
		self::assertSame(['news', 'wire'], $reparsed->getXMP()->getProperty(XMP::NS_PHOTOSHOP, 'SupplementalCategories'));
		self::assertSame(['news', 'wire'], $reparsed->getIPTC()[IPTCTags::SupplementalCategories]);
		self::assertSame(['news', 'wire'], PhotoshopFileInfo::fromJpeg($reparsed)['supplementalcategories']);

		// An empty list removes the property instead of writing an empty collection.
		$empty = $this->jpeg();
		(new PhotoshopFileInfo())->applyTo($empty);
		$stripped = JPEGImage::fromString($empty->toBinary());
		self::assertNull($stripped->getXMP()->getProperty(XMP::NS_PHOTOSHOP, 'SupplementalCategories'));
		self::assertNull(PhotoshopFileInfo::fromJpeg($stripped)['supplementalcategories']);
	}

	public function testFromJpegReadsTheMarkedRightsProperty()
	{
		// xmpRights:Marked is the only store here, so the status comes from it alone.
		$jpeg = $this->jpeg();
		$xmp = XMP::blank();
		$xmp->setProperty(XMP::NS_RIGHTS, 'Marked', true);
		$jpeg->setXMP($xmp);
		self::assertSame(
			PhotoshopFileInfo::Copyrighted,
			PhotoshopFileInfo::fromJpeg(JPEGImage::fromString($jpeg->toBinary()))['copyrightstatus'],
		);

		// The comparison is case-insensitive, and anything else is public domain.
		$lower = $this->jpeg();
		$lowerXmp = XMP::blank();
		$lowerXmp->setProperty(XMP::NS_RIGHTS, 'Marked', 'true');
		$lower->setXMP($lowerXmp);
		self::assertSame(
			PhotoshopFileInfo::Copyrighted,
			PhotoshopFileInfo::fromJpeg(JPEGImage::fromString($lower->toBinary()))['copyrightstatus'],
		);

		$unmarked = $this->jpeg();
		$unmarkedXmp = XMP::blank();
		$unmarkedXmp->setProperty(XMP::NS_RIGHTS, 'Marked', false);
		$unmarked->setXMP($unmarkedXmp);
		self::assertSame(
			PhotoshopFileInfo::PublicDomain,
			PhotoshopFileInfo::fromJpeg(JPEGImage::fromString($unmarked->toBinary()))['copyrightstatus'],
		);
	}

	public function testInvalidDateThrows()
	{
		$info = new PhotoshopFileInfo();
		$info['date'] = '17/07/2026';
		self::expectException(\InvalidArgumentException::class);
		$info->applyTo($this->jpeg());
	}
}
