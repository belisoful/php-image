<?php

use Belisoful\Image\AVIImage;
use Belisoful\Image\BMFF\BMFFBox;
use Belisoful\Image\BMFF\BMFFFileBox;
use Belisoful\Image\BMFF\BMFFItem;
use Belisoful\Image\BMFFFile;
use Belisoful\Image\Compression\CCITTFaxCompressor;
use Belisoful\Image\GIF\GIFExtension;
use Belisoful\Image\GIF\GIFFrame;
use Belisoful\Image\GIFImage;
use Belisoful\Image\ImageChunk;
use Belisoful\Image\ImageFile;
use Belisoful\Image\ImageGraphics;
use Belisoful\Image\ImageGraphicsGD;
use Belisoful\Image\ImageGraphicsMode;
use Belisoful\Image\JPEGImage;
use Belisoful\Image\JXLImage;
use Belisoful\Image\Meta\EXIF;
use Belisoful\Image\Meta\IPTC;
use Belisoful\Image\Meta\IPTCTags;
use Belisoful\Image\Meta\JFIF;
use Belisoful\Image\Meta\Makernote\CanonMakernote;
use Belisoful\Image\Meta\Makernote\Makernote;
use Belisoful\Image\Meta\XMP;
use Belisoful\Image\PhotoshopIRB;
use Belisoful\Image\PhotoshopResource;
use Belisoful\Image\PNGImage;
use Belisoful\Image\RIFFContainer;
use Belisoful\Image\RIFFList;
use Belisoful\Image\TIFF\TIFFDocument;
use Belisoful\Image\TIFF\TIFFIfd;
use Belisoful\Image\TIFF\TIFFRaster;
use Belisoful\Image\TIFF\TIFFTag;
use Belisoful\Image\TIFFImage;

/**
 * Every class the library creates is named by a class constant and resolved with
 * `static::`, so a subclass that names its own class has that created instead — through
 * the static factories and the instance methods alike.  The first test holds the source to
 * that; the rest follow an override through each container.
 */
class ClassOverrideTest extends PHPUnit\Framework\TestCase
{
	/** The classes that move or transform bytes and are never part of what a caller gets back. */
	private const Plumbing = [
		'BinaryReader', 'BitReader', 'BitWriter', 'SourceRange', 'StreamIO',
		'LZWEncoder', 'LZWDecoder', 'PackBitsEncoder', 'PackBitsDecoder',
		'HorizontalPredictorEncoder', 'HorizontalPredictorDecoder',
		'LZWCompressor', 'PackBitsCompressor', 'GIFLZWCompressor', 'HorizontalPredictor',
		'Photoshop8BIM', 'Utf8Converter', 'EscCharsetConverter',
	];

	/** The static facades: every call on one from another class goes through a constant. */
	private const Facades = ['ImageGraphics', 'TIFFRaster'];

	private static function sourceDirectory(): string
	{
		return dirname((string) (new ReflectionClass(ImageFile::class))->getFileName());
	}

	private static function shortName(string $class): string
	{
		$name = substr($class, (int) strrpos('\\' . $class, '\\'));
		// The same lists serve the sibling repository, whose classes carry a T prefix.
		return $name[0] === 'T' && (in_array(substr($name, 1), self::Plumbing, true) || in_array(substr($name, 1), self::Facades, true)) ? substr($name, 1) : $name;
	}

	private static function isLibraryClass(string $class): bool
	{
		if (!class_exists($class) && !interface_exists($class)) {
			return false;
		}
		return str_starts_with((string) (new ReflectionClass($class))->getFileName(), self::sourceDirectory() . DIRECTORY_SEPARATOR);
	}

