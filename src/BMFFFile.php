<?php

/**
 * BMFFFile class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image;

use Belisoful\Image\BMFF\BMFFBox;
use Belisoful\Image\BMFF\BMFFFileBox;
use Belisoful\Image\BMFF\BMFFItem;
use Belisoful\Image\Meta\EXIF;
use Belisoful\Image\Meta\IPTC;
use Belisoful\Image\Meta\XMP;

/**
 * BMFFFile class.
 *
 * Reads and writes the metadata of an ISO base media file (ISO/IEC 14496-12) — a HEIF or
 * AVIF still, an MP4, or a QuickTime movie — told apart by the brand in its `ftyp` box.  The
 * media is never decoded; the file is opened to read or edit what it says about itself.
 *
 * Two quite different carriers live in this one format, and both are supported:
 *
 * - **The Adobe `uuid` box** (`BE7ACFCB-97A9-42E8-9C71-999491E3AFAC`), which is where MP4
 *   and QuickTime put an XMP packet.  It is an ordinary top-level box.
 * - **Items**, which is how HEIF and AVIF store metadata: `iinf` names an `Exif` or `mime`
 *   item and `iloc` says where its bytes are, usually an absolute offset into `mdat` far
 *   from the description.  {@see getItems()} joins the two.
 *
 * **Nothing in the file ever moves.**  `iloc` extents, `stco`/`co64` chunk offsets and any
 * OpenDML-style index all address absolute positions, so the writer holds every existing
 * byte where it is:
 *
 * - a new top-level box is appended past everything;
 * - a top-level box that already exists is rewritten **in its own slot whenever it fits** —
 *   the same length, or shorter with a `free` box filling what is left over — and otherwise
 *   its slot is vacated to `free` and the box looks for room among the file's **other** free
 *   space — already-set-aside bytes that cost nothing to fill — before resorting to the end.
 *   What is left over has to hold a `free` box's own eight-byte header, so a box that frees
 *   one to seven bytes cannot reuse a slot even though it is smaller;
 * - **an item's bytes are appended in a fresh `mdat` and its `iloc` extent is patched in
 *   place**, which is possible precisely because patching two numbers does not change
 *   `iloc`'s length.  If the new offset or length would not fit the widths `iloc` already
 *   uses, the write throws instead of silently widening a field and moving the file.
 *
 * Not modelled, and so refused rather than half-done: IPTC, which the format has no carrier
 * for; and the ICC profile, which a HEIF keeps in a `colr` box inside the item-property
 * boxes (`iprp`/`ipco`) with `ipma` associations that this class does not rewrite.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @see https://www.iso.org/standard/83102.html ISO/IEC 14496-12 (ISO base media file format)
 */
class BMFFFile extends ImageFile
{
	/** The file-type box, whose brand says which flavour of the format this is. */
	public const FileTypeBox = 'ftyp';

	/** The metadata box holding the item description and location boxes. */
	public const MetaBox = 'meta';

	/** The item information box, holding one `infe` entry per item. */
	public const ItemInfoBox = 'iinf';

	/** The item information entry box, describing one item. */
	public const ItemInfoEntryBox = 'infe';

	/** The item location box, saying where each item's bytes are. */
	public const ItemLocationBox = 'iloc';

	/** The item data box, which an item may be located within. */
	public const ItemDataBox = 'idat';

	/** The media data box. */
	public const MediaDataBox = 'mdat';

	/** The movie box, which holds a QuickTime or MP4 file's structure. */
	public const MovieBox = 'moov';

	/** The user data box. */
	public const UserDataBox = 'udta';

	/** The free-space box, which the writer pads a vacated slot with. */
	public const FreeBox = 'free';

	/** The item properties box, holding the property container and its associations. */
	public const ItemPropertiesBox = 'iprp';

	/** The item property container, whose children are indexed from one in order. */
	public const ItemPropertyContainerBox = 'ipco';

	/** The item property association box. */
	public const ItemPropertyAssociationBox = 'ipma';

	/** The primary item box, naming the item an ICC profile belongs to. */
	public const PrimaryItemBox = 'pitm';

	/** The item reference box, which links a metadata item to the picture it describes. */
	public const ItemReferenceBox = 'iref';

	/** The reference type that says "this item describes that one", which Exif and XMP use. */
	public const ContentDescribes = 'cdsc';

	/** The colour information box, which is where a still keeps its ICC profile. */
	public const ColourBox = 'colr';

	/** The colour type of an unrestricted ICC profile. */
	public const ColourTypeProfile = 'prof';

	/** The colour type of a restricted ICC profile. */
	public const ColourTypeRestrictedProfile = 'rICC';

	/** The widest property index a one-byte association can hold. */
	public const MaxShortPropertyIndex = 0x7F;

	/**
	 * The smallest box that can exist: a size and a type, with no payload.  A slot that frees
	 * fewer bytes than this cannot hold the `free` box that would keep the media still, so
	 * such a write has to move instead.
	 */
	public const MinimumBox = 8;

	/** The other free-space box, which QuickTime writes instead. */
	public const SkipBox = 'skip';

	/** The iTunes metadata list, inside `moov`/`udta`/`meta`. */
	public const ItemListBox = 'ilst';

	/** The value box inside an iTunes metadata key. */
	public const DataBox = 'data';

	/** The handler box that names what a `meta` box holds. */
	public const HandlerBox = 'hdlr';

	/** The `data` type indicator for UTF-8 text, the only one these accessors read. */
	public const TextTypeIndicator = 1;

	/** The packed language code for "undefined", which QuickTime writers use. */
	public const UndefinedLanguage = 0x55C4;

	// The user-data keys both conventions share, spelled with the copyright sign the
	// format uses.  The vocabulary is open: any four-character key is read and written.
	public const KeyTitle = "\xA9nam";
	public const KeyArtist = "\xA9ART";
	public const KeyAlbum = "\xA9alb";
	public const KeyComment = "\xA9cmt";
	public const KeyDate = "\xA9day";
	public const KeyEncoder = "\xA9too";
	public const KeyCopyright = "\xA9cpy";
	public const KeyWriter = "\xA9wrt";
	public const KeyGenre = "\xA9gen";
	public const KeyDescription = "\xA9des";

	/**
	 * The GPS coordinates a QuickTime recorder writes, as an ISO 6709 string.  This is the
	 * one user-data key that says where a video was taken, so it is the {@see
	 * PrivacyCategory::Location} entry below.
	 */
	public const KeyLocation = "\xA9xyz";

	/**
	 * The user-data keys each {@see PrivacyCategory} removes.  Only identifying fields are
	 * listed: nothing that describes the picture is touched.
	 */
	protected const PrivacyKeys = [
		PrivacyCategory::Location => [self::KeyLocation],
		PrivacyCategory::Author => [self::KeyArtist, self::KeyCopyright, self::KeyWriter],
		PrivacyCategory::Description => [self::KeyTitle, self::KeyComment, self::KeyDescription, self::KeyAlbum, self::KeyGenre],
		PrivacyCategory::Timestamp => [self::KeyDate],
		PrivacyCategory::Software => [self::KeyEncoder],
	];

	/** Adobe's user type for the `uuid` box that carries an XMP packet. */
	public const XmpUuid = "\xBE\x7A\xCF\xCB\x97\xA9\x42\xE8\x9C\x71\x99\x94\x91\xE3\xAF\xAC";

	/** The brands this class names; any other is reported as the generic format. */
	public const BrandFormats = [
		'heic' => 'HEIF', 'heix' => 'HEIF', 'hevc' => 'HEIF', 'mif1' => 'HEIF', 'msf1' => 'HEIF',
		'avif' => 'AVIF', 'avis' => 'AVIF',
		'qt  ' => 'MOV',
		'isom' => 'MP4', 'mp41' => 'MP4', 'mp42' => 'MP4', 'M4V ' => 'MP4', 'M4A ' => 'MP4',
	];

	/** @var array<int, BMFFBox> The top-level boxes, in file order. */
	private array $_boxes = [];

	/** @var string Trailing bytes that are not a whole box, kept for a faithful rewrite. */
	private string $_remainder = '';

	/** @var ?array<int, BMFFItem> The items of the `meta` box, or null until read. */
	private ?array $_items = null;

