# TIFF 6.0 (spec linked from agents/README.md; local copy at local/TIFF6.pdf) coverage assessment

Status legend: ✅ implemented · 🟡 partial · 📦 preserved (tags/data survive a read-modify-write
byte-faithfully, but no decoded semantics) · ❌ not implemented.

An important global property: the container engine **preserves everything on rewrite** — unknown
tags, every IFD, and all strip/tile data are relocated intact — so even ❌ raster forms survive
metadata editing losslessly. "Raster" below means `TIFFImage::getImage()`/`setImage()`; the read side
is `Belisoful\Image\TIFF\TIFFRaster`, which normalizes any supported form to 8-bit RGB.

## Part 1 — Baseline TIFF

| Section | Feature | Structure/metadata | Raster read | Raster write |
|---|---|---|---|---|
| 2 | Header, IFDs, the 12 field types | ✅ all 12 types (incl. Float/Double), MM+II, IFD chains, tolerant parse, full write | — | — |
| 3 | Bilevel (Compression 1 / 2 MH / 32773) | ✅ | ✅ (FillOrder 1 and 2) | ✅ (MH; also G3/G4) |
| 4 | Grayscale | ✅ | ✅ 1/2/4/8/16/32-bit | ❌ (stored as RGB) |
| 5 | Palette color | ✅ | ✅ 1/2/4/8-bit indices | ❌ (stored as RGB) |
| 6 | RGB full color | ✅ | ✅ 8-, 16- and 32-bit/sample | ✅ |
| 7–8 | Baseline fields | ✅ full tag knowledge base | — | writes the raster-required set |
| 9 | PackBits | ✅ codec | ✅ | ✅ |
| 10 | Modified Huffman | ✅ codec | ✅ | ✅ |

## Part 2 — Extensions

| Section | Feature | Structure/metadata | Raster read | Raster write |
|---|---|---|---|---|
| 11 | CCITT Group 3 / Group 4 | ✅ | ✅ G3-1D, G3-2D (T4Options bit 0), G4 | ✅ G3-1D, G3-2D, G4 |
| 12 | Document storage fields | ✅/📦 | — | — |
| 13 | LZW | ✅ codec | ✅ | ✅ |
| 14 | Horizontal predictor | ✅ codec | ✅ any whole-byte width, both byte orders; the floating-point predictor (3) too | ✅ |
| 15 | Tiled images | ✅ tiles preserved+relocated on write | ✅ decode + edge clipping | ❌ (writes strips) |
| 16 | CMYK (Separated) | 📦 | ✅ (naive CMYK→RGB) | ❌ |
| 17 | Halftone hints | 📦 | — | — |
| 18 | Alpha / ExtraSamples | 📦 | 🟡 extra samples read and dropped | ❌ |
| 19 | SampleFormat | 📦 | ✅ unsigned, signed (offset), IEEE float 16/24/32/64 (SMin/SMaxSampleValue range, else 0–1); complex (5, 6) refused | ❌ |
| 20 | RGB colorimetry | 📦 | 🟡 no white-point/primaries chain | — |
| 21 | YCbCr | ✅ tags + subsampling interpretation | ✅ incl. YCbCrSubSampling unit layout | ❌ |
| 22 | JPEG-in-TIFF (old-style, 512–521; new-style 7) | 📦 | ❌ | ❌ |
| 23 | CIE L\*a\*b\* | 📦 | ✅ CIELab and ICCLab (D50 → sRGB) | ❌ |

## Other constraints

- PlanarConfiguration: 1 (chunky) and 2 (separate planes) both decode; the writer emits 1.
- FillOrder: 1 (MSB-first) and 2 (LSB-first, bit-mirrored per byte) both decode, subsampled YCbCr included; the writer emits 1.
- Byte order: multi-byte samples are read in the document's order (MM or II), and reduced to their
  most significant byte; the floating-point predictor's planes are big-endian in either.
- Raster decode handles both strip- and tile-organized images; the writer emits strips.
- The raster writer always stores 8-bit chunky RGB (or 1-bit bilevel for the fax modes), so a
  decode/encode cycle normalizes the raster form even though metadata is preserved exactly.
- The G3 decoder tolerates fill bits before EOL; the byte-aligned-EOL T4Options variant is
  handled by that tolerance rather than modeled explicitly.
- Private spaces bridge to the framework reserved-space stream decorators via `EXIF`/`TIFFImage` `getReservedSpaces()` + `toReservedSpaceStream()`/`toFreeSpaceStream()` (pinned maker-note/private-IFD ranges protected on write).
