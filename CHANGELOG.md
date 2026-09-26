# Changelog

Notable changes to php-image. Versions follow [semantic versioning](https://semver.org/),
with the caveat that the major version is 0: the API is not frozen, and a minor bump
may change it.

This library is mirrored by [prado-image](https://github.com/belisoful/prado-image), which
carries the same features under its own naming. Entries applying to only one of the two say
so.

## [0.2.0] — unreleased

### Added

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
- CI now covers PHP 8.4 and 8.5 alongside 8.1 to 8.3.

### Fixed

- Appending an item to an `iinf` table too short to hold its own version and entry count wrote
  a malformed table. The count is padded out rather than special cased, so a truncated table
  is rewritten as a valid one.
- `GIFLZWCompressor::assertMinCodeSize()` threw `\RuntimeException` while its docblock promised
  `\InvalidArgumentException` — a different SPL hierarchy, so a caller catching the
  documented type would have missed the throw. It now throws `\UnexpectedValueException`,
  matching every other throw in the file and both public docblocks.

### Changed

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