	/**
	 * Returns the format name, from the `ftyp` brand.
	 * @return string The format name (`HEIF`, `AVIF`, `MP4`, `MOV`, or `BMFF`).
	 */
	public function getFormat(): string
	{
		return self::BrandFormats[$this->getBrand()] ?? 'BMFF';
	}

	/**
	 * Indicates whether the bytes are an ISO base media file: a `ftyp` box at the very
	 * start, which every brand of the format begins with.
	 * @param string $data The candidate bytes.
	 * @return bool Whether the data is an ISO base media file.
	 */
	public static function isBMFF(string $data): bool
	{
		if (strlen($data) < 16 || substr($data, 4, 4) !== self::FileTypeBox) {
			return false;
		}
		$length = (int) unpack('N', substr($data, 0, 4))[1];
		return $length >= 16 && $length <= strlen($data);
	}

	/**
	 * Returns the major brand of the `ftyp` box.
	 * @return string The brand, empty when there is no readable `ftyp`.
	 */
	public function getBrand(): string
	{
		return substr((string) $this->getBox(self::FileTypeBox)?->getPayload(), 0, 4);
	}

	/**
	 * Returns the compatible brands the `ftyp` box lists after the major brand and the minor
	 * version.
	 * @return array<int, string> The compatible brands.
	 */
	public function getCompatibleBrands(): array
	{
		$payload = (string) $this->getBox(self::FileTypeBox)?->getPayload();
		return str_split(substr($payload, 8, intdiv(max(0, strlen($payload) - 8), 4) * 4) ?: '', 4);
	}

	/**
	 * Returns the top-level boxes in file order.
	 * @return array<int, BMFFBox> The boxes.
	 */
	public function getBoxes(): array
	{
		return $this->_boxes;
	}

	/**
	 * Replaces the top-level boxes wholesale.
	 * @param array<int, BMFFBox> $value The boxes in file order.
	 */
	public function setBoxes(array $value): void
	{
		$this->_boxes = array_values($value);
		$this->_items = null;
	}

	/**
	 * Returns the first top-level box of a type.
	 * @param string $type The four-character box type.
	 * @return ?BMFFBox The box, or null when absent.
	 */
	public function getBox(string $type): ?BMFFBox
	{
		foreach ($this->_boxes as $box) {
			if ($box->getType() === $type) {
				return $box;
			}
		}
		return null;
	}

	//
	// ─── Items ───────────────────────────────────────────────────────────────
	//

	/**
	 * Returns the items of the `meta` box, each joining its `infe` description to its `iloc`
	 * location.  A file with no `meta` box has none.
	 * @return array<int, BMFFItem> The items, keyed from zero in `iinf` order.
	 */
	public function getItems(): array
	{
		if ($this->_items === null) {
			$this->_items = $this->readItems();
		}
		return $this->_items;
	}

