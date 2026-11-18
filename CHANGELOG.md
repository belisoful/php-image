# Changelog

Notable changes to php-image. Versions follow [semantic versioning](https://semver.org/),
with the caveat that the major version is 0: the API is not frozen, and a minor bump
may change it.

This library is mirrored by [prado-image](https://github.com/belisoful/prado-image), which
carries the same features under its own naming. Entries applying to only one of the two say
so.

## [0.2.0] — unreleased

### Added

- **A part ceiling**: `ImageFile::getMaxParts()`, `setMaxParts()`, `assertPartCount()` and
  `allowsPartCount()`. Every parser counts the parts it makes a record of — JPEG segments, PNG
  and RIFF chunks, ISO BMFF boxes at every depth, GIF blocks and data sub-blocks, TIFF directory
  entries — and a file may hold 65,536 of them plus one more for every 256 bytes of its length,
  so a large file of ordinary parts is never refused for being large. A container over the
  ceiling throws `\UnexpectedValueException`; metadata over it (Photoshop resources, IPTC
  datasets) reads as absent. The count is per file: nested boxes pass it down, and a
  `RIFFList` shares its container's (`sharePartCount()`).
- **`TIFFImage::setEXIF()`** merges an EXIF into the TIFF instead of refusing it. A TIFF's IFD0
  is its EXIF IFD0, so every tag outside `TIFFImage::ImageTags` (the raster and layout tags plus
  the XMP, IPTC, IRB and ICC carriers) is removed and the given EXIF's other IFD0 tags are copied
  in, its EXIF, GPS and Interoperability IFDs as deep copies. `null` leaves only the image tags.
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
- **A pixel ceiling** on the pure-PHP raster decoders: `ImageGraphics::getMaxPixels()` and
  `setMaxPixels()`, 2^28 by default (a 16384 by 16384 raster). `TIFFImage::getImage()` and
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