	/**
	 * Whether a static method hands back an object of the library, or by its name parses
	 * bytes into several: a factory.
	 * @param string $class
	 * @param string $method
	 */
	private static function isFactory(string $class, string $method): bool
	{
		if (!method_exists($class, $method)) {
			return false;
		}
		if (str_starts_with($method, 'parse')) {
			return true;
		}
		$reflection = new ReflectionMethod($class, $method);
		$type = $reflection->getReturnType();
		if (!$reflection->isStatic() || $type === null) {
			return false;
		}
		foreach ($type instanceof ReflectionNamedType ? [$type] : $type->getTypes() as $named) {
			if (in_array($named->getName(), ['static', 'self'], true) || self::isLibraryClass($named->getName())) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return string[] The places the source creates a library class, calls one of its
	 *   factories, or calls a facade by the name of the class.
	 */
	private function hardCodedClasses(): array
	{
		$found = [];
		$names = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::sourceDirectory(), FilesystemIterator::SKIP_DOTS));
		foreach ($files as $file) {
			$tokens = array_values(array_filter(
				token_get_all((string) file_get_contents($file->getPathname())),
				fn ($token) => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
			));
			$namespace = '';
			$imports = [];
			$declared = null;
			$depth = 0;
			$resolve = function (array $token) use (&$namespace, &$imports): ?string {
				if ($token[0] === T_NAME_FULLY_QUALIFIED) {
					return ltrim($token[1], '\\');
				}
				if (in_array(strtolower($token[1]), ['self', 'static', 'parent'], true)) {
					return null;
				}
				$first = explode('\\', $token[1])[0];
				return isset($imports[$first]) ? $imports[$first] . substr($token[1], strlen($first)) : $namespace . '\\' . $token[1];
			};
			foreach ($tokens as $i => $token) {
				$depth += ($token === '{') - ($token === '}');
				if (!is_array($token)) {
					continue;
				}
				$next = $tokens[$i + 1] ?? null;
				$isName = is_array($next) && in_array($next[0], $names, true);
				$where = basename($file->getPathname()) . ':' . $token[2];
				if ($token[0] === T_NAMESPACE && is_array($next)) {
					$namespace = $next[1];
				} elseif ($token[0] === T_USE && $depth === 0 && $isName) {
					$imports[substr($next[1], (int) strrpos('\\' . $next[1], '\\'))] = ltrim($next[1], '\\');
				} elseif ($token[0] === T_CLASS && $depth === 0 && $declared === null && is_array($next) && $next[0] === T_STRING) {
					$declared = $namespace . '\\' . $next[1];
				} elseif ($token[0] === T_NEW && $isName) {
					// `new self` fixes the class as surely as its name does.
					$class = strtolower($next[1]) === 'self' ? $declared : $resolve($next);
					if ($class !== null && self::isLibraryClass($class) && !in_array(self::shortName($class), self::Plumbing, true)) {
						$found[] = "$where new " . self::shortName($class);
					}
				} elseif (in_array($token[0], $names, true) && is_array($next) && $next[0] === T_DOUBLE_COLON && ($tokens[$i + 3] ?? null) === '('
					&& !(is_array($tokens[$i - 1]) && in_array($tokens[$i - 1][0], [T_DOUBLE_COLON, T_NEW], true))) {
					$class = $resolve($token);
					$method = $tokens[$i + 2][1];
					if ($class === null || $class === $declared || !self::isLibraryClass($class) || in_array(self::shortName($class), self::Plumbing, true)) {
						continue;
					}
					if (in_array(self::shortName($class), self::Facades, true) || self::isFactory($class, $method)) {
						$found[] = "$where " . self::shortName($class) . "::$method()";
					}
				}
			}
		}
		sort($found);
		return $found;
	}

	public function testNoSourceCreatesALibraryClassByItsName()
	{
		// `new EXIF()`, `IPTC::parse()` or `ImageGraphics::decode()` written out in the source
		// is a class no subclass can replace: each is `new (static::ExifClass)()`,
		// `static::IptcClass::parse()`, `static::GraphicsClass::decode()` instead.
		self::assertSame([], $this->hardCodedClasses());
	}

	// ---- the fixtures ----

