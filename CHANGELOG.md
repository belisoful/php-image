# Changelog

Notable changes to php-image. Versions follow [semantic versioning](https://semver.org/),
with the caveat that the major version is 0: the API is not frozen, and a minor bump
may change it.

This library is mirrored by [prado-image](https://github.com/belisoful/prado-image), which
carries the same features under its own naming. Entries applying to only one of the two say
so.

## [0.2.0] — unreleased

### Added

- **Conformance**: `validate()` and `conform()` on every metadata carrier (`EXIF`, `XMP`,
  `IPTC`, `PhotoshopIRB`) and every container, through `ConformableInterface`. `validate()`
  reports where metadata departs from its specification and changes nothing; `conform()`
  corrects it. Both take `ConformanceCategory` flags: `Type` (a TIFF's IPTC tag not stored as
  LONG, an XMP property not in its schema's form), `Required` (the fields Exif requires of a
  JPEG's or a TIFF's metadata, the IPTC record versions), `Structure`, `Invented` (the IPTC
  envelope's sender, date and number) and `Advisory` (a JPEG's extended XMP packet not named by
  its MD5 digest, which is read all the same). `conform()` defaults to `Safe`, which leaves out
  what would have to be invented. A container reports each carrier under its name and adds
  what only it knows — a JPEG's pixel dimensions.
- **Classes a subclass can replace**: every class the library creates is named by a class
  constant on the class that creates it and resolved with `static::` — `ExifClass`,
  `XmpClass`, `IptcClass`, `IrbClass`, `ChunkClass`, `BoxClass`, `FrameClass`, `TagClass` and
  the rest — so a subclass that names its own class has that created instead. An abstract
  subclass of `ImageFile` names its own containers (`JpegClass` … `TiffClass`) to have the
  format-detecting factories open them; `ImageGraphics` names its GD and ImageMagick
  libraries, a container the facade it draws through, a TIFF its fax codec and raster
  decoder, and `Makernote` its maker classes (`MakerClasses`, `GenericClass`). The byte
  readers, bit IO, codec halves and static utilities stay as they are.
- **A part ceiling**: `ImageFile::getMaxParts()`, `setMaxParts()`, `assertPartCount()` and
  `allowsPartCount()`. Every parser counts the parts it makes a record of — JPEG segments, PNG
  and RIFF chunks, ISO BMFF boxes at every depth, GIF blocks and data sub-blocks, TIFF directory
  entries — and a file may hold as many as the memory budget allows (65,536 with no memory
  limit) plus one more for every 256 bytes of its length, so a large file of ordinary parts is
  never refused for being large. A container over the
  ceiling throws `\UnexpectedValueException`; metadata over it (Photoshop resources, IPTC
  datasets) reads as absent. The count is per file: nested boxes pass it down, and a
  `RIFFList` shares its container's (`sharePartCount()`).
- **`TIFFImage::setEXIF()`** merges an EXIF into the TIFF instead of refusing it. A TIFF's IFD0
  is its EXIF IFD0, so every tag outside `TIFFImage::ImageTags` (the raster and layout tags plus
  the XMP, IPTC, IRB and ICC carriers) is removed and the given EXIF's other IFD0 tags are copied
  in, its EXIF, GPS and Interoperability IFDs as deep copies. `null` leaves only the image tags.
  The resolution (`TIFFImage::ResolutionTags`) stays with the image as well, unless the given EXIF
  states its own.
- **Trailing data** on JPEG and GIF: `getTrailingData()` and `removeTrailingData()`. On a JPEG
  that is everything after the first top-level EOI — MPF secondary images, motion-photo video,
  any appended file — and removing it also drops the APP2 `MPF` index that addressed it. A
  lazily read JPEG shortens its deferred scan through the new `SourceRange::withLength()`
  (php-image only: prado-image shortens the framework stream window it already holds).
- **TIFF `SampleFormat`** in `TIFFImage::getImage()`: signed integer samples at 8, 16 and 32 bits;
  IEEE floating point at 16 (half), 24, 32 and 64 bits, scaled from `SMinSampleValue`–
  `SMaxSampleValue` or 0.0–1.0; unsigned at 32 bits; and the floating-point predictor.
  Complex samples, the one format with no colour to show, are refused.
- **AVI**, read-write. `LIST INFO` tags, XMP in `_PMX` and the `IDIT` timestamp, with privacy
  scrubbing by category. The media is never decoded.
- **ISO BMFF**, read-write — HEIF, AVIF, MP4 and QuickTime, told apart by the `ftyp` brand.
  Exif and XMP as `meta` items, XMP in the Adobe `uuid` box for movies, the ICC profile as a
  `colr` item property, and movie user data in both the QuickTime `udta` and iTunes `ilst`
  conventions. Items can be created, not only repointed.
- **JPEG XL**, read-write. `Exif`, `xml ` (XMP) and `jumb` boxes; pixel dimensions from the
  codestream's bit-packed `SizeHeader` (`JXLSizeHeader`); a bare codestream is promoted to a
  container on the first write rather than refused.
- **Nested RIFF.** `RIFFContainer::parseChunks()` is one walker for the container body and
  every `LIST` payload, so a list reads as a `RIFFList` — a chunk to whatever holds it and a
  chunk collection in its own right.
- **`RIFX` and `RF64`/`BW64`.** Read and rewritten in the form they were found: big-endian
  sizes at any depth, and the `0xFFFFFFFF` sentinel resolved through a `ds64` chunk that a
  rewrite updates to what it actually wrote.
- **A shared ISO BMFF box model** (`BMFFBox`), lifted out of the JUMBF reader: the 32-bit
  size, the 64-bit and run-to-the-end forms, `uuid` user types and depth-capped nesting.
- **A file ceiling**: `ImageFile::getMaxFileSize()`, `setMaxFileSize()` and `assertFileSize()`.
  A file read whole is held in memory, held again as the parts it is made of, and once more
  while it is written back: measured, 3.0 to 3.2 times its length and 3.8 for a GIF of full
  sub-blocks, so a 50 MB file ended a process running under the stock 128M. `fromFile()`,
  `fromStream()` and `fromString()` now throw `\UnexpectedValueException` for a file longer
  than the ceiling, and the first two read no further than one byte past it. The ceiling is a
  quarter of the memory budget — 16 MiB under the stock 128M — and none where the process has
  no memory limit. The lazy readers (`fromStreamLazy()`) hold only a file's structure and are
  not subject to it; with `streamTo()` they are how a longer file is read and rewritten.
  `RIFFContainer`, `EXIFAudio` and `EXIF::fromTiffFile()` keep to it as well.
- **A memory budget**: the four ceilings are sized from one number — one byte of a file read
  whole for every 4 bytes of it, one part for every 2,048, one inflated byte for every 8, one
  decoded pixel for every 16. The ratios are measured peaks with room to spare: a hex-encoded
  raw profile peaks at 4.4 times its inflated length, and a TIFF raster decoded to an image at
  9 bytes a pixel. Unless one is set the budget is half of PHP's `memory_limit`, read each time
  it is asked for, so a file that would not fit the limit the process really runs under is
  refused with an exception instead of ending it: under the stock 128M that is a file of 16
  MiB, 32,768 parts, 8 MiB inflated and about four million pixels. A process with no memory
  limit has no budget: a file may be any length and the others keep the fixed defaults (65,536
  parts, 16 MiB, 2^28 pixels). `ImageFile::setMemoryBudget()` takes a number of bytes, a size
  in PHP's own shorthand (`'256M'`, `'1G'`) or a share of the limit (`'25%'`), and
  `getMemoryBudget()` reads it back. A ceiling moved by its own setter keeps that value
  whatever budget is set before or after, until the setter is given `null`. It is not a meter —
  the limit is configuration and nothing watches memory while a file is read, so a file opens
  or is refused the same way whatever else the application holds.
  `PNGImage::getMaxInflatedSize()` and `setMaxInflatedSize()` come with it, so the inflation
  ceiling moves at run time like the others.
- **A pixel ceiling** on the pure-PHP raster decoders: `ImageGraphics::getMaxPixels()` and
  `setMaxPixels()`, 2^28 with no memory limit (a 16384 by 16384 raster). `TIFFImage::getImage()` and
  `GIFFrame::getPixels()` size their buffers from the dimensions a file states, so a TIFF of
  176 bytes claiming 50000 by 50000 asked for 7.5 GB and died of it, uncatchably. A raster over
  the ceiling now throws before anything is allocated.
- CI now covers PHP 8.4 and 8.5 alongside 8.2 and 8.3.

### Security

Reported privately, with proof-of-concept files, against v0.1.0 and the unreleased main. Each
let a small file stop a PHP process or leave data behind that the caller had removed.

- **TIFF sub-IFD pointers were followed without remembering where they led** (also in
  v0.1.0). An IFD holding the Exif IFD pointer twice, both back at itself, doubled the work at
  each of 64 levels: a 349-byte JPEG never finished opening. So did a chain of distinct IFDs
  that each point twice at the next, with no loop in it. `TIFFDocument` now reads each IFD
  once per parse, whichever pointer leads to it, and notes the others as warnings.
- **An `iloc` table with zero-width fields cost 65535 steps an item.** A 60 KB HEIF took over
  forty seconds to answer `getEXIF()`. Only the first extent of an item is modelled, so the
  rest are stepped over rather than read.
- **An `iloc` offset or length near 2^63 ended in a `\TypeError`**, on a write and, when the
  base and the offset overflowed together, on a plain `getEXIF()`. A location that is not a
  place in any file (`BMFFItem::isLocatable()`) is no longer recorded: the item reads as
  holding nothing, and a write that would have to move it is refused with the documented
  `\RuntimeException`.
- **Rewriting a HEIF or AVIF item left its old bytes in the file.** Every `setEXIF()` or
  `setXMP()` appended a new `mdat` and repointed the item, so earlier captions stayed readable
  — and so did the whole original Exif, GPS position included, after `clearPrivateData()`
  reported it removed. New bytes no longer than the old are now written over them and longer
  ones appended as before, and the old bytes are zeroed either way. A scrub no longer grows
  the file. Bytes that another `iloc` extent shares, or that lie outside an `mdat`, are not
  the item's alone and are left as they are; bytes orphaned by an earlier version or another
  tool are not found.
- **A file of nothing but empty parts cost twenty to a hundred times its size in memory**,
  because every segment, chunk, box and block becomes an object: a 3.8 MB JPEG of empty
  segments needed 380 MB, which is a fatal error no caller can catch. See the part ceiling
  under Added.
- **IPTC parsing was quadratic in a repeated dataset**: each keyword read filtered and copied
  every earlier one again, so 7 MB of JPEG `APP13` segments took twenty seconds to open and a
  60 KB PNG with one compressed chunk took minutes. They are gathered and added once.

### Fixed

- `save()` wrote over the file in place, so a write that failed part-way — a full disk — left
  half a file where the original had been. It now writes a temporary file beside the target
  and renames it over, keeping the target's permission bits and following a symbolic link to
  the file it names. Whatever stops that throws `\RuntimeException` and leaves the original
  untouched: a short write, a directory that takes no temporary file, a rename that is
  refused. The bytes are never written over the file in place instead; a caller who wants
  that hands `writeTo()` a handle it opened. Only where there is no file to replace is the
  write direct: a stream-wrapper path, a target that is not a regular file.
  `EXIFAudio::save()` goes the same way, both through `StreamIOTrait::writeFile()`.
- Rewriting a file stamped an IPTC envelope on it. `IPTC::toBinary()` completed every set with
  the mandatory envelope record, so a JPEG whose IPTC had none — most of them — came back from
  a rewrite with today's date in `DateSent` and an `EnvelopeNumber` derived from it, edited or
  not. A set read from bytes is now written with the datasets it holds; a set built from
  nothing is still completed, and `validate()` completes either on request.
- An IPTC record set came back from a rewrite in other bytes than it went in. Its datasets
  were sorted by tag id, a repeated dataset split by another was gathered, text was trimmed
  or padded to its dataset's bounds and re-encoded, and padding after the last dataset was
  dropped — all without an edit. A set that was only read is now written back byte for byte;
  an edited one keeps the order the file had, a repeated dataset's values together where the
  first stood and a new dataset ahead of the first of a later record. Only a set built from
  nothing is written in the order of its tag ids. `PhotoshopIRB::setIPTC()` leaves a resource
  already holding those bytes as it is, name included.
- A TIFF's IPTC tag (33723) was written as UNDEFINED, which `exiftool -validate` warns about.
  It is written as LONG, the type Photoshop gave it: zero-padded to a whole number of four
  bytes, the datasets in their own order whatever the byte order of the file. A tag read as
  UNDEFINED or BYTE and not changed keeps its type and its bytes.
- A TIFF written from an image (`TIFFImage::fromImage()`, `setImage()`) had no XResolution or
  YResolution, which baseline TIFF requires and `exiftool -validate` warns about. A raster
  that states no resolution is now given 72 pixels an inch; a resolution the file already
  states is kept, and where it states one axis the other takes the same.
- A TIFF's IPTC was rewritten on every save, changed or not, and always into tag 33723: a file
  keeping it only in its Photoshop resources gained the tag, and an edit left the resources'
  copy as it was, so the file held two that disagreed. `TIFFImage` now writes only a set that
  differs from the one it read, to the tag and into the Photoshop resources where they hold
  a copy.
- Extended XMP in a JPEG was read whether or not the main packet named it. With no
  `xmpNote:HasExtendedXMP`, or one naming a digest no segment carried, the first extended
  packet in the file was merged anyway — which is what an editor leaves behind when it
  rewrites the main packet and not the extension. Only the named packet is read now, and only
  when every byte of it arrived; any other is dropped on a rewrite. The digest is matched as a
  name and not recomputed from the text, since writers in the wild name their packets with
  digests of something else.
- A privacy scrub left identifying data in place. XMP face regions (`mwg-rs:Regions` and
  Microsoft's `MP:RegionInfo`, which name the people pictured) are now removed under `Author`;
  and `clearPrivateData()` on a JPEG or GIF drops the trailing data for any category, since an
  MPF secondary image carries its own EXIF, GPS included, that no carrier scrub reached.
- Malformed metadata raised PHP errors where it should have read as absent, each found by
  mutating valid files:
  - `XMP::parse('')` threw a `\ValueError`, so an empty carrier (a zero-length WebP `XMP `
    chunk, a PNG `iTXt` with no text) broke `getXMP()` and `clearPrivateData()`. It returns
    false, as documented.
  - An IPTC block whose `CodedCharacterSet` (1:90) was an escape sequence this library has no
    name for, or was empty, parsed and then could not be written: `toBinary()` threw a
    `\TypeError`, and with it every save of the file. The designation is now kept verbatim and
    written back as read, and the text after it stays in the fallback charset.
  - An IPTC numeric dataset shorter than its type read past the end of its value. It reads as
    the number its bytes spell. IPTC text that is not in its declared charset raised an iconv
    notice; it is left unconverted, quietly.
  - A PNG `iTXt` chunk cut off before its two flag bytes threw a `\ValueError`.
  - `EXIF::getLatitude()`/`getLongitude()` warned on a reference tag stored as numbers, and
    `getGpsTimestamp()` threw a `\ValueError` on a date stamp with bytes after its terminating
    NUL. The first reads unsigned; the second ends the date at the NUL.
  - `EXIFTags::textValue()` threw a `\TypeError` on an enumerated tag stored as a rational,
    and warned on a text tag stored as rationals or a `UserComment` stored as numbers. A field
    in a type its tag does not take renders as the numbers it holds.
  - `ICCProfile::decodeCurve()` warned on a `curv` gamma with no value after its count.
- A compressed PNG chunk inflated without limit: a 400 KB file with an `iCCP` chunk inflating
  to 400 MB exhausted the memory limit in `getICCProfile()`. `iCCP`, `zTXt` and compressed
  `iTXt` now inflate to at most `PNGImage::MaxInflatedSize` (16 MiB) and read as absent past it.
  The size is what still fits a 128 MiB memory limit: a hex-encoded raw profile peaks at over
  four times its inflated length while it is decoded and parsed.
- Appending an item to an `iinf` table too short to hold its own version and entry count wrote
  a malformed table. The count is padded out rather than special cased, so a truncated table
  is rewritten as a valid one.
- 16-bit TIFF samples in a little-endian (`II`) file were read from their low byte, so every such
  raster decoded as noise without an error. `TIFFRaster::toRgb()` now takes the document's
  byte order.
- The horizontal predictor was undone only for 8-bit samples; a 16-bit predicted raster decoded
  its stored differences as values. It is now undone at any whole-byte width, and a predictor
  a raster cannot take is refused.
- A subsampled YCbCr raster ignored `FillOrder`, and a 16-bit palette raster used the high byte
  of its index. The first is honoured; the second, and a palette index that is not unsigned,
  are refused.
- A Float field's text showed its widening to a double (`0.10000000149011612` for a stored
  0.1). `EXIFTags::textValue()` now renders the shortest decimal that packs to the same four
  bytes.
- (php-image only) `GIFLZWCompressor::assertMinCodeSize()` threw `\RuntimeException` while its docblock promised
  `\InvalidArgumentException` — a different SPL hierarchy, so a caller catching the
  documented type would have missed the throw. It now throws `\UnexpectedValueException`,
  matching every other throw in the file and both public docblocks.

### Changed

- **`IPTC::validate()` reports; it no longer changes the set.** Completing the envelope and
  the record versions, which it used to do, is `IPTC::conform()`; `toBinary()` still does it
  for a set built from nothing. `XMP::validate()` takes the same category flags as the rest.
- **Writing a file holds it once, not twice.** Each container appended every payload to its
  header and that to the output, so a file of one large chunk, box or strip was held two
  more times while it was written. They now gather the pieces and join them once
  (`BMFFBox::toParts()`, `GIFFrame::toParts()`), which brought reading and saving from 4.0–4.4
  times the file's length to 3.0–3.2 (3.8 for a GIF of full sub-blocks) and the file
  ceiling's ratio from five to four.
- `ImageFile::fromString()`, `fromStream()` and `fromFile()` declare `self` as their return
  type, so an abstract subclass can dispatch to containers that do not extend it; called on
  a container they return that container, as before.
- `ImageGraphics` keeps its libraries by class, not by mode name, so a subclass that names
  another library gets its own.
- **A missing suggested extension no longer fails as an undefined function.** Without
  `ext-zlib` a compressed PNG chunk reads as absent and writing one throws; without `ext-iconv`
  text that needs converting is read unconverted (or empty, for UTF-16) and encoding a
  `UNICODE` or `JIS` coded string throws rather than writing it empty. The checks are small
  overridable methods (`hasZlib()`, `hasIconv()`), which is how the tests stand in for an
  installation without the extension. In prado-image the IPTC check sits in `TIPTC`, since the
  converter it guards is the framework's; in php-image it sits in `Utf8Converter` itself.
- The archive Composer installs carries only `src/`, the README, the changelog and the
  licence. A `.gitattributes` keeps the tests, the CI and tool configuration, and the agent notes
  out of it, which halves the download; a clone still has everything.
- **PHP 8.2 is now the minimum** in both mirrors. The PRADO framework that prado-image builds on
  requires it, and the two repositories share code file for file, so they share a language
  floor too. PHP 8.1 is past end of life; CI no longer runs it.
- Every write to these containers obeys a **never-move rule**, because they hold absolute file
  offsets (`idx1`, `indx`, `stco`/`co64`, `saio`, `iloc`) that naive insertion corrupts. A
  chunk is rewritten in its own slot when it fits, else the slot is vacated to padding,
  adjacent padding is merged, and the smallest sufficient run takes it; appending is the last
  resort. Three relaxations are taken only where provable: a still's `iloc` as the complete
  reference set, an AVI with no OpenDML index and a `movi`-relative `idx1`, and free-space
  pooling, which moves nothing by construction.
- A format with no carrier now **throws** rather than accepting and dropping: IPTC in AVI, ISO
  BMFF and JPEG XL, Exif and ICC in AVI, and ICC in JPEG XL, whose profile lives inside the
  codestream.
- Xdebug is pinned to 3.5.3 in the branch-coverage job. 3.6.0alpha1 mis-reports path coverage
  badly enough to fail the gate on unchanged code.

## [0.1.0] — 2026-09-04

Initial release: JPEG, TIFF, PNG, WebP/RIFF and GIF 87a/89a containers; EXIF through Exif 3.1
with makernote decoding for thirteen makers; XMP, IPTC, the Photoshop 8BIM IRB, JFIF/JFXX,
PrintIM, APP12 Picture Info and JUMBF; read-write ICC profiles with a pure-PHP matrix/TRC
transform; the LZW/GIF-LZW/PackBits/CCITT-fax/horizontal-predictor codecs; and dual
GD/ImageMagick raster conversion.