	/**
	 * Returns the first item of a type.
	 * @param string $type The four-character item type (e.g. {@see BMFFItem::ExifType}).
	 * @return ?BMFFItem The item, or null when absent.
	 */
	public function getItem(string $type): ?BMFFItem
	{
		foreach ($this->getItems() as $item) {
			if ($item->getType() === $type) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * Returns the bytes an item locates, reading them from the composed file.
	 * @param BMFFItem $item The item.
	 * @return ?string The bytes, or null when the location cannot be resolved.
	 */
	public function getItemData(BMFFItem $item): ?string
	{
		if ($item->getLength() === 0) {
			return null;
		}
		if ($item->getConstructionMethod() === BMFFItem::IdatConstruction) {
			$idat = $this->getMetaBox()?->getChild(self::ItemDataBox)?->getPayload();
			return $idat === null ? null : (substr($idat, $item->getOffset(), $item->getLength()) ?: null);
		}
		if ($item->getConstructionMethod() !== BMFFItem::FileConstruction) {
			return null;   // offsets relative to another item are not resolved
		}
		$data = substr($this->compose(), $item->getOffset(), $item->getLength());
		return $data === '' ? null : $data;
	}

	/**
	 * Replaces the bytes an item locates: the new bytes are appended to the file in a fresh
	 * `mdat` and the item's `iloc` extent is patched to point at them, so every byte that
	 * was already in the file stays exactly where it was.
	 * @param BMFFItem $item The item to repoint.
	 * @param string $data The new bytes.
	 * @throws \RuntimeException When the item cannot be repointed in place, or the new offset or
	 *   length would not fit the field widths `iloc` already uses.
	 */
	public function setItemData(BMFFItem $item, string $data): void
	{
		$meta = $this->getMetaBox();
		$iloc = $meta?->getChild(self::ItemLocationBox);
		if ($meta === null || $iloc === null || !$item->getIsWritable()) {
			throw new \RuntimeException(sprintf('The %s item cannot be repointed in place: it has no single file-located extent.', $item->getType()));
		}
		// The payload lands in a new mdat appended at the end; its offset is where that
		// box's payload will begin once the current bytes are composed.
		$offset = strlen($this->compose()) + 8;
		if (!$item->fits($offset, strlen($data))) {
			throw new \RuntimeException(sprintf("The %s item's new location does not fit the field widths iloc already uses.", $item->getType()));
		}
		$iloc->setPayload($item->writeLocation($iloc->getPayload(), $offset, strlen($data)));
		$this->_boxes[] = new BMFFFileBox(self::MediaDataBox, $data);
	}

	/**
	 * Adds an item: an `infe` entry describing it, an `iloc` extent locating it, and — when
	 * the file names a primary item — an `iref`/`cdsc` reference saying the new item describes
	 * that picture, which is how a reader knows an `Exif` or XMP item belongs to the image.
	 *
	 * All three live in `meta`, so this resizes it and moves the media; that is corrected the
	 * same way {@see setICCProfile()} corrects it, and is allowed only for a still.  The bytes
	 * themselves are appended in a fresh `mdat` afterwards, through {@see setItemData()}.
	 * @param string $type The four-character item type (e.g. {@see BMFFItem::ExifType}).
	 * @param string $data The item's bytes.
	 * @param string $name The item name.
	 * @param string $contentType The content type, for a {@see BMFFItem::MimeType} item.
	 * @throws \RuntimeException When the file has no `meta` box, is not a still, or has no item id
	 *   left that an entry can describe.
	 * @return BMFFItem The item that was added.
	 */
	public function addItem(string $type, string $data, string $name = '', string $contentType = ''): BMFFItem
	{
		$before = $this->compose();
		$id = $this->nextItemId();
		$primary = $this->getPrimaryItemId();
		$this->resizeMeta(function () use ($type, $name, $contentType, $id, $primary): void {
			$meta = $this->getMetaBox();
			if ($meta === null) {
				throw new \RuntimeException('The file has no meta box to add an item to.');
			}
			$this->appendItemInfo($meta, $id, $type, $name, $contentType);
			$this->appendItemLocation($meta, $id);
			if ($primary !== null && $primary !== $id) {
				$this->appendItemReference($meta, $id, $primary);
			}
		});
		$item = $this->getItemById($id);
		if ($item === null) {
			// An entry describes its item in sixteen bits, so a file already holding 65535
			// items has no id left to give.
			$this->setBoxes(BMFFFileBox::parseBoxes($before));
			throw new \RuntimeException(sprintf('The new item id %d does not fit the sixteen-bit field an item entry uses.', $id));
		}
		$this->setItemData($item, $data);
		return $item;
	}

	/**
	 * Returns the item of an id.
	 * @param int $id The item id.
	 * @return ?BMFFItem The item, or null when absent.
	 */
	public function getItemById(int $id): ?BMFFItem
	{
		foreach ($this->getItems() as $item) {
			if ($item->getId() === $id) {
				return $item;
			}
		}
		return null;
	}

	//
	// ─── The metadata carriers ───────────────────────────────────────────────
	//

	/**
	 * Returns the XMP packet text, from the Adobe `uuid` box a movie carries or from the
	 * `mime` item a HEIF does.
	 * @return ?string The packet text, or null when absent.
	 */
	public function getXmpText(): ?string
	{
		foreach ($this->_boxes as $box) {
			if ($box->getUserType() === self::XmpUuid) {
				return $box->getUserPayload();
			}
		}
		foreach ($this->getItems() as $item) {
			if ($item->getIsXmp()) {
				return $this->getItemData($item);
			}
		}
		return null;
	}

	/**
	 * Sets (or removes, when null) the XMP packet text, in whichever carrier suits the file:
	 * an existing XMP item is rewritten as an item, a still with a primary item gains one
	 * (the form HEIF defines), and anything else — a movie — uses the Adobe `uuid` box.
	 * @param ?string $xmp The packet text, or null to drop it.
	 * @throws \RuntimeException When an XMP item cannot be repointed in place.
	 */
	public function setXmpText(?string $xmp): void
	{
		foreach ($this->getItems() as $item) {
			if ($item->getIsXmp()) {
				$this->setItemData($item, (string) $xmp);
				return;
			}
		}
		if ($xmp !== null && $this->getBox(self::MovieBox) === null && $this->getPrimaryItemId() !== null) {
			// A still keeps XMP the way HEIF defines it, as a MIME item beside the picture.
			$this->addItem(BMFFItem::MimeType, $xmp, '', BMFFItem::XmpContentType);
			return;
		}
		$this->setUuidBox(self::XmpUuid, $xmp);
	}

	/**
	 * Returns the parsed XMP packet.
	 * @return ?XMP The XMP, or null when absent or unparsable.
	 */
	public function getXMP(): ?XMP
	{
		$text = $this->getXmpText();
		if ($text === null) {
			return null;
		}
		$xmp = XMP::parse($text);
		return $xmp === false ? null : $xmp;
	}

	/**
	 * Sets (or removes, when null) the XMP packet.
	 * @param ?XMP $xmp The XMP, or null to drop it.
	 */
	public function setXMP(?XMP $xmp): void
	{
		$this->setXmpText($xmp?->toPacketText());
	}

	/**
	 * Returns the EXIF metadata of the `Exif` item, stepping over the four-byte offset that
	 * precedes the TIFF header.
	 * @return ?EXIF The EXIF, or null when absent or unparsable.
	 */
	public function getEXIF(): ?EXIF
	{
		$item = $this->getItem(BMFFItem::ExifType);
		$payload = $item === null ? null : $this->getItemData($item);
		if ($payload === null || strlen($payload) < 4) {
			return null;
		}
		$tiff = substr($payload, 4 + (int) unpack('N', substr($payload, 0, 4))[1]);
		if ($tiff === '') {
			return null;
		}
		try {
			$exif = EXIF::fromTiffString($tiff);
		} catch (\RuntimeException $e) {
			return null;
		}
		$exif->setSignature('');
		return $exif;
	}

	/**
	 * Sets the EXIF metadata of the `Exif` item, behind the zero TIFF-header offset every
	 * writer uses.  A file with no `Exif` item gains one through {@see addItem()}.
	 * @param ?EXIF $exif The EXIF, or null to empty the item.
	 * @throws \RuntimeException When the file is not a still, so an item cannot be added.
	 */
	public function setEXIF(?EXIF $exif): void
	{
		$item = $this->getItem(BMFFItem::ExifType);
		if ($item === null && $exif === null) {
			return;
		}
		$exif?->setSignature('');
		$payload = $exif === null ? '' : pack('N', 0) . $exif->toBinary();
		if ($item === null) {
			$this->addItem(BMFFItem::ExifType, $payload, BMFFItem::ExifType);
			return;
		}
		$this->setItemData($item, $payload);
	}

	//
	// ─── User data: the two conventions a movie may use ──────────────────────
	//

	/**
	 * Returns the classic QuickTime user-data text atoms of `moov`/`udta`, each keyed by its
	 * four-character type.  An atom's payload is one or more `[length][language][text]`
	 * entries; the first is read.
	 * @return array<string, string> The atoms, empty when there are none.
	 */
	public function getUserDataAtoms(): array
	{
		$atoms = [];
		foreach ($this->getUserDataBox()?->getChildren() ?? [] as $atom) {
			if ($atom->getType() === self::MetaBox) {
				continue;   // the iTunes list, not a text atom
			}
			$payload = $atom->getPayload();
			if (strlen($payload) >= 4) {
				$length = (int) unpack('n', substr($payload, 0, 2))[1];
				$atoms[$atom->getType()] = substr($payload, 4, $length);
			}
		}
		return $atoms;
	}

	/**
	 * Sets (or removes, when null) one QuickTime user-data text atom, keeping the language of
	 * an atom that is already there.
	 * @param string $type The four-character atom type (e.g. {@see KeyTitle}).
	 * @param ?string $value The text, or null to remove the atom.
	 * @throws \RuntimeException When the file has no movie box, or the size change cannot be
	 *   absorbed without moving the media.
	 */
	public function setUserDataAtom(string $type, ?string $value): void
	{
		$this->rewriteMovie(function () use ($type, $value): void {
			$udta = $this->requireUserDataBox();
			$language = self::UndefinedLanguage;
			$children = [];
			foreach ($udta->getChildren() as $atom) {
				if ($atom->getType() !== $type) {
					$children[] = $atom;
					continue;
				}
				if (strlen($atom->getPayload()) >= 4) {
					$language = (int) unpack('n', substr($atom->getPayload(), 2, 2))[1];
				}
			}
			if ($value !== null) {
				$children[] = new BMFFFileBox($type, pack('n', strlen($value)) . pack('n', $language) . $value);
			}
			$udta->setChildren($children);
		});
	}

	/**
	 * Returns the iTunes-style tags of `moov`/`udta`/`meta`/`ilst`, each keyed by its
	 * four-character key.  Only UTF-8 text values are returned: a key holding cover art or a
	 * number is skipped rather than handed back as bytes.
	 * @return array<string, string> The tags, empty when there are none.
	 */
	public function getItunesTags(): array
	{
		$tags = [];
		foreach ($this->getItemListBox()?->getChildren() ?? [] as $key) {
			foreach (BMFFFileBox::parseBoxes($key->getPayload()) as $data) {
				if ($data->getType() !== self::DataBox || strlen($data->getPayload()) < 8) {
					continue;
				}
				if ((int) unpack('N', substr($data->getPayload(), 0, 4))[1] === self::TextTypeIndicator) {
					$tags[$key->getType()] = substr($data->getPayload(), 8);
					break;
				}
			}
		}
		return $tags;
	}

	/**
	 * Sets (or removes, when null) one iTunes-style tag, creating the `meta`/`ilst` chain
	 * when the file has none.
	 * @param string $key The four-character key (e.g. {@see KeyTitle}).
	 * @param ?string $value The text, or null to remove the key.
	 * @throws \RuntimeException When the file has no movie box, or the size change cannot be
	 *   absorbed without moving the media.
	 */
	public function setItunesTag(string $key, ?string $value): void
	{
		$this->rewriteMovie(function () use ($key, $value): void {
			$ilst = $this->requireItemListBox();
			$children = array_values(array_filter($ilst->getChildren(), fn ($k) => $k->getType() !== $key));
			if ($value !== null) {
				$data = new BMFFFileBox(self::DataBox, pack('N', self::TextTypeIndicator) . pack('N', 0) . $value);
				$children[] = new BMFFFileBox($key, $data->toBinary());
			}
			$ilst->setChildren($children);
		});
	}

	/**
	 * Returns one user-data value, looking in whichever convention the file uses: the iTunes
	 * list first, then the QuickTime text atoms.
	 * @param string $key The four-character key (e.g. {@see KeyTitle}).
	 * @return ?string The text, or null when neither convention carries it.
	 */
	public function getUserDataValue(string $key): ?string
	{
		return $this->getItunesTags()[$key] ?? $this->getUserDataAtoms()[$key] ?? null;
	}

	/**
	 * Sets (or removes, when null) one user-data value in the convention the file already
	 * uses.  A file that uses neither is written in the one its brand implies: a QuickTime
	 * movie gets a text atom, anything else gets an iTunes tag.  Removing clears both, so a
	 * value cannot survive in the convention that was not chosen.
	 * @param string $key The four-character key (e.g. {@see KeyTitle}).
	 * @param ?string $value The text, or null to remove it.
	 * @throws \RuntimeException When the file has no movie box, or the size change cannot be
	 *   absorbed without moving the media.
	 */
	public function setUserDataValue(string $key, ?string $value): void
	{
		$hasAtom = isset($this->getUserDataAtoms()[$key]);
		$hasTag = isset($this->getItunesTags()[$key]);
		if ($value === null) {
			if ($hasAtom) {
				$this->setUserDataAtom($key, null);
			}
			if ($hasTag) {
				$this->setItunesTag($key, null);
			}
			return;
		}
		if ($hasAtom || (!$hasTag && $this->getBrand() === 'qt  ')) {
			$this->setUserDataAtom($key, $value);
			return;
		}
		$this->setItunesTag($key, $value);
	}

	/**
	 * Returns no IPTC: the ISO base media file format has no carrier for IIM records.
	 * @return ?IPTC Always null.
	 */
	public function getIPTC(): ?IPTC
	{
		return null;
	}

	/**
	 * Refuses an IPTC record set: the format defines items and the Adobe `uuid` box, and no
	 * IIM one.  Rather than accept data it would drop on {@see save()}, this throws — put the
	 * equivalent properties in {@see setXMP() XMP}.
	 * @param ?IPTC $iptc The IPTC record set; only null is accepted.
	 * @throws \RuntimeException When an IPTC record set is given.
	 */
	public function setIPTC(?IPTC $iptc): void
	{
		if ($iptc !== null) {
			throw new \RuntimeException('An ISO base media file has no IPTC carrier; put the equivalent properties in XMP.');
		}
	}

	/**
	 * Returns the ICC profile of the `colr` item property.  A `colr` box holding `nclx`
	 * describes a colour space by its coefficients rather than carrying a profile, so it
	 * reads as none.
	 * @return ?string The profile bytes, or null when the file carries no profile.
	 */
	public function getICCProfile(): ?string
	{
		$colr = $this->findColourBox();
		return $colr === null ? null : substr($colr->getPayload(), 4);
	}

	/**
	 * Sets (or removes, when null) the ICC profile, writing a `colr` box of the unrestricted
	 * `prof` type among the item properties and associating it with the primary item.
	 *
	 * A profile of exactly the length the file already holds is written in place and nothing
	 * moves.  Any other change resizes `meta`, which shifts the media — so it is allowed only
	 * for a **still**, where `iloc` is provably the whole set of absolute references and every
	 * one of them is corrected; a file with a movie box is refused instead, because `stco`,
	 * `saio` and the fragment offsets would also need correcting.
	 * @param ?string $profile The profile bytes, or null to remove the profile.
	 * @throws \RuntimeException When the file has no item properties to write into, when it has a
	 *   movie box, or when a corrected item location would not fit its stored field width.
	 */
	public function setICCProfile(?string $profile): void
	{
		$colr = $this->findColourBox();
		if ($profile === null) {
			if ($colr !== null) {
				$this->removeColourInPlace($colr);
			}
			return;
		}
		if ($this->fitColourInPlace($colr, $profile)) {
			return;
		}
		$this->resizeMeta(function () use ($profile): void {
			[$iprp, $ipco] = $this->requireProperties();
			// Re-found rather than captured: a failed in-place attempt restores the boxes,
			// which replaces every object in the tree.
			$colr = $this->findColourBox();
			if ($colr !== null) {
				$colr->setPayload(self::ColourTypeProfile . $profile);
				return;
			}
			$item = $this->getPrimaryItemId();
			if ($item === null) {
				throw new \RuntimeException('The file names no primary item to associate an ICC profile with.');
			}
			$ipco->addChild(new BMFFFileBox(self::ColourBox, self::ColourTypeProfile . $profile));
			$this->associateProperty($iprp, $item, count($ipco->getChildren()));
		});
	}

	//
	// ─── Reading and writing ─────────────────────────────────────────────────
	//

	/**
	 * Writes the file to a target.
	 * @param mixed $target A writable {@see \Psr\Http\Message\StreamInterface} or PHP stream resource.
	 * @return int The number of bytes written.
	 */
	public function streamTo(mixed $target): int
	{
		return $this->writeTo($target);
	}

	/**
	 * Rebuilds the file from its boxes.
	 * @return string The composed bytes.
	 */
	protected function compose(): string
	{
		$bytes = '';
		foreach ($this->_boxes as $box) {
			$bytes .= $box->toBinary();
		}
		return $bytes . $this->_remainder;
	}

	/**
	 * Parses the box tree.
	 * @throws \UnexpectedValueException When the bytes do not start with a `ftyp` box.
	 */
	protected function parse(): void
	{
		$bytes = $this->getBytesDirect();
		if (!self::isBMFF($bytes)) {
			throw new \UnexpectedValueException(sprintf('ISO base media data is invalid: %s.', 'missing a leading ftyp box'));
		}
		[$this->_boxes, $this->_remainder] = BMFFFileBox::parseSequence($bytes);
	}

	/**
	 * Returns the `meta` box, which HEIF puts at the top level and a movie puts inside
	 * `moov`/`udta`.
	 * @return ?BMFFBox The `meta` box, or null when absent.
	 */
	protected function getMetaBox(): ?BMFFBox
	{
		return $this->getBox(self::MetaBox)
			?? $this->getBox(self::MovieBox)?->getChild(self::UserDataBox)?->getChild(self::MetaBox);
	}

	/**
	 * Stores (or drops, when null) a top-level `uuid` box of a user type, holding every
	 * existing byte where it is: a box that **fits its own slot** — the same length, or shorter
	 * with a `free` box filling the remainder — is rewritten there, and anything that does not
	 * fit vacates the slot to `free` and is appended at the end.  Fitting has a floor, since
	 * what is left over must hold a `free` box's own header.
	 * @param string $userType The 16-byte user type.
	 * @param ?string $payload The content after the user type, or null to drop the box.
	 */
	protected function setUuidBox(string $userType, ?string $payload): void
	{
		$index = null;
		foreach ($this->_boxes as $i => $box) {
			if ($box->getUserType() === $userType) {
				$index = $i;
				break;
			}
		}
		$replacement = $payload === null ? null : BMFFFileBox::uuidBox($userType, $payload);
		if ($index === null) {
			if ($replacement !== null && !$this->placeInFreeSpace($replacement)) {
				$this->_boxes[] = $replacement;   // the end is past every offset in the file
			}
			return;
		}
		$slot = strlen($this->_boxes[$index]->toBinary());
		$gap = $replacement === null ? null : self::slotGap($slot, strlen($replacement->toBinary()));
		if ($gap !== null) {
			// It fits where it is, so nothing later moves; the remainder becomes free space.
			$this->_boxes[$index] = $replacement;
			if ($gap > 0) {
				array_splice($this->_boxes, $index + 1, 0, [$this->freeBox($gap - self::MinimumBox)]);
			}
			return;
		}
		// It outgrew its own slot, so the slot becomes free space and the box looks for room
		// among the file's other free space before resorting to the end.
		$this->_boxes[$index] = $this->freeBox($slot - self::MinimumBox);
		$this->mergeAdjacentFreeSpace();
		if ($replacement !== null && !$this->placeInFreeSpace($replacement)) {
			$this->_boxes[] = $replacement;
		}
	}

	/**
	 * Writes a box into the file's existing free space, if any of it is the right size.  A
	 * `free` box is space the file has already set aside, so filling it moves nothing: every
	 * byte before and after stays exactly where it was.  The smallest run that can take the
	 * box is used, leaving the larger ones for a larger write later.
	 * @param BMFFBox $box The box to place.
	 * @return bool Whether a free run took it.
	 */
	protected function placeInFreeSpace(BMFFBox $box): bool
	{
		$want = strlen($box->toBinary());
		$best = null;
		$bestGap = null;
		foreach ($this->_boxes as $i => $candidate) {
			if (!in_array($candidate->getType(), [self::FreeBox, self::SkipBox], true)) {
				continue;
			}
			$gap = self::slotGap(strlen($candidate->toBinary()), $want);
			if ($gap !== null && ($bestGap === null || $gap < $bestGap)) {
				$best = $i;
				$bestGap = $gap;
			}
		}
		if ($best === null || $bestGap === null) {
			return false;
		}
		$this->_boxes[$best] = $box;
		if ($bestGap > 0) {
			array_splice($this->_boxes, $best + 1, 0, [$this->freeBox($bestGap - self::MinimumBox)]);
		}
		return true;
	}

	/**
	 * Joins neighbouring free-space boxes into one.  Two adjacent `free` boxes occupy the same
	 * bytes as a single one of their combined length, so merging them moves nothing and turns
	 * two runs too small to be useful into one that may not be.
	 */
	protected function mergeAdjacentFreeSpace(): void
	{
		$merged = [];
		foreach ($this->_boxes as $box) {
			$last = $merged === [] ? null : $merged[count($merged) - 1];
			$isFree = in_array($box->getType(), [self::FreeBox, self::SkipBox], true);
			if ($isFree && $last !== null && in_array($last->getType(), [self::FreeBox, self::SkipBox], true)) {
				$merged[count($merged) - 1] = $this->freeBox(strlen($last->toBinary()) + strlen($box->toBinary()) - self::MinimumBox);
				continue;
			}
			$merged[] = $box;
		}
		$this->_boxes = $merged;
	}

	/**
	 * Returns how many bytes a replacement leaves over in the slot it is written into, or null
	 * when it cannot be written there at all.  Nothing left over is a perfect fit; what is
	 * left over must be enough for a `free` box's own header, so a slot that frees fewer bytes
	 * than {@see MinimumBox} has to be vacated rather than reused.
	 * @param int $slot The whole length available.
	 * @param int $want The whole length to write.
	 * @return ?int The bytes left over, or null when the replacement does not fit.
	 */
	protected static function slotGap(int $slot, int $want): ?int
	{
		$gap = $slot - $want;
		return ($gap === 0 || $gap >= self::MinimumBox) ? $gap : null;
	}

	/**
	 * Builds a free-space box of a payload length.
	 * @param int $payload The payload length.
	 * @return BMFFFileBox The free-space box.
	 */
	protected function freeBox(int $payload): BMFFFileBox
	{
		return new BMFFFileBox(self::FreeBox, str_repeat("\0", $payload));
	}

	/**
	 * Joins the `iinf` descriptions to the `iloc` locations into items.
	 * @return array<int, BMFFItem> The items.
	 */
	private function readItems(): array
	{
		$meta = $this->getMetaBox();
		if ($meta === null) {
			return [];
		}
		$items = $this->readItemInfo((string) $meta->getChild(self::ItemInfoBox)?->getPayload());
		$this->readItemLocations((string) $meta->getChild(self::ItemLocationBox)?->getPayload(), $items);
		return array_values($items);
	}

	/**
	 * Reads the `iinf` box: a version and flags, an entry count whose width the version
	 * decides, then one `infe` box per item.
	 * @param string $payload The `iinf` payload.
	 * @return array<int, BMFFItem> The items, keyed by item id.
	 */
	private function readItemInfo(string $payload): array
	{
		if (strlen($payload) < 6) {
			return [];
		}
		$version = ord($payload[0]);
		$countWidth = $version === 0 ? 2 : 4;
		$pos = 4 + $countWidth;
		$items = [];
		foreach (BMFFFileBox::parseBoxes(substr($payload, $pos)) as $entry) {
			if ($entry->getType() !== self::ItemInfoEntryBox) {
				continue;
			}
			$item = $this->readItemInfoEntry($entry->getPayload());
			if ($item !== null) {
				$items[$item->getId()] = $item;
			}
		}
		return $items;
	}

	/**
	 * Reads one `infe` entry: the item id (two bytes in version 2, four in version 3), the
	 * protection index, the item type, the name, and a `mime` item's content type.
	 * @param string $payload The `infe` payload.
	 * @return ?BMFFItem The item, or null when the entry is too short or an older version.
	 */
	private function readItemInfoEntry(string $payload): ?BMFFItem
	{
		$version = strlen($payload) > 0 ? ord($payload[0]) : 0;
		if ($version < 2 || strlen($payload) < 12) {
			return null;   // versions 0 and 1 describe a different, long-obsolete layout
		}
		$idWidth = $version === 2 ? 2 : 4;
		$id = $idWidth === 2
			? (int) unpack('n', substr($payload, 4, 2))[1]
			: (int) unpack('N', substr($payload, 4, 4))[1];
		$pos = 4 + $idWidth + 2;
		$item = new BMFFItem($id, substr($payload, $pos, 4));
		$pos += 4;
		$item->setName($this->readCString($payload, $pos));
		if ($item->getType() === BMFFItem::MimeType) {
			$item->setContentType($this->readCString($payload, $pos));
		}
		return $item;
	}

	/**
	 * Reads the `iloc` box, recording each item's extent and where in the payload that
	 * extent's numbers are stored so they can later be patched in place.
	 * @param string $payload The `iloc` payload.
	 * @param array<int, BMFFItem> $items The items to locate, keyed by item id.
	 */
	private function readItemLocations(string $payload, array $items): void
	{
		if (strlen($payload) < 8) {
			return;
		}
		$version = ord($payload[0]);
		$offsetSize = ord($payload[4]) >> 4;
		$lengthSize = ord($payload[4]) & 0x0F;
		$baseOffsetSize = ord($payload[5]) >> 4;
		$indexSize = $version === 1 || $version === 2 ? (ord($payload[5]) & 0x0F) : 0;
		$idWidth = $version < 2 ? 2 : 4;
		$pos = 6;
		$count = $version < 2
			? (int) unpack('n', substr($payload, 6, 2))[1]
			: (int) unpack('N', substr($payload, 6, 4))[1];
		$pos += $version < 2 ? 2 : 4;

		for ($i = 0; $i < $count && $pos < strlen($payload); $i++) {
			$id = $this->readField($payload, $pos, $idWidth);
			$construction = 0;
			if ($version === 1 || $version === 2) {
				$construction = $this->readField($payload, $pos, 2) & 0x0F;
			}
			$pos += 2;   // data_reference_index
			$baseField = $pos;
			$base = $this->readField($payload, $pos, $baseOffsetSize);
			$extentCount = $this->readField($payload, $pos, 2);
			$item = $items[$id] ?? null;
			for ($e = 0; $e < $extentCount; $e++) {
				$pos += $indexSize;
				$offsetField = $pos;
				$offset = $this->readField($payload, $pos, $offsetSize);
				$lengthField = $pos;
				$length = $this->readField($payload, $pos, $lengthSize);
				if ($item !== null && $e === 0) {
					$item->setLocation(
						$construction,
						$base,
						$baseField,
						$baseOffsetSize,
						$base + $offset,
						$length,
						$offsetField,
						$offsetSize,
						$lengthField,
						$lengthSize,
						$extentCount === 1,
					);
				}
			}
		}
	}

	/**
	 * Reads a big-endian field of a width and advances the position.
	 * @param string $bytes The bytes.
	 * @param int $pos The position, advanced by the width.
	 * @param int $width The field width in bytes; zero reads nothing.
	 * @return int The value.
	 */
	private function readField(string $bytes, int &$pos, int $width): int
	{
		$value = 0;
		for ($i = 0; $i < $width; $i++) {
			$value = ($value << 8) | ord($bytes[$pos + $i] ?? "\0");
		}
		$pos += $width;
		return $value;
	}

	/**
	 * Reads a NUL-terminated string and advances the position past its terminator.
	 * @param string $bytes The bytes.
	 * @param int $pos The position, advanced past the string.
	 * @return string The string.
	 */
	private function readCString(string $bytes, int &$pos): string
	{
		$end = strpos($bytes, "\0", $pos);
		if ($end === false) {
			$value = substr($bytes, $pos);
			$pos = strlen($bytes);
			return $value;
		}
		$value = substr($bytes, $pos, $end - $pos);
		$pos = $end + 1;
		return $value;
	}

	/**
	 * Removes the identifying user-data keys from whichever convention carries them, leaving
	 * everything that describes the video.  EXIF and XMP are scrubbed by the base class
	 * through their own accessors.
	 * @param int $types The {@see PrivacyCategory} flags to remove.
	 * @return int The number of values removed.
	 */
	protected function clearFormatPrivateData(int $types): int
	{
		$removed = 0;
		foreach (self::PrivacyKeys as $category => $keys) {
			if (!($types & $category)) {
				continue;
			}
			foreach ($keys as $key) {
				if ($this->getUserDataValue($key) !== null) {
					$this->setUserDataValue($key, null);
					$removed++;
				}
			}
		}
		return $removed;
	}

	/**
	 * Returns the `moov`/`udta` box, or null when the file has none.
	 * @return ?BMFFBox The user data box.
	 */
	protected function getUserDataBox(): ?BMFFBox
	{
		return $this->getBox(self::MovieBox)?->getChild(self::UserDataBox);
	}

	/**
	 * Returns the `moov`/`udta`/`meta`/`ilst` box, or null when the file has none.
	 * @return ?BMFFBox The iTunes metadata list.
	 */
	protected function getItemListBox(): ?BMFFBox
	{
		return $this->getUserDataBox()?->getChild(self::MetaBox)?->getChild(self::ItemListBox);
	}

	/**
	 * Returns the `moov`/`udta` box, adding an empty one when the movie has none.
	 * @throws \RuntimeException When the file has no movie box to put user data in.
	 * @return BMFFBox The user data box.
	 */
	protected function requireUserDataBox(): BMFFBox
	{
		$moov = $this->getBox(self::MovieBox);
		if ($moov === null) {
			throw new \RuntimeException('The file has no moov box to hold user data.');
		}
		$udta = $moov->getChild(self::UserDataBox);
		if ($udta === null) {
			$udta = new BMFFFileBox(self::UserDataBox);
			$udta->setChildren([]);
			$moov->addChild($udta);
		}
		return $udta;
	}

	/**
	 * Returns the iTunes metadata list, building the `meta`/`hdlr`/`ilst` chain a reader
	 * expects when the movie has none.
	 * @throws \RuntimeException When the file has no movie box to put user data in.
	 * @return BMFFBox The iTunes metadata list.
	 */
	protected function requireItemListBox(): BMFFBox
	{
		$udta = $this->requireUserDataBox();
		$meta = $udta->getChild(self::MetaBox);
		if ($meta === null) {
			$meta = new BMFFFileBox(self::MetaBox);
			$meta->setChildren([new BMFFFileBox(self::HandlerBox, str_repeat("\0", 8) . 'mdir' . 'appl' . str_repeat("\0", 9))]);
			$udta->addChild($meta);
		}
		$ilst = $meta->getChild(self::ItemListBox);
		if ($ilst === null) {
			$ilst = new BMFFFileBox(self::ItemListBox);
			$ilst->setChildren([]);
			$meta->addChild($ilst);
		}
		return $ilst;
	}

	/**
	 * Runs an edit of the movie box and then makes sure it cost the file nothing: if the
	 * movie grew or shrank and something an absolute offset points at follows it, the
	 * difference is taken out of an adjacent free-space box so every later byte stays where
	 * it was.  When there is no room to absorb it, the edit is undone and the write throws
	 * rather than shifting `mdat` out from under `stco`.
	 * @param callable $edit The edit to apply to the movie box.
	 * @throws \RuntimeException When the size change cannot be absorbed.
	 */
	protected function rewriteMovie(callable $edit): void
	{
		$before = $this->compose();
		$moov = $this->getBox(self::MovieBox);
		$was = $moov === null ? 0 : strlen($moov->toBinary());
		$edit();
		$moov = $this->getBox(self::MovieBox);
		$delta = ($moov === null ? 0 : strlen($moov->toBinary())) - $was;
		if ($delta === 0) {
			$this->_items = null;
			return;
		}
		if (!$this->holdMediaInPlace($delta)) {
			$this->setBoxes(BMFFFileBox::parseBoxes($before));   // undo rather than corrupt
			throw new \RuntimeException(sprintf('Changing the movie box by %d bytes would move the media, and no free-space box can absorb it.', $delta));
		}
		$this->_items = null;
	}

	/**
	 * Keeps the media where it is after the movie box changed size.  Walking forward from the
	 * movie box there are three outcomes: a free-space box comes first and the change is taken
	 * out of it, so every later byte stays put; media or item data comes first and there is
	 * nothing to take it out of; or neither is found, which means no absolute offset addresses
	 * anything after the movie and the change is harmless.
	 * @param int $delta The number of bytes the movie box grew by (negative when it shrank).
	 * @return bool Whether the media can stay where it is.
	 */
	protected function holdMediaInPlace(int $delta): bool
	{
		$seen = false;
		foreach ($this->_boxes as $box) {
			if ($box->getType() === self::MovieBox) {
				$seen = true;
				continue;
			}
			if (!$seen) {
				continue;
			}
			if (in_array($box->getType(), [self::FreeBox, self::SkipBox], true)) {
				$room = strlen($box->getPayload()) - $delta;
				if ($room < 0) {
					return false;
				}
				$box->setPayload(str_repeat("\0", $room));
				return true;
			}
			if (in_array($box->getType(), [self::MediaDataBox, self::ItemDataBox], true)) {
				return false;   // the data starts before any padding could absorb the change
			}
		}
		return true;   // nothing an offset addresses follows the movie, so it may grow freely
	}

	//
	// ─── The ICC colour property ─────────────────────────────────────────────
	//

	/**
	 * Returns the item property container, `meta`/`iprp`/`ipco`.
	 * @return ?BMFFBox The container, or null when the file has none.
	 */
	protected function getPropertyContainer(): ?BMFFBox
	{
		return $this->getMetaBox()?->getChild(self::ItemPropertiesBox)?->getChild(self::ItemPropertyContainerBox);
	}

	/**
	 * Returns the `colr` property holding an ICC profile, ignoring one that only describes a
	 * colour space by coefficients.
	 * @return ?BMFFBox The colour box, or null when there is no profile.
	 */
	protected function findColourBox(): ?BMFFBox
	{
		foreach ($this->getPropertyContainer()?->getChildren() ?? [] as $property) {
			if ($property->getType() !== self::ColourBox) {
				continue;
			}
			$type = substr($property->getPayload(), 0, 4);
			if ($type === self::ColourTypeProfile || $type === self::ColourTypeRestrictedProfile) {
				return $property;
			}
		}
		return null;
	}

	/**
	 * Returns the id of the primary item, which an ICC profile is associated with.
	 * @return ?int The item id, or null when the file names no primary item.
	 */
	protected function getPrimaryItemId(): ?int
	{
		$pitm = $this->getMetaBox()?->getChild(self::PrimaryItemBox)?->getPayload();
		if ($pitm === null || strlen($pitm) < 6) {
			return null;
		}
		return ord($pitm[0]) === 0
			? (int) unpack('n', substr($pitm, 4, 2))[1]
			: (int) unpack('N', substr($pitm, 4, 4))[1];
	}

	/**
	 * Returns the item property container, adding the `iprp`/`ipco` pair when the file has
	 * none.  A file with no `meta` box at all has nowhere for a profile to live.
	 * @throws \RuntimeException When there is no `meta` box.
	 * @return array{0: BMFFBox, 1: BMFFBox} The properties box and its property container.
	 */
	protected function requireProperties(): array
	{
		$meta = $this->getMetaBox();
		if ($meta === null) {
			throw new \RuntimeException('The file has no meta box to attach an ICC profile to.');
		}
		$iprp = $meta->getChild(self::ItemPropertiesBox);
		if ($iprp === null) {
			$iprp = new BMFFFileBox(self::ItemPropertiesBox);
			$iprp->setChildren([]);
			$meta->addChild($iprp);
		}
		$ipco = $iprp->getChild(self::ItemPropertyContainerBox);
		if ($ipco === null) {
			$ipco = new BMFFFileBox(self::ItemPropertyContainerBox);
			$ipco->setChildren([]);
			$iprp->addChild($ipco);
		}
		return [$iprp, $ipco];
	}

	/**
	 * Removes the ICC profile without moving anything: the `colr` property is replaced by a
	 * `free` box of exactly the same length, so the container keeps its size **and every
	 * property keeps its index** — which matters, because a property is addressed by its
	 * position, and dropping one from the middle would renumber every association above it.
	 * Only the association naming the profile goes; the index it named is left occupied by
	 * padding that nothing refers to.
	 * @param BMFFBox $colr The colour property to remove.
	 */
	protected function removeColourInPlace(BMFFBox $colr): void
	{
		$ipco = $this->getPropertyContainer();
		$children = $ipco?->getChildren() ?? [];
		foreach ($children as $i => $property) {
			if ($property !== $colr) {
				continue;
			}
			$free = $this->freeBox(strlen($colr->toBinary()) - self::MinimumBox);
			$children[$i] = $free;
			$ipco->setChildren($children);

			// Dropping the association shortens `ipma`, which would resize `meta` and move
			// the media; the padding that replaced the profile takes up that slack as well,
			// so the box the media sits after is exactly the length it was.
			$was = $this->associationsLength();
			$this->disassociateProperty($i + 1);   // properties are numbered from one
			$freed = $was - $this->associationsLength();
			$free->setPayload(str_repeat("\0", strlen($free->getPayload()) + $freed));
			return;
		}
	}

	/**
	 * Returns the whole length of the association box, or zero when the file has none.
	 * @return int The length in bytes.
	 */
	protected function associationsLength(): int
	{
		$ipma = $this->getMetaBox()?->getChild(self::ItemPropertiesBox)?->getChild(self::ItemPropertyAssociationBox);
		return $ipma === null ? 0 : strlen($ipma->toBinary());
	}

	/**
	 * Writes the profile without changing the length of the `meta` box, by trading the
	 * difference with a `free` property kept at the **end** of the property container.
	 * Padding goes last so that no existing property changes index; a new `colr` is inserted
	 * just before it, which only moves the padding's own index and nothing refers to that.
	 *
	 * The trade covers everything the write costs, the association it may add included, and
	 * the result is checked against the length `meta` started at — so a write that could not
	 * be paid for is undone and reported rather than quietly moving the media.
	 * @param ?BMFFBox $colr The colour property already there, or null when there is none.
	 * @param string $profile The profile bytes.
	 * @return bool Whether the profile was written without resizing the box.
	 */
	protected function fitColourInPlace(?BMFFBox $colr, string $profile): bool
	{
		$ipco = $this->getPropertyContainer();
		$meta = $this->getMetaBox();
		if ($ipco === null || $meta === null) {
			return false;
		}
		$before = $this->compose();
		$was = strlen($meta->toBinary());
		if ($this->tradeColourIntoPadding($ipco, $colr, $profile, $was) && strlen($meta->toBinary()) === $was) {
			return true;
		}
		$this->setBoxes(BMFFFileBox::parseBoxes($before));
		return false;
	}

	/**
	 * Writes the colour property and then takes the whole cost of having done so out of the
	 * container's trailing padding.
	 * @param BMFFBox $ipco The property container.
	 * @param ?BMFFBox $colr The colour property already there, or null when there is none.
	 * @param string $profile The profile bytes.
	 * @param int $was The length the `meta` box must end up back at.
	 * @return bool Whether the cost was absorbed.
	 */
	protected function tradeColourIntoPadding(BMFFBox $ipco, ?BMFFBox $colr, string $profile, int $was): bool
	{
		if ($colr !== null) {
			$colr->setPayload(self::ColourTypeProfile . $profile);
		} else {
			$item = $this->getPrimaryItemId();
			if ($item === null) {
				return false;
			}
			$children = $ipco->getChildren();
			$at = count($children);
			if ($this->trailingFreeProperty($ipco) !== null) {
				$at--;   // keep the padding last, so no real property changes index
			}
			array_splice($children, $at, 0, [new BMFFFileBox(self::ColourBox, self::ColourTypeProfile . $profile)]);
			$ipco->setChildren($children);
			$this->associateProperty($this->requireProperties()[0], $item, $at + 1);
		}
		$delta = strlen((string) $this->getMetaBox()?->toBinary()) - $was;
		return $delta === 0 || $this->tradeWithPadding($ipco, $this->trailingFreeProperty($ipco), $delta);
	}

	/**
	 * Takes a difference out of the container's trailing `free` property, or puts it back in.
	 * A container with no padding can gain some when there is enough to spare for a box of
	 * its own, and padding can be removed outright when the shortfall is exactly its length.
	 * @param BMFFBox $ipco The property container.
	 * @param ?BMFFBox $free The trailing padding, or null when there is none.
	 * @param int $delta The bytes needed (negative when bytes are being freed).
	 * @return bool Whether the difference was absorbed.
	 */
	protected function tradeWithPadding(BMFFBox $ipco, ?BMFFBox $free, int $delta): bool
	{
		if ($delta < 0) {
			$spare = -$delta;
			if ($free !== null) {
				$free->setPayload(str_repeat("\0", strlen($free->getPayload()) + $spare));
				return true;
			}
			if ($spare < self::MinimumBox) {
				return false;   // too little to hold a padding box of its own
			}
			$ipco->addChild($this->freeBox($spare - self::MinimumBox));
			return true;
		}
		if ($free === null) {
			return false;
		}
		$payload = strlen($free->getPayload());
		if ($payload >= $delta) {
			$free->setPayload(str_repeat("\0", $payload - $delta));
			return true;
		}
		if ($payload + self::MinimumBox !== $delta) {
			return false;
		}
		$children = $ipco->getChildren();
		array_pop($children);   // the padding is last, so dropping it renumbers nothing
		$ipco->setChildren($children);
		return true;
	}

	/**
	 * Returns the container's padding property, which is only padding when it is the last one:
	 * anywhere else it would be holding an index that the associations still count past.
	 * @param BMFFBox $ipco The property container.
	 * @return ?BMFFBox The trailing padding, or null when the last property is not padding.
	 */
	protected function trailingFreeProperty(BMFFBox $ipco): ?BMFFBox
	{
		$children = $ipco->getChildren();
		$last = $children === [] ? null : $children[count($children) - 1];
		return $last !== null && in_array($last->getType(), [self::FreeBox, self::SkipBox], true) ? $last : null;
	}

	/**
	 * Removes the association naming a property index, leaving every index as it was.
	 * @param int $index The one-based property index.
	 */
	protected function disassociateProperty(int $index): void
	{
		$ipma = $this->getMetaBox()?->getChild(self::ItemPropertiesBox)?->getChild(self::ItemPropertyAssociationBox);
		if ($ipma === null) {
			return;
		}
		[$version, $flags, $entries] = $this->readAssociations($ipma->getPayload());
		foreach ($entries as $i => [$id, $associations]) {
			$entries[$i] = [$id, array_values(array_filter($associations, fn ($a) => $a[1] !== $index))];
		}
		$ipma->setPayload($this->writeAssociations($version, $flags, $entries));
	}

	/**
	 * Adds one property association to an item's entry, creating the `ipma` box when the file
	 * has none.
	 * @param BMFFBox $iprp The item properties box to associate within.
	 * @param int $item The item id.
	 * @param int $index The one-based property index.
	 * @throws \RuntimeException When the index is too wide for the association form in use.
	 */
	protected function associateProperty(BMFFBox $iprp, int $item, int $index): void
	{
		$ipma = $iprp->getChild(self::ItemPropertyAssociationBox);
		if ($ipma === null) {
			$ipma = new BMFFFileBox(self::ItemPropertyAssociationBox, pack('N', 0) . pack('N', 0));
			$iprp->addChild($ipma);
		}
		[$version, $flags, $entries] = $this->readAssociations($ipma->getPayload());
		if (!($flags & 1) && $index > self::MaxShortPropertyIndex) {
			throw new \RuntimeException(sprintf('The property index %d does not fit the one-byte association form this file uses.', $index));
		}
		$found = false;
		foreach ($entries as $i => [$id, $associations]) {
			if ($id !== $item) {
				continue;
			}
			$associations[] = [false, $index];
			$entries[$i] = [$id, $associations];
			$found = true;
			break;
		}
		if (!$found) {
			$entries[] = [$item, [[false, $index]]];
		}
		$ipma->setPayload($this->writeAssociations($version, $flags, $entries));
	}


	/**
	 * Reads an `ipma` payload: a version and flags, then per item an id and a list of
	 * associations.  The flags decide whether an index is seven bits or fifteen, and the
	 * version whether an item id is sixteen bits or thirty-two.
	 * @param string $payload The `ipma` payload.
	 * @return array{0: int, 1: int, 2: array<int, array{0: int, 1: array<int, array{0: bool, 1: int}>}>}
	 *   The version, the flags, and the entries.
	 */
	protected function readAssociations(string $payload): array
	{
		if (strlen($payload) < 8) {
			return [0, 0, []];
		}
		$version = ord($payload[0]);
		$flags = (int) unpack('N', "\0" . substr($payload, 1, 3))[1];
		$wide = ($flags & 1) === 1;
		$pos = 4;
		$count = $this->readField($payload, $pos, 4);
		$entries = [];
		for ($i = 0; $i < $count && $pos < strlen($payload); $i++) {
			$id = $this->readField($payload, $pos, $version < 1 ? 2 : 4);
			$associations = [];
			$total = $this->readField($payload, $pos, 1);
			for ($j = 0; $j < $total; $j++) {
				$value = $this->readField($payload, $pos, $wide ? 2 : 1);
				$top = $wide ? 0x8000 : 0x80;
				$associations[] = [($value & $top) !== 0, $value & ($top - 1)];
			}
			$entries[] = [$id, $associations];
		}
		return [$version, $flags, $entries];
	}

	/**
	 * Writes an `ipma` payload back in the same version and flags it was read with.
	 * @param int $version The box version.
	 * @param int $flags The box flags.
	 * @param array<int, array{0: int, 1: array<int, array{0: bool, 1: int}>}> $entries The entries.
	 * @return string The payload.
	 */
	protected function writeAssociations(int $version, int $flags, array $entries): string
	{
		$wide = ($flags & 1) === 1;
		$payload = chr($version) . substr(pack('N', $flags), 1) . pack('N', count($entries));
		foreach ($entries as [$id, $associations]) {
			$payload .= $version < 1 ? pack('n', $id) : pack('N', $id);
			$payload .= chr(count($associations));
			foreach ($associations as [$essential, $index]) {
				$value = $index | ($essential ? ($wide ? 0x8000 : 0x80) : 0);
				$payload .= $wide ? pack('n', $value) : chr($value);
			}
		}
		return $payload;
	}

	/**
	 * Runs an edit of the `meta` box and then corrects for it having changed size.  Every
	 * byte after `meta` shifts, so each item located by an absolute file offset is moved by
	 * the same amount.  That is only sound for a **still**: a movie box means `stco`, `saio`
	 * and the fragment offsets address the file too, and this class does not rewrite those,
	 * so such a file is refused with the edit undone.
	 * @param callable $edit The edit to apply.
	 * @throws \RuntimeException When the file is not a still, or a corrected location would not fit.
	 */
	protected function resizeMeta(callable $edit): void
	{
		$before = $this->compose();
		$meta = $this->getMetaBox();
		$was = $meta === null ? 0 : strlen($meta->toBinary());
		try {
			$edit();
			$meta = $this->getMetaBox();
			$delta = ($meta === null ? 0 : strlen($meta->toBinary())) - $was;
			if ($delta !== 0) {
				$this->shiftItemLocations($delta);
			}
		} catch (\RuntimeException $e) {
			$this->setBoxes(BMFFFileBox::parseBoxes($before));   // undo rather than half-edit
			throw $e;
		}
		$this->_items = null;
	}

	/**
	 * Moves every file-located item extent by a delta, rewriting the `iloc` table in place.
	 * All of them move, because an item's data always follows the `meta` box that describes
	 * it.  An item located within `idat` is left alone: its offset is relative to that box,
	 * so it stays correct wherever the box lands.
	 * @param int $delta The number of bytes the data moved by.
	 * @throws \RuntimeException When the file is not a still, or a location would not fit its field.
	 */
	protected function shiftItemLocations(int $delta): void
	{
		if ($this->getBox(self::MovieBox) !== null || $this->getBox('moof') !== null) {
			throw new \RuntimeException('Resizing the meta box would move the media, and only a still image\'s item locations can be corrected.');
		}
		$iloc = $this->getMetaBox()?->getChild(self::ItemLocationBox);
		if ($iloc === null) {
			return;   // nothing addresses the file, so nothing needs correcting
		}
		$payload = $iloc->getPayload();
		foreach ($this->getItems() as $item) {
			if ($item->getConstructionMethod() !== BMFFItem::FileConstruction) {
				continue;
			}
			if (!$item->canShift($delta)) {
				throw new \RuntimeException(sprintf("The %s item's new location does not fit the field widths iloc already uses.", $item->getType()));
			}
			$payload = $item->shift($payload, $delta);
		}
		$iloc->setPayload($payload);
	}

	//
	// ─── Building a new item ─────────────────────────────────────────────────
	//

	/**
	 * Returns an item id nothing is using yet.
	 * @return int The next free item id.
	 */
	protected function nextItemId(): int
	{
		$highest = 0;
		foreach ($this->getItems() as $item) {
			$highest = max($highest, $item->getId());
		}
		return $highest + 1;
	}

	/**
	 * Appends an `infe` entry describing a new item, creating `iinf` when the file has none
	 * and bumping its entry count when it has.
	 * @param BMFFBox $meta The `meta` box.
	 * @param int $id The item id.
	 * @param string $type The four-character item type.
	 * @param string $name The item name.
	 * @param string $contentType The content type, for a `mime` item.
	 */
	protected function appendItemInfo(BMFFBox $meta, int $id, string $type, string $name, string $contentType): void
	{
		$body = pack('n', $id) . pack('n', 0) . $type . $name . "\0";
		if ($type === BMFFItem::MimeType) {
			$body .= $contentType . "\0";
		}
		$entry = (new BMFFFileBox(self::ItemInfoEntryBox, chr(2) . "\x00\x00\x00" . $body))->toBinary();

		$iinf = $meta->getChild(self::ItemInfoBox);
		if ($iinf === null) {
			$meta->addChild(new BMFFFileBox(self::ItemInfoBox, chr(0) . "\x00\x00\x00" . pack('n', 1) . $entry));
			return;
		}
		// A table too short to hold its version and count is padded out rather than special
		// cased, so a truncated `iinf` is appended to as a valid one rather than kept broken.
		$payload = str_pad($iinf->getPayload(), 4, "\0");
		$version = ord($payload[0]);
		$width = $version === 0 ? 2 : 4;
		$at = 4;
		$count = $this->readField($payload, $at, $width);
		$counted = $version === 0 ? pack('n', $count + 1) : pack('N', $count + 1);
		$iinf->setPayload(substr_replace(str_pad($payload, 4 + $width, "\0"), $counted, 4, $width) . $entry);
	}

	/**
	 * Appends an `iloc` extent for a new item, zeroed for {@see setItemData()} to fill in.
	 * The entry is written in whatever field widths and version the table already uses, so
	 * the table stays self-consistent; a file with no `iloc` gets one with four-byte offsets
	 * and lengths.
	 * @param BMFFBox $meta The `meta` box.
	 * @param int $id The item id.
	 */
	protected function appendItemLocation(BMFFBox $meta, int $id): void
	{
		$iloc = $meta->getChild(self::ItemLocationBox);
		if ($iloc === null) {
			// offset_size 4, length_size 4, base_offset_size 0: the address rides the extent.
			$iloc = new BMFFFileBox(self::ItemLocationBox, chr(0) . "\x00\x00\x00" . chr(0x44) . chr(0x00) . pack('n', 0));
			$meta->addChild($iloc);
		}
		$payload = $iloc->getPayload();
		$version = ord($payload[0]);
		$offsetSize = ord($payload[4]) >> 4;
		$lengthSize = ord($payload[4]) & 0x0F;
		$baseOffsetSize = ord($payload[5]) >> 4;
		$indexSize = $version === 1 || $version === 2 ? (ord($payload[5]) & 0x0F) : 0;

		$entry = $version < 2 ? pack('n', $id) : pack('N', $id);
		if ($version === 1 || $version === 2) {
			$entry .= pack('n', BMFFItem::FileConstruction);
		}
		$entry .= pack('n', 0)                              // data_reference_index
			. str_repeat("\0", $baseOffsetSize)             // base_offset
			. pack('n', 1)                                  // one extent
			. str_repeat("\0", $indexSize + $offsetSize + $lengthSize);

		$at = 6;
		$count = $this->readField($payload, $at, $version < 2 ? 2 : 4);
		$counted = $version < 2 ? pack('n', $count + 1) : pack('N', $count + 1);
		$iloc->setPayload(substr_replace($payload, $counted, 6, $version < 2 ? 2 : 4) . $entry);
	}

	/**
	 * Appends an `iref`/`cdsc` reference saying a new item describes the picture, creating the
	 * `iref` box when the file has none.  Without it a reader has no way to tell which image
	 * an `Exif` or XMP item belongs to.
	 * @param BMFFBox $meta The `meta` box.
	 * @param int $from The describing item's id.
	 * @param int $to The described item's id.
	 */
	protected function appendItemReference(BMFFBox $meta, int $from, int $to): void
	{
		$iref = $meta->getChild(self::ItemReferenceBox);
		if ($iref === null) {
			$iref = new BMFFFileBox(self::ItemReferenceBox);
			$iref->setChildren([]);
			$meta->addChild($iref);
		}
		$wide = ord(str_pad($iref->getFullBoxHeader(), 1, "\0")) >= 1;   // version 1 widens the ids
		$body = ($wide ? pack('N', $from) : pack('n', $from)) . pack('n', 1) . ($wide ? pack('N', $to) : pack('n', $to));
		$iref->addChild(new BMFFFileBox(self::ContentDescribes, $body));
	}
}