	private function gd(int $width = 8, int $height = 6): \GdImage
	{
		$image = imagecreatetruecolor($width, $height);
		imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 10, 120, 200));
		return $image;
	}

	private function encoded(string $function): string
	{
		ob_start();
		$function($this->gd());
		return (string) ob_get_clean();
	}

	/** A JPEG holding EXIF, XMP, and IPTC in a Photoshop resource block. */
	private function jpeg(): string
	{
		$jpeg = JPEGImage::fromString($this->encoded('imagejpeg'));
		$exif = new EXIF();
		$exif->setValueByName('Make', 'Own');
		$jpeg->setEXIF($exif);
		$xmp = XMP::blank();
		$xmp->setProperty(XMP::NS_DC, 'title', 'Own');
		$jpeg->setXMP($xmp);
		$irb = new PhotoshopIRB();
		$irb->setResource(new PhotoshopResource(PhotoshopResource::Url, 'https://example.org/'));
		$jpeg->setPhotoshopIRB($irb);
		$iptc = new IPTC();
		$iptc[IPTCTags::ObjectName] = 'Own';
		$jpeg->setIPTC($iptc);
		return $jpeg->toBinary();
	}

	/** @return resource A rewound in-memory resource holding the bytes. */
	private function resource(string $bytes)
	{
		$handle = fopen('php://memory', 'w+b');
		fwrite($handle, $bytes);
		rewind($handle);
		return $handle;
	}

	// ---- the overrides ----

	public function testAContainersCollaboratorsAreTheClassesItsConstantsName()
	{
		$jpeg = TOwnJpeg::fromString($this->jpeg());

		// The EXIF, and down through it the document, its directories and their fields.
		$exif = $jpeg->getEXIF();
		self::assertInstanceOf(TOwnExif::class, $exif);
		self::assertInstanceOf(TOwnTiffDocument::class, $exif->getTiff());
		self::assertInstanceOf(TOwnIfd::class, $exif->getIfd0());
		self::assertContainsOnlyInstancesOf(TOwnTag::class, $exif->getIfd0()->getTags());
		self::assertSame('Own', $exif->getValueByName('Make'));

		self::assertInstanceOf(TOwnXmp::class, $jpeg->getXMP());
		self::assertInstanceOf(TOwnJfif::class, $jpeg->getJFIF());

		// The resource block, its resources, and the IPTC it holds.
		$irb = $jpeg->getPhotoshopIRB();
		self::assertInstanceOf(TOwnIrb::class, $irb);
		self::assertContainsOnlyInstancesOf(TOwnResource::class, $irb->getResources());
		self::assertInstanceOf(TOwnIptc::class, $jpeg->getIPTC());
		self::assertInstanceOf(TOwnIptc::class, $irb->getIPTC());
		self::assertSame('Own', $jpeg->getIPTC()[IPTCTags::ObjectName]);

		// A lazy read names the same classes, and what is written is the same file.
		$lazy = TOwnJpeg::fromStreamLazy($this->resource($this->jpeg()));
		self::assertInstanceOf(TOwnExif::class, $lazy->getEXIF());
		self::assertInstanceOf(TOwnIptc::class, $lazy->getIPTC());
		self::assertSame(bin2hex(JPEGImage::fromString($this->jpeg())->toBinary()), bin2hex($jpeg->toBinary()));

		// The library's own classes are what the library's own container still creates.
		$plain = JPEGImage::fromString($this->jpeg());
		self::assertSame(EXIF::class, $plain->getEXIF()::class);
		self::assertSame(IPTC::class, $plain->getIPTC()::class);
	}

	public function testAnAbstractSubclassOpensTheContainersItNames()
	{
		$jpeg = $this->jpeg();
		$path = (string) tempnam(sys_get_temp_dir(), 'own');
		file_put_contents($path, $jpeg);
		try {
			self::assertInstanceOf(TOwnJpeg::class, TOwnImages::fromString($jpeg));
			self::assertInstanceOf(TOwnJpeg::class, TOwnImages::fromFile($path));
			self::assertInstanceOf(TOwnJpeg::class, TOwnImages::fromStream($this->resource($jpeg)));
		} finally {
			unlink($path);
		}
		// A format it names no container for opens in the library's.
		self::assertSame(PNGImage::class, TOwnImages::fromString($this->encoded('imagepng'))::class);
		// And the library's own base is unchanged.
		self::assertSame(JPEGImage::class, ImageFile::fromString($jpeg)::class);
	}

	public function testAClassThatCreatesItselfCreatesTheSubclassItWasCalledOn()
	{
		$block = "\x1C\x02\x78\x00\x07caption";
		self::assertInstanceOf(TOwnIptc::class, TOwnIptc::parse($block));
		self::assertSame(IPTC::class, IPTC::parse($block)::class);

		$jfif = JPEGImage::fromString($this->encoded('imagejpeg'))->getJFIF()->toBinary();
		self::assertInstanceOf(TOwnJfif::class, TOwnJfif::parse($jfif));
		self::assertSame(JFIF::class, JFIF::parse($jfif)::class);
	}

	public function testChunksListsAndRiffContainers()
	{
		$png = TOwnPng::fromString($this->encoded('imagepng'));
		self::assertContainsOnlyInstancesOf(TOwnChunk::class, $png->getChunks());
		$png->setXmpText('<x:xmpmeta xmlns:x="adobe:ns:meta/"/>');
		self::assertContainsOnlyInstancesOf(TOwnChunk::class, $png->getChunks());
		self::assertContainsOnlyInstancesOf(TOwnChunk::class, TOwnPng::fromStreamLazy($this->resource($this->encoded('imagepng')))->getChunks());

		$avi = TOwnAvi::fromString(ContainerFixtures::avi());
		$chunks = $avi->getRiff()->getChunks();
		self::assertInstanceOf(TOwnRiff::class, $avi->getRiff());
		self::assertNotEmpty($chunks);
		foreach ($chunks as $chunk) {
			self::assertInstanceOf($chunk instanceof RIFFList ? TOwnList::class : TOwnChunk::class, $chunk);
		}
	}

	public function testFramesExtensionsBoxesAndItems()
	{
		$gif = TOwnGif::fromString($this->encoded('imagegif'));
		$gif->addComment('own');
		self::assertContainsOnlyInstancesOf(TOwnFrame::class, $gif->getFrames());
		self::assertContainsOnlyInstancesOf(TOwnExtension::class, $gif->getExtensions());
		self::assertNotEmpty($gif->getExtensions());
		$reread = TOwnGif::fromString($gif->toBinary());
		self::assertContainsOnlyInstancesOf(TOwnExtension::class, $reread->getExtensions());
		self::assertSame(['own'], $reread->getComments());

		$heif = TOwnBmff::fromString(ContainerFixtures::heif());
		self::assertContainsOnlyInstancesOf(TOwnFileBox::class, $heif->getBoxes());
		self::assertContainsOnlyInstancesOf(TOwnItem::class, $heif->getItems());
		self::assertNotEmpty($heif->getItems());

		$jxl = TOwnJxl::fromString(ContainerFixtures::jxl());
		$jxl->setXmpText('<x:xmpmeta xmlns:x="adobe:ns:meta/"/>');
		$boxes = TOwnJxl::fromString($jxl->toBinary())->getBoxes();
		self::assertContainsOnlyInstancesOf(TOwnBox::class, $boxes);
	}

	public function testTheFaxCodecAndTheRasterDecoderOfATiff()
	{
		TOwnFax::$created = 0;
		$tiff = TOwnTiff::fromImage($this->gd(16, 8), TIFFImage::CompressionGroup4);
		self::assertSame(1, TOwnFax::$created, 'the raster was encoded by the codec the TIFF names');
		self::assertInstanceOf(TOwnExif::class, $tiff->getEXIF());

		self::assertInstanceOf(\GdImage::class, $tiff->getImage(ImageGraphicsMode::GD));
		self::assertSame(2, TOwnFax::$created, 'and decoded by the one its raster decoder names');
	}

	public function testTheGraphicsFacadeAndItsLibraries()
	{
		// A facade names the library created for each mode...
		self::assertInstanceOf(TOwnGd::class, TOwnGraphics::getLibrary(ImageGraphicsMode::GD));
		self::assertSame(ImageGraphicsGD::class, ImageGraphics::getLibrary(ImageGraphicsMode::GD)::class);

		// ...and a container names the facade it draws through.
		TOwnGd::$decoded = 0;
		$png = TOwnPng::fromString($this->encoded('imagepng'));
		self::assertInstanceOf(\GdImage::class, $png->getImage(ImageGraphicsMode::GD));
		self::assertSame(1, TOwnGd::$decoded);
		PNGImage::fromString($this->encoded('imagepng'))->getImage(ImageGraphicsMode::GD);
		self::assertSame(1, TOwnGd::$decoded, 'the library\'s own container still draws through the library\'s own facade');
	}

	public function testMakerNoteClasses()
	{
		$class = new ReflectionMethod(Makernote::class, 'makerClass');
		$class->setAccessible(true);

		self::assertSame(CanonMakernote::class, $class->invoke(null, 'Canon'));
		self::assertSame(Makernote::class, $class->invoke(null, 'Somebody'));
		// A maker's own class answers with the general one for any other maker.
		self::assertSame(Makernote::class, (new ReflectionMethod(CanonMakernote::class, 'makerClass'))->invoke(null, 'Somebody'));

		$own = new ReflectionMethod(TOwnMakernote::class, 'makerClass');
		$own->setAccessible(true);
		self::assertSame(TOwnCanon::class, $own->invoke(null, 'Canon'));
		self::assertSame(TOwnMakernote::class, $own->invoke(null, 'Somebody'));

		// A class registered at run time still comes first.
		Makernote::registerMakerClass('Canon', Makernote::class);
		try {
			self::assertSame(Makernote::class, $own->invoke(null, 'Canon'));
		} finally {
			Makernote::registerMakerClass('Canon', null);
		}
	}
}

class TOwnTag extends TIFFTag
{
}

class TOwnIfd extends TIFFIfd
{
	public const TagClass = TOwnTag::class;
}

class TOwnTiffDocument extends TIFFDocument
{
	public const IfdClass = TOwnIfd::class;
	public const TagClass = TOwnTag::class;
}

class TOwnIptc extends IPTC
{
}

class TOwnXmp extends XMP
{
}

class TOwnResource extends PhotoshopResource
{
}

class TOwnIrb extends PhotoshopIRB
{
	public const IptcClass = TOwnIptc::class;
	public const ResourceClass = TOwnResource::class;
}

class TOwnExif extends EXIF
{
	public const TiffDocumentClass = TOwnTiffDocument::class;
	public const IfdClass = TOwnIfd::class;
	public const IptcClass = TOwnIptc::class;
	public const XmpClass = TOwnXmp::class;
	public const IrbClass = TOwnIrb::class;
}

class TOwnJfif extends JFIF
{
}

class TOwnJpeg extends JPEGImage
{
	public const ExifClass = TOwnExif::class;
	public const XmpClass = TOwnXmp::class;
	public const IptcClass = TOwnIptc::class;
	public const IrbClass = TOwnIrb::class;
	public const JfifClass = TOwnJfif::class;
}

abstract class TOwnImages extends ImageFile
{
	public const JpegClass = TOwnJpeg::class;
}

class TOwnChunk extends ImageChunk
{
}

class TOwnRiff extends RIFFContainer
{
	public const ChunkClass = TOwnChunk::class;
	public const ListClass = TOwnList::class;
}

class TOwnList extends RIFFList
{
	public const RiffClass = TOwnRiff::class;
}

class TOwnGd extends ImageGraphicsGD
{
	public static int $decoded = 0;

	public function decode(string $bytes): false|\GdImage
	{
		self::$decoded++;
		return parent::decode($bytes);
	}
}

class TOwnGraphics extends ImageGraphics
{
	public const GdLibraryClass = TOwnGd::class;
}

class TOwnPng extends PNGImage
{
	public const ChunkClass = TOwnChunk::class;
	public const GraphicsClass = TOwnGraphics::class;
}

class TOwnAvi extends AVIImage
{
	public const RiffClass = TOwnRiff::class;
	public const ChunkClass = TOwnChunk::class;
	public const ListClass = TOwnList::class;
}

class TOwnFrame extends GIFFrame
{
}

class TOwnExtension extends GIFExtension
{
}

class TOwnGif extends GIFImage
{
	public const FrameClass = TOwnFrame::class;
	public const ExtensionClass = TOwnExtension::class;
}

class TOwnFileBox extends BMFFFileBox
{
}

class TOwnItem extends BMFFItem
{
}

class TOwnBmff extends BMFFFile
{
	public const BoxClass = TOwnFileBox::class;
	public const ItemClass = TOwnItem::class;
}

class TOwnBox extends BMFFBox
{
}

class TOwnJxl extends JXLImage
{
	public const BoxClass = TOwnBox::class;
}

class TOwnFax extends CCITTFaxCompressor
{
	public static int $created = 0;

	public function __construct(int $columns = 1728, int $mode = self::Group4)
	{
		self::$created++;
		parent::__construct($columns, $mode);
	}
}

class TOwnRaster extends TIFFRaster
{
	public const FaxCompressorClass = TOwnFax::class;
}

class TOwnTiff extends TIFFImage
{
	public const ExifClass = TOwnExif::class;
	public const FaxCompressorClass = TOwnFax::class;
	public const RasterClass = TOwnRaster::class;
}

class TOwnCanon extends CanonMakernote
{
}

class TOwnMakernote extends Makernote
{
	public const MakerClasses = ['Canon' => TOwnCanon::class];
	public const GenericClass = self::class;
}
