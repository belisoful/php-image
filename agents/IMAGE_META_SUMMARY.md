# PHP JPEG Metadata Toolkit — Complete Feature Summary

| | |
|---|---|
| **Library** | PHP JPEG Metadata Toolkit |
| **Version** | 1.12 (`$GLOBALS['Toolkit_Version'] = "1.12"` in `Toolkit_Version.php`) |
| **Author** | Evan Hunter (http://electronics.ozhiker.com) |
| **License** | GNU GPL v2 or later (`COPYING.txt`) |
| **Requirements** | PHP 4+ (originally tested with PHP 4.3.7/4.3.8); no mandatory extensions |
| **Architecture** | Purely procedural PHP — no classes, no `define()` constants; all fixed data lives in `$GLOBALS` arrays |
| **Tested against** | 450+ digital cameras (see `documentation/Camera_List_1.0.pdf`) |

---

## 1. Feature Overview

The toolkit reads and writes image **metadata containers**; it never decodes or re-encodes pixel data.

| Metadata format | Container | Read | Write | HTML report | API file |
|---|---|:---:|:---:|:---:|---|
| JPEG header segments | All markers | ✅ | ✅ | ✅ | `JPEG.php` |
| JPEG Comment | COM (`0xFFFE`) | ✅ | ✅ | ✅ | `JPEG.php` |
| JPEG intrinsic values (dimensions, components) | SOF | ✅ | — | ✅ | `JPEG.php` |
| JFIF | APP0 | ✅ | ✅ | ✅ | `JFIF.php` |
| JFXX (JFIF Extension thumbnail) | APP0 | ✅ | ✅ | ✅ | `JFIF.php` |
| EXIF / DCF / TIFF/EP (incl. makernotes, thumbnail) | APP1 | ✅ | ✅ | ✅ | `EXIF.php` |
| EXIF in TIFF files | TIFF file | ✅ | — | ✅ | `EXIF.php` |
| Kodak "Meta" | APP3 | ✅ | ✅ | ✅ | `EXIF.php` |
| Makernotes (13 camera makers) | inside EXIF | ✅ | — | ✅ | `EXIF_Makernote.php`, `Makernotes/` |
| Print Image Matching (PIM) | EXIF tag 50341 / makernote tag `0x0E00` | ✅ | ✅ | ✅ | `PIM.php` |
| XMP / RDF / Dublin Core | APP1 | ✅ | ✅ | ✅ | `XMP.php`, `XML.php` |
| Photoshop Image Resource Blocks (IRB) | APP13 | ✅ | ✅ | ✅ | `Photoshop_IRB.php` |
| IPTC-NAA IIM records | inside IRB / EXIF tag 33723 | ✅ | ✅ | ✅ | `IPTC.php` |
| Photoshop "File Info" (merged EXIF+XMP+IPTC view) | all of the above | ✅ | ✅ | form | `Photoshop_File_Info.php` |
| Picture Info (legacy camera text) | APP12 | ✅ | ✅ | ✅ | `PictureInfo.php` |
| Embedded thumbnails (EXIF / JFXX / Casio / Minolta / Photoshop) | various | ✅ | — | ✅ | `get_*_thumb.php` |
| Unicode text handling (UTF-8 / UTF-16) | — | ✅ | ✅ | ✅ | `Unicode.php` |

---

## 2. Core JPEG Container (`JPEG.php`)

### Functions
| Function | Signature | Description |
|---|---|---|
| `get_jpeg_header_data` | `( $filename )` | Reads all JPEG header segments into an array of `SegType`, `SegName`, `SegDesc`, `SegDataStart`, `SegData`; `FALSE` on failure |
| `put_jpeg_header_data` | `( $old_filename, $new_filename, $jpeg_header_data )` | Writes a new JPEG from supplied headers + existing compressed image data (validates each segment ≤ `0xFFFD` bytes); may overwrite in place |
| `get_jpeg_Comment` | `( $jpeg_header_data )` | Returns first COM segment contents |
| `put_jpeg_Comment` | `( $jpeg_header_data, $new_Comment )` | Replaces or inserts a COM segment |
| `Interpret_Comment_to_HTML` | `( $jpeg_header_data )` | Renders the comment as HTML |
| `get_jpeg_intrinsic_values` | `( $jpeg_header_data )` | Extracts bits/component, height, width, per-component sampling factors from SOF |
| `Interpret_intrinsic_values_to_HTML` | `( $values )` | Renders dimensions/colour depth table |
| `get_jpeg_image_data` | `( $filename )` | Returns the compressed scan data after SOS (1 MB chunk reads) |
| `Generate_JPEG_APP_Segment_HTML` | `( $jpeg_header_data )` | HTML table of APP0–APP15 segments present |
| `network_safe_fread` | `( $file_handle, $length )` | Loop-`fread` tolerant of short network reads |

### Global data arrays (~65 entries each)
- `$GLOBALS["JPEG_Segment_Names"]` — marker byte → name (SOF0–15, DHT, DAC, RST0–7, SOI, EOI, SOS, DQT, DNL, DRI, DHP, EXP, APP0–15, JPG0–13, COM, TEM, RES…)
- `$GLOBALS["JPEG_Segment_Descriptions"]` — marker byte → human-readable description (incl. usage hints: APP0 = JFIF/JFXX, APP1 = EXIF/XMP, APP2 = Flashpix, APP12 = Picture Info, APP13 = Photoshop IRB/IPTC)

### Recognized APP segment signatures (label cleanup)
`http://ns.adobe.com/xap/1.0/` (XMP), `Photoshop 3.0` (IRB), `[picture info]`, `Type=` (Epson), `HHHH…`/`@s33` (HP).

---

## 3. JFIF / JFXX (`JFIF.php`)

### Functions
| Function | Signature | Description |
|---|---|---|
| `get_JFIF` | `( $jpeg_header_data )` | Decodes JFIF APP0 → `Version1/2`, `Units`, `XDensity`, `YDensity`, `ThumbX/Y`, `ThumbData` |
| `put_JFIF` | `( $jpeg_header_data, $new_JFIF_array )` | Replaces or inserts JFIF APP0 |
| `Interpret_JFIF_to_HTML` | `( $JFIF_array, $filename )` | Renders version, resolution/aspect, thumbnail info |
| `get_JFXX` | `( $jpeg_header_data )` | Decodes JFXX APP0 → `Extension_Code`, `ThumbData` |
| `put_JFXX` | `( $jpeg_header_data, $new_JFXX_array )` | Replaces/inserts JFXX; auto-creates a default JFIF segment (v1.2, 72 dpi) if none exists |
| `Interpret_JFXX_to_HTML` | `( $JFXX_array, $filename )` | Renders thumbnail (links to `get_JFXX_thumb.php` for JPEG thumbs) |

### Options / modes
- **JFIF Units byte**: `0` = aspect ratio only · `1` = dpi · `2` = dpcm
- **JFXX Extension_Code**: `0x10` = JPEG-encoded thumbnail ✅ · `0x11` = 1 byte/pixel palettized (not implemented) · `0x13` = 3 bytes/pixel RGB (not implemented)

---

## 4. EXIF Subsystem (`EXIF.php`)

### Public API
| Function | Signature | Description |
|---|---|---|
| `get_EXIF_JPEG` | `( $filename )` | Decodes EXIF APP1 (`Exif\x00\x00`); **local files only** (http/ftp rejected) |
| `put_EXIF_JPEG` | `( $exif_data, $jpeg_header_data )` | Packs and replaces/inserts APP1 EXIF ⚠️ may damage makernotes with external pointers |
| `get_Meta_JPEG` | `( $filename )` | Decodes Kodak "Meta" APP3 (`Meta\x00\x00`) |
| `put_Meta_JPEG` | `( $meta_data, $jpeg_header_data )` | Packs and replaces/inserts APP3 ⚠️ same pointer warning |
| `get_EXIF_TIFF` | `( $filename )` | Decodes EXIF from a TIFF file (read-only) |
| `Interpret_EXIF_to_HTML` | `( $Exif_array, $filename )` | Full HTML report (IFD0, EXIF/GPS/Interop SubIFDs, IFD1 thumbnail, makernote, embedded IPTC/XMP/IRB) |

### Key internals
`get_TIFF_Packed_Data`, `get_IFD_Array_Packed_Data`, `get_IFD_Packed_Data`, `process_TIFF_Header`, `read_Multiple_IFDs( …, $local_offsets = FALSE, $read_next_ptr = TRUE )`, `read_IFD_universal`, `get_Tag_Text_Value`, `get_Special_Tag_Text_Value`, `interpret_IFD`, `get_IFD_Data_Type`, `put_IFD_Data_Type`, `get_IFD_value_as_text`.

### TIFF data types (1–12) supported
| # | Type | Read | Write |
|---|---|:---:|:---:|
| 1 | Unsigned Byte | ✅ | ✅ |
| 2 | ASCII String | ✅ | ✅ |
| 3 | Unsigned Short | ✅ | ✅ |
| 4 | Unsigned Long | ✅ | ✅ |
| 5 | Unsigned Rational | ✅ | ✅ |
| 6 | Signed Byte | ✅ | ✅ |
| 7 | Undefined (binary) | ✅ | ✅ |
| 8 | Signed Short | ✅ | ✅ |
| 9 | Signed Long | ✅ | ✅ |
| 10 | Signed Rational | ✅ | ✅ |
| 11 | Float | ❌ placeholder | ❌ |
| 12 | Double | ❌ placeholder | ❌ |

Byte orders: `MM` (Motorola/big-endian) and `II` (Intel/little-endian).

### Tag interpretation types (`Type` field in tag definitions)
`Numeric` (with optional `Units`) · `String` · `Character Coded String` (`ASCII`/`UNICODE` prefixes) · `Lookup` (value→meaning tables) · `Special` (custom decoders: YCbCr subsampling, Components Configuration, CFA Pattern) · `SubIFD` · `Maker Note` · `PIM` · `IPTC` · `XMP` · `IRB` · `Unknown`.

### IFD tag groups (`$GLOBALS["IFD_Tag_Definitions"]`, ≈173 base tags)
| Group | Coverage |
|---|---|
| `TIFF` | 36 tags (incl. 33723 IPTC, 34377 Photoshop IRB, 34665 EXIF SubIFD, 34853 GPS, 50341 PIM, 513/514 thumbnail, 700 XMP) |
| `EXIF` | 58 tags (exposure, flash, sensing, scene, 37500 MakerNote, 37510 UserComment…) |
| `Interoperability` | 5 tags |
| `GPS` | 31 tags (0–30) |
| `Meta` (APP3) | 34 tags |
| `KodakSpecialEffects` | 3 tags |
| `KodakBorders` | 6 tags |

### Embedded metadata inside EXIF (read **and** write)
IPTC (tag 33723) · XMP (tag 700) · Photoshop IRB (tag 34377) · PIM (tag 50341) · MakerNote (tag 37500, offset preserved on write) · EXIF thumbnail (IFD1 tags 513/514).

### Display option globals (booleans, default `FALSE`)
- `$GLOBALS['HIDE_UNKNOWN_TAGS']` — hide unknown tags (EXIF + unknown IRB resources + unknown PIM tags)
- `$GLOBALS['SHOW_BINARY_DATA_HEX']` — show type-7 data as hex dump
- `$GLOBALS['SHOW_BINARY_DATA_TEXT']` — show type-7 data as raw text

---

## 5. Makernote Subsystem (`EXIF_Makernote.php` + `Makernotes/`)

### Plugin mechanism
`EXIF_Makernote.php` auto-includes every `.php` in `Makernotes/`; each registers handlers into `$GLOBALS['Makernote_Function_Array']` under three keys: `Read_Makernote_Tag`, `get_Makernote_Text_Value`, `Interpret_Makernote_to_HTML`. Dispatcher tries each registered parser until one matches.

### Dispatcher functions
| Function | Description |
|---|---|
| `Read_Makernote_Tag( $Makernote_Tag, $EXIF_Array, $filehnd )` | Detects maker (via IFD0 tag 271 Make) and decodes; marks Empty/Unknown |
| `get_Makernote_Text_Value( $Tag, $Tag_Definitions_Name )` | Maker-specific text decoders |
| `Interpret_Makernote_to_HTML( $Makernote_tag, $filename )` | Maker-specific HTML reports |

### Supported makers & formats
| Maker | File | Header signature(s) | Tag group(s) | Special features |
|---|---|---|---|---|
| Agfa | `agfa.php` | `AGFA \x00\x01` | Olympus (shared) | interpretation delegated to Olympus |
| Canon | `canon.php` | none | `Canon` (8 tags) | Camera Settings 1 & 2 offset decoders (self-timer, focal lengths, flash bit-flags, white balance, flash bias, focus points, subject distance), Serial Number formatting, Custom Functions 1–13 lookup tables |
| Casio | `casio.php` | Type 1: none · Type 2: `QVC\x00\x00\x00` | `Casio Type 1` (12) / `Casio Type 2` (28) | forced MM order; focal-length decoder; **thumbnail extraction** (tags 0x0004 / 0x2000 → `get_casio_thumb.php`) |
| Epson | `epson.php` | `EPSON\x00\x01\x00` | Olympus (shared) | Epson-specific tags 0x020B–0x020D |
| Fujifilm | `fujifilm.php` | `FUJIFILM` + Intel offset | `Fujifilm` (16) | forced II order, makernote-relative offsets; also matches one Nikon (Coolpix 775) |
| Konica/Minolta | `konica_minolta.php` | `MLY`, `KC`, `+M+M+M+M`, `MINOL` (recognized, not decoded) · no-header (decoded) | Olympus (shared) | 46 camera-setting definitions; APEX formula decoders (ISO, shutter, aperture, brightness), WB RGB coefficients, date/time bit-packing |
| Kyocera / Contax | `kyocera.php` | `KYOCERA            \x00\x00\x00` | `Kyocera` (2) | non-standard IFD: local offsets, no next-IFD pointer; proprietary thumbnail tag recognized (undecoded) |
| Nikon | `nikon.php` | Type 1: `Nikon\x00\x01\x00` · Type 2: none · Type 3: `Nikon\x00\x02\x10\x00\x00` / `\x02\x00\x00\x00` | `Nikon Type 1` (8) / `Nikon Type 3` (37) | Type 3 embeds a second TIFF header (recursive decode); ISO, AF-area, bracketing/shooting-mode bit decoders; encrypted fields **not** handled |
| Olympus | `olympus.php` | `OLYMP\x00\x01` / `\x02` | `Olympus` (43) | Special Mode decoder (Normal/Fast/Panorama + direction); **thumbnail extraction** (tags 0x0088/0x0081 with missing-`0xFF` repair → `get_minolta_thumb.php`); serves Agfa/Epson/Minolta |
| Panasonic | `panasonic.php` | Type 1: `Panasonic\x00\x00\x00` · Type 2: `MKED` (empty) | `Panasonic` (5) | no next-IFD pointer |
| Pentax / Asahi | `Pentax.php` | Type 1: none · Type 2: `AOC\x00` + 2 bytes | `Pentax` (14) / Casio Type 2 (reused) | — |
| Ricoh | `ricoh.php` | Text (`Rv`/`Rev…`) · empty · IFD (`Ricoh`/`RICOH`) | `Ricoh` (4) / `RicohSubIFD` (empty) | nested `[Ricoh Camera Info]` SubIFD decode; text makernote rendering |
| Sony | `sony.php` | `SONY CAM \x00\x00\x00` / `SONY DSC \x00\x00\x00` | `Sony` (1) | no next-IFD pointer |

---

## 6. IPTC-NAA IIM (`IPTC.php`)

### Functions
| Function | Signature | Description |
|---|---|---|
| `get_IPTC` | `( $Data_Str )` | Parses binary IIM records (tag marker `0x1C`) → array of `IPTC_Type`, `RecName`, `RecDesc`, `RecData`; returns partial array on truncation |
| `put_IPTC` | `( $new_IPTC_block )` | Re-encodes records to IIM binary; validates input |
| `Interpret_IPTC_to_HTML` | `( $IPTC_info )` | HTML table with semantic decoding of dates, times, versions, enums |

### Global data arrays
- `$GLOBALS["IPTC_Entry_Names"]` / `$GLOBALS["IPTC_Entry_Descriptions"]` — `"record:dataset"` → name/description
- `$GLOBALS["IPTC_File Formats"]` — file-format numbers 0–29 → names (JFIF, TIFF, Photo-CD, BMP, WAV, AVI, PDF, MPEG…)
- `$GLOBALS['ImageType_Names']` — colour component letters → names

### Supported datasets (by record)
- **Record 1 (Envelope)**: 1:00 Model Version · 1:05 Destination · 1:20 File Format · 1:22 File Format Version · 1:30 Service Identifier · 1:40 Envelope Number · 1:50 Product ID · 1:60 Envelope Priority · 1:70 Date Sent · 1:80 Time Sent · 1:90 Coded Character Set (not decoded) · 1:100 UNO · 1:120 ARM Identifier · 1:122 ARM Version
- **Record 2 (Application)**: 2:00 Record Version · 2:03 Object Type Reference · 2:05 Object Name (Title) · 2:07 Edit Status · 2:08 Editorial Update · 2:10 Urgency · 2:12 Subject Reference · 2:15 Category · 2:20 Supplemental Category · 2:22 Fixture Identifier · 2:25 Keywords · 2:26/2:27 Content Location Code/Name · 2:30 Release Date · 2:35 Release Time · 2:37 Expiration Date · 2:40 Special Instructions · 2:42 Action Advised · 2:45/2:47/2:50 Reference Service/Date/Number · 2:55 Date Created · 2:60 Time Created · 2:62/2:63 Digital Creation Date/Time · 2:65/2:70 Originating Program/Version · 2:75 Object Cycle · 2:80 By-Line · 2:85 By-Line Title · 2:90 City · 2:92 Sub-Location · 2:95 Province/State · 2:100/2:101 Country Code/Name · 2:103 Original Transmission Reference · 2:105 Headline · 2:110 Credit · 2:115 Source · 2:116 Copyright Notice · 2:118 Contact · 2:120 Caption/Abstract · 2:122 Caption Writer/Editor · 2:125 Rasterized Caption · 2:130 Image Type · 2:131 Image Orientation · 2:135 Language Identifier · 2:150–2:154 Audio fields · 2:200–2:202 ObjectData Preview fields
- **Records 7/8/9 (ObjectData)**: 7:10/7:20/7:90/7:95 · 8:10 Subfile · 9:10 Confirmed ObjectData Size

### Semantic decodes in HTML view
Dates (`CCYYMMDD` → `DD/MM/YYYY`) · times (`HHMMSS±HHMM`) · hex version numbers · file-format lookup (1:20) · Action Advised (2:42: Kill/Replace/Append/Reference) · Editorial Update (2:08) · Object Cycle (2:75: a/p/b) · Image Type component colours (2:130) · Image Orientation (2:131: L/P/S).

---

## 7. Photoshop IRB (`Photoshop_IRB.php`)

### Functions
| Function | Signature | Description |
|---|---|---|
| `get_Photoshop_IRB` | `( $jpeg_header_data )` | Concatenates all APP13 (`Photoshop 3.0\x00`) payloads and parses 8BIM resources |
| `put_Photoshop_IRB` | `( $jpeg_header_data, $new_IRB_data )` | Rebuilds APP13 segment(s), chunked at 32000 bytes each |
| `get_Photoshop_IPTC` | `( $Photoshop_IRB_data )` | Extracts + decodes the IPTC resource (`0x0404`) |
| `put_Photoshop_IPTC` | `( $Photoshop_IRB_data, $new_IPTC_block )` | Replaces/appends the IPTC resource |
| `Interpret_IRB_to_HTML` | `( $IRB_array, $filename )` | HTML report of all resources |
| `unpack_Photoshop_IRB_Data` | `( $IRB_Data )` | Low-level 8BIM parser (tolerant of Photoshop's non-spec name padding) |
| `pack_Photoshop_IRB_Data` | `( $IRB_data )` | Low-level 8BIM serializer |
| `Interpret_Transfer_Function` | `( $Transfer_Function_Binary )` | Decodes 13-point ink curves (0x03F7/0x03F8) |
| `Interpret_Halftone` | `( $Halftone_Binary )` | Decodes halftone screens (0x03F4/0x03F5): frequency, angle, dot shape (Round/Ellipse/Line/Square/Cross/Diamond) |

### Global data arrays
`$GLOBALS["Photoshop_ID_Names"]`, `$GLOBALS["Photoshop_ID_Descriptions"]` — resource ID → name/description.

### Resource IDs — fully decoded in HTML
| ID | Name |
|---|---|
| 0x03ED | Resolution Info |
| 0x03F3 | Print flags |
| 0x03F4 / 0x03F5 | Grayscale / Color halftoning |
| 0x03F7 / 0x03F8 | Grayscale / Color transfer functions |
| 0x0404 | IPTC-NAA record |
| 0x0406 | JPEG quality (1–12, Standard/Optimised/Progressive) |
| 0x0408 | Grid and guides |
| 0x0409 / 0x040C | Thumbnail resource (PS 4.0 / 5.0) → `get_ps_thumb.php` |
| 0x040A | Copyright flag |
| 0x040B | URL |
| 0x040D | Global Angle |
| 0x0411 | ICC Untagged |
| 0x0414 | Document Specific IDs |
| 0x0419 | Global Altitude |
| 0x041A | Slices |
| 0x041E | URL List |
| 0x0421 | Version Info |
| 0x2710 | Print flags information |

### Resource IDs — recognized but not decoded
0x03E8, 0x03E9, 0x03EB, 0x03EE–0x03F2, 0x03F6, 0x03F9–0x03FD, 0x03FE, 0x03FF, 0x0400–0x0403, 0x0405, 0x040E, 0x040F (ICC Profile), 0x0410 (Watermark), 0x0412, 0x0413, 0x0415–0x0417, 0x041B–0x041D, 0x0BB7 (clipping path name), plus range **0x07D0–0x0BB6** = Path Information.

---

## 8. Photoshop File Info Emulation (`Photoshop_File_Info.php`)

Merges/synchronizes 22 fields across XMP, IRB/IPTC and EXIF exactly like Photoshop's File Info dialog.

### Functions
| Function | Signature | Description |
|---|---|---|
| `get_photoshop_file_info` | `( $Exif_array, $XMP_array, $IRB_array )` | Gathers + de-duplicates the 22-field array from all three sources |
| `put_photoshop_file_info` | `( $jpeg_header_data, $new_ps_file_info_array, $Old_Exif_array, $Old_XMP_array, $Old_IRB_array )` | Writes fields back to EXIF + XMP + IRB/IPTC simultaneously; validates date `YYYY-MM-DD` |
| `get_Local_Timezone_Offset` | `( )` | Server GMT offset as `±HH:MM` |
| `XMP_Check` | `( $reference_array, $check_array )` | Recursively ensures all blank-template tags exist |
| `find_XMP_Tag` / `find_XMP_item` / `find_XMP_block` | search helpers | Locate tags/blocks (`rdf:Description` matched by `xmlns:*`) |
| `create_GUID` | `( )` | 8-4-4-4-12 GUID from `md5(uniqid(...))` |
| `add_to_field` | `( $field_array, $field, $value, $separator )` | De-duplicating append |
| `find_IPTC_Resource` / `find_Photoshop_IRB_Resource` | search helpers | Find IPTC dataset / IRB resource by ID |

### The 22 File Info fields
`title` · `author` · `authorsposition` · `caption` · `captionwriter` · `jobname` · `copyrightstatus` (`Unknown`/`Copyrighted Work`/`Public Domain`) · `copyrightnotice` · `ownerurl` · `keywords[]` · `category` · `supplementalcategories[]` · `date` (YYYY-MM-DD) · `city` · `state` · `country` · `credit` · `source` · `headline` · `instructions` · `transmissionreference` · `urgency` (`none`/1–8).

### Field → storage mapping (write targets)
| Field | IPTC (in IRB 0x0404) | XMP | EXIF |
|---|---|---|---|
| title | 2:05 (≤64) | `dc:title` (rdf:Alt) | — |
| author | 2:80 (≤32) | `dc:creator` (rdf:Seq) | 315 Artist |
| authorsposition | 2:85 (≤32) | `photoshop:AuthorsPosition` | — |
| caption | 2:120 (≤2000) | `dc:description` | 270 ImageDescription |
| captionwriter | 2:122 (≤32) | `photoshop:CaptionWriter` | — |
| keywords | 2:25 (repeat) | `dc:subject` (rdf:Bag) | — |
| copyrightstatus | IRB 0x040A flag | `xapRights:Marked` | — |
| copyrightnotice | 2:116 (≤128) | `dc:rights` (rdf:Alt) | 33432 Copyright |
| ownerurl | IRB 0x040B | `xapRights:WebStatement` | — |
| category | 2:15 (≤3) | `photoshop:Category` | — |
| supplementalcategories | 2:20 (repeat) | `photoshop:SupplementalCategories` (rdf:Bag) | — |
| date | 2:55 (Ymd) | `photoshop:DateCreated` | 306 DateTime (now) |
| city / state / country | 2:90 / 2:95 / 2:101 | `photoshop:City/State/Country` | — |
| credit / source | 2:110 / 2:115 | `photoshop:Credit/Source` | — |
| headline | 2:105 (≤256) | `photoshop:Headline` | — |
| instructions | 2:40 (≤256) | `photoshop:Instructions` | — |
| transmissionreference | 2:103 (≤32) | `photoshop:TransmissionReference` | — |
| jobname | — | `xapBJ:JobRef`→`stJob:name` | — |
| urgency | 2:10 (only if 1–8) | `photoshop:Urgency` | — |

Auto-maintained on write: EXIF 305 Software (+= toolkit name), 306 DateTime, backfill 36867/36868; XMP `xap:CreateDate/ModifyDate/MetadataDate/CreatorTool`; IPTC 2:00 Record Version.

### Globals defined here
- `$GLOBALS["Software Name"]` = `"(PHP JPEG Metadata Toolkit v1.12)"`
- `$GLOBALS['Blank XMP Structure']` — template XMP tree (blocks: pdf, photoshop, xap, xapBJ+stJob, xapRights, dc; commented-out: exif, tiff, xapMM+stRef) with per-request GUID.

---

## 9. XMP (`XMP.php`)

### Functions
| Function | Signature | Description |
|---|---|---|
| `get_XMP_text` | `( $jpeg_header_data )` | Extracts raw XMP XML from APP1 (`http://ns.adobe.com/xap/1.0/\x00`) |
| `put_XMP_text` | `( $jpeg_header_data, $newXMP )` | Replaces or inserts the XMP APP1 segment |
| `read_XMP_array_from_text` | `( $xmptext )` | Parses XMP text → tree array (alias of `read_xml_array_from_text`) |
| `write_XMP_array_to_text` | `( $xmparray )` | Serializes tree → full XMP packet (`<?xpacket…?>` header/trailer, `adobe-xap-filters` PI, ~2.9 KB padding for in-place editing) |
| `Interpret_XMP_to_HTML` | `( $XMP_array )` | HTML report, per-namespace heading + caption/value tables |
| `Interpret_RDF_Item` | `( $Item )` | Caption lookup + value build (special-cases `photoshop:DateCreated`) |
| `get_RDF_field_html_value` | `( $rdf_item )` | Handles values, `rdf:parseType="Resource"` sub-resources, nested Descriptions, collections |
| `interpret_RDF_collection` | `( $item )` | Renders rdf:Alt ("List of Alternates"), rdf:Bag ("Unordered List"), rdf:Seq ("Ordered List") |

### Global data
`$GLOBALS['XMP_tag_captions']` — XMP field tag → human-readable caption.

### Supported schemas/namespaces
| Prefix | Schema |
|---|---|
| `dc` | Dublin Core (contributor, coverage, creator, date, description, format, identifier, language, publisher, relation, rights, source, subject, title, type) |
| `xmp` / `xap` | XMP Basic (Advisory, BaseURL, CreateDate, CreatorTool, Identifier, MetadataDate, ModifyDate, Nickname, Thumbnails) |
| `xmpidq` / `xapidq` | Identifier qualifier |
| `xapRights` | Rights Management (Certificate, Copyright, Marked, Owner, UsageTerms, WebStatement) |
| `xapMM` | Media Management (DerivedFrom, DocumentID, History, Versions, Rendition*, ManagedFrom/To…) |
| `xapBJ` + `stJob` | Basic Job Ticket (JobRef: name, id, url) |
| `xmpTPg` | Paged-Text (MaxPageSize, NPages) |
| `pdf` | Adobe PDF (Keywords, PDFVersion, Producer) |
| `photoshop` | Photoshop RDF (14 File-Info fields + History) |
| `tiff` | Embedded TIFF properties |
| `exif` | Embedded EXIF properties (full set incl. GPS*, Flash sub-fields, OECF/SFR, CFAPattern) |
| `xapGImg` | Thumbnail sub-fields (height, width, format, image) |
| `stDim` / `stEvt` / `stRef` / `stVer` | Sub-structures (dimensions, events, references, versions) |

### RDF structures handled
`x:xapmeta`/`x:xmpmeta` wrappers · `rdf:RDF` · `rdf:Description` · `rdf:Alt`/`rdf:Bag`/`rdf:Seq` · `rdf:li` / `rdf:_N` members · `rdf:parseType="Resource"`.

---

## 10. Generic XML Engine (`XML.php`)

### Functions
| Function | Signature | Description |
|---|---|---|
| `read_xml_array_from_text` | `( $xmltext )` | expat-based parse (UTF-8, case-preserving, whitespace kept) → tree array |
| `write_xml_array_to_text` | `( $xmlarray, $indentlevel )` | Serializes tree → indented XML, all names/values via `xml_UTF8_clean()` |
| `xml_get_children` | `( &$input_xml_array, &$item_num )` | Internal recursive tree builder |
| `xml_get_child` | `( &$input_xml_item, $children = NULL )` | Internal node builder |

### Tree node format
`[ 'tag' => name, 'value' => text, 'attributes' => [name=>value…], 'children' => [ … ] ]` (keys present only when applicable). Expat types handled: `cdata`, `complete`, `open`, `close`.

---

## 11. Unicode Handling (`Unicode.php`)

Pure-PHP Unicode support (no mbstring required). **Supported encodings: UTF-8 and UTF-16 (BE & LE via `$MSB_first`), full surrogate-pair range.** UCS-2/UCS-4/JIS/EUC/Shift-JIS are NOT supported.

### Functions (14)
| Group | Functions |
|---|---|
| Sanitize | `UTF8_fix( $utf8_text )` · `UTF16_fix( $utf16_text, $MSB_first )` |
| Decode → code points | `UTF8_to_unicode_array` · `UTF16_to_unicode_array` |
| Encode ← code points | `unicode_array_to_UTF8` · `unicode_array_to_UTF16` |
| XML cleaning | `xml_UTF8_clean` · `xml_UTF16_clean` (legal-XML char ranges + `&#x09;/&#x0A;/&#x0D;` escaping) |
| HTML escape | `HTML_UTF8_Escape` · `HTML_UTF16_Escape` (code points > 0x7F → `&#xHHHH;`) |
| HTML unescape | `HTML_UTF8_UnEscape` · `HTML_UTF16_UnEscape` (decimal + hex entities) |
| Smart escapers | `smart_HTML_Entities` · `smart_htmlspecialchars` (idempotent — leave existing entities intact) |

Note (per file header): UTF-16 functions not fully tested.

---

## 12. Print Image Matching (`PIM.php`)

| Function | Signature | Description |
|---|---|---|
| `Decode_PIM` | `( $tag, $Tag_Definitions_Name )` | Decodes `PrintIM\x00` blocks (version + 6-byte entries: 2-byte tag no. + 4-byte data); Panasonic extra-NUL workaround |
| `Encode_PIM` | `( $tag, $Byte_Align )` | Repacks a decoded PIM tag (MM/II aware) |
| `get_PIM_Text_Value` | `( $Tag, $Tag_Definitions_Name )` | Text dump (Version + per-tag entries; respects `HIDE_UNKNOWN_TAGS`) |

PIM sub-tag definitions are unknown — all entries reported as "Unknown Tag N".

---

## 13. Picture Info — APP12 (`PictureInfo.php`)

| Function | Signature | Description |
|---|---|---|
| `get_jpeg_App12_Pic_Info` | `( $jpeg_header_data )` | Returns `[ "Header" => vendor header, "Picture Info" => text ]`; text truncated at `[end]` marker |
| `put_jpeg_App12_Pic_Info` | `( $jpeg_header_data, $new_Pic_Info_Text )` | Replaces or inserts APP12 |
| `Interpret_App12_Pic_Info_to_HTML` | `( $jpeg_header_data )` | HTML rendering (UTF-8 escaped) |

### Recognized vendor signatures (7)
`[picture info]` · `\x0a\x09\x09\x09\x09[picture info]` · `SEIKO EPSON CORP.  \00` · `Agfa Gevaert   \x00` · `SanyoElectricDSC\x00` · HP (byte + `\x00\x00\x00`) · `Type=` (Epson) · `OLYMPUS OPTICAL CO.,LTD.`

---

## 14. Utilities & Version

### `pjmt_utils.php`
| Function | Description |
|---|---|
| `get_relative_path( $target, $fromdir )` | Portable relative path computation (`/` and `\`, drive letters) — makes thumbnail-script links relocatable |

### `Toolkit_Version.php`
- `$GLOBALS['Toolkit_Version'] = "1.12"` — single source of the version string.

---

## 15. Example & Helper Scripts

| Script | Purpose | Key behavior |
|---|---|---|
| `Example.php` | Main read-only demo — full metadata HTML report of a JPEG | GET param `jpeg_fname` (regex-whitelisted `[_A-Za-z0-9]+\.jpe?g`); `$Toolkit_Dir` include path option; sets `HIDE_UNKNOWN_TAGS = TRUE` |
| `TIFFExample.php` | TIFF counterpart — EXIF/IFD HTML report of a TIFF | GET param `tiff_fname` (`.tif`/`.tiff` whitelist) |
| `Edit_File_Info.php` | Include file rendering the Photoshop File Info edit form | **4 modes**: (1) `$new_ps_file_info_array` set → forced values; (2) only `$filename` → values from file; (3) `$filename` + `$default_ps_file_info_array` → file values + defaults; (4) neither → blank form. `$outputfilename` **required** always |
| `Write_File_Info.php` | Form handler — merges POST into EXIF+XMP+IRB and rewrites JPEG in place | Only security: `.jpg` extension check; `error_reporting(0)`; uses legacy `$GLOBALS['HTTP_POST_VARS']` |
| `Edit_File_Info_Example.php` | Demo driver for the editor (non-destructive) | Copies source to rotating `temp_a.jpg`–`temp_z.jpg` (`get_next_filename()` + `next_temp_file.dat`); hard-coded demo defaults |
| `get_exif_thumb.php` | Streams EXIF IFD1 thumbnail as `image/jpeg` | Tag 513 data |
| `get_JFXX_thumb.php` | Streams JFXX thumbnail | Only extension code 0x10 (JPEG) |
| `get_casio_thumb.php` | Streams Casio makernote thumbnail | Tags 8192 / 4 |
| `get_minolta_thumb.php` | Streams Minolta/Olympus makernote thumbnail | Tags 0x0088 / 0x0081; repairs first byte to `0xFF` |
| `get_ps_thumb.php` | Streams Photoshop IRB thumbnail | IDs 0x0409/0x040C; works for JPEG **and** TIFF (IRB in EXIF tag 34377) |

### Editable form fields (Edit_File_Info.php → Write_File_Info.php)
All 22 File Info fields (see §8): text inputs (title, author, authorsposition, captionwriter, ownerurl, category, date, city, state, country, credit, source, jobname, transmissionreference), textareas (caption, keywords, supplementalcategories, copyrightnotice, headline, instructions), selects (copyrightstatus: Unknown/Copyrighted Work/Public Domain; urgency: none/1–8).

---

## 16. Global Configuration Flags & Data Arrays (Complete List)

### Defined globals — behavior flags
| Global | Default | Effect |
|---|---|---|
| `$GLOBALS['HIDE_UNKNOWN_TAGS']` | `FALSE` | Hide unknown EXIF tags, unknown IRB resources, unknown PIM entries |
| `$GLOBALS['SHOW_BINARY_DATA_HEX']` | `FALSE` | Show TIFF type-7 (undefined) data as hex dump |
| `$GLOBALS['SHOW_BINARY_DATA_TEXT']` | `FALSE` | Show TIFF type-7 data as raw text |
| `$GLOBALS['Toolkit_Version']` | `"1.12"` | Version string |

### Defined globals — data tables
| Global | Contents |
|---|---|
| `$GLOBALS["JPEG_Segment_Names"]` / `["JPEG_Segment_Descriptions"]` | ~65 JPEG marker names/descriptions |
| `$GLOBALS["IFD_Tag_Definitions"]` | Tag definitions for 7 base groups + 14 makernote groups |
| `$GLOBALS['IFD_Data_Sizes']` | TIFF data type → byte size (1–12) |
| `$GLOBALS["Maker_Note_Tag"]` | Reference to makernote entry during IFD read |
| `$GLOBALS['Makernote_Function_Array']` | Makernote plugin registry (3 handler lists) |
| `$GLOBALS["Canon_Camera_Settings_1_Tag_Values"]` / `["_2_Tag_Values"]` / `["Canon_Custom_Functions_Tag_Values"]` | Canon decode tables |
| `$GLOBALS["Minolta_Camera_Setting_Definitions"]` | 46 Minolta camera settings |
| `$GLOBALS["IPTC_Entry_Names"]` / `["IPTC_Entry_Descriptions"]` | IPTC dataset names/descriptions |
| `$GLOBALS["IPTC_File Formats"]` | 30 file-format names |
| `$GLOBALS['ImageType_Names']` | Colour component names |
| `$GLOBALS["Photoshop_ID_Names"]` / `["Photoshop_ID_Descriptions"]` | IRB resource names/descriptions |
| `$GLOBALS['XMP_tag_captions']` | XMP field captions |
| `$GLOBALS["Software Name"]` | Toolkit signature written to EXIF/XMP |
| `$GLOBALS['Blank XMP Structure']` | XMP template tree for create/repair |

### Constants
**The library defines zero `define()` constants.** All fixed values are inline literals: JPEG marker bytes, signature strings (`JFIF\x00`, `JFXX\x00`, `Exif\x00\x00`, `Meta\x00\x00`, `Photoshop 3.0\x00`, `http://ns.adobe.com/xap/1.0/\x00`, `8BIM`, `PrintIM\x00`, makernote headers), segment types (`0xE0` APP0, `0xE1` APP1, `0xE3` APP3, `0xEC` APP12, `0xED` APP13, `0xFE` COM), max segment payload `0xFFFD`, APP13 chunk size 32000, JFXX codes 0x10/0x11/0x13, IPTC marker `0x1C`, EXIF tag numbers (270, 305, 306, 315, 33432, 34665, 36867, 36868, 37500, 50341, 33723, 34377, 700, 513, 514), Photoshop resource IDs, xpacket id `W5M0MpCehiHzreSzNTczkc9d`.

---

## 17. HTML Output / CSS Customization

All `Interpret_*_to_HTML` functions emit HTML **fragments** (caller supplies `<html>/<head>/<body>`), classed for CSS styling (see `documentation/css_terms.html`):

- `JPEG_Intrinsic_*`, `JPEG_APP_Segments_*`, `JPEG_Comment_*`
- `JFIF_*`, `JFXX_*` (incl. `Thumbnail`, `Thumbnail_Link`)
- `Picture_Info_*`
- `EXIF_*` (incl. `Secondary_Heading`, `First_IFD_Thumb(_Link)`, `Minolta_Thumb(_Link)`, `Casio_Thumb(_Link)`, `Makernote_Small_Heading`, `Makernote_Text`)
- `XMP_*` (incl. `Secondary_Heading`)
- `Photoshop_*` (incl. `Thumbnail(_Link)`), `IPTC_*`

Each family offers `Main_Heading`, `Table`, `Table_Row`, `Caption_Cell`, `Value_Cell` classes.

---

## 18. Documented Limitations & Warnings

1. **EXIF/Meta reads require local files** — http/ftp wrappers are explicitly rejected (workaround: `copy()` to a temp file first).
2. **`put_EXIF_JPEG` / `put_Meta_JPEG` can damage makernotes** containing external pointers (e.g. embedded thumbnails) — makernote-aware re-offsetting is not implemented.
3. **`put_jpeg_header_data` replaces ALL headers** — never transplant a SOF segment between different images.
4. TIFF data types **Float (11) and Double (12)** are not implemented.
5. **Nikon encrypted makernote fields** are not decrypted.
6. Not decoded: JFIF packed thumbnail display, JFXX 0x11/0x13 thumbnails, IPTC 1:90 Coded Character Set & 2:125 Rasterized Caption, many IRB resource IDs, Kyocera proprietary thumbnail, PIM sub-tag meanings.
7. **Security**: only the example entry scripts whitelist filenames; the `get_*_thumb.php` scripts do not sanitize `$_GET['filename']`, and `Write_File_Info.php` accepts any `.jpg` path — no authentication anywhere.
8. Legacy PHP4-isms: short open tag in `EXIF.php`/`Photoshop_IRB.php`, `$str{n}` offsets, `$GLOBALS['HTTP_POST_VARS']`.

---

## 19. Version History Highlights (from `documentation/changes.html`)

- **1.01** — IPTC returns partial data on error
- **1.02/1.03** — IRB corrupted-name tolerance; APP13 signature on every segment
- **1.04** — XMP insertion fix
- **1.10** — Big release: TIFF reading (`get_EXIF_TIFF`), Photoshop File Info editor (4 new files), XML whitespace fix, Unicode smart escapers + unescape functions, IRB spec-deviation fixes, `jpeg_fname` GET rename (security)
- **1.11** — Embedded XMP/IRB inside EXIF (TIFF); portable thumbnail links (`pjmt_utils.php`); `unpack_/pack_Photoshop_IRB_Data` split; `HIDE_UNKNOWN_TAGS` covers IRB; `Toolkit_Version.php` added; http/ftp rejection; GPS/ZIP/LZW tag fixes; `get_ps_thumb.php` TIFF support
- **1.12** — (no entry in changes.html; version bump in `Toolkit_Version.php`)

## 20. Planned / TODO (from `documentation/todo.html`)

More makernote specs · PIM tag definitions · Photoshop CS format spec + unknown IRB resources (1061/1062/1064) · `adobe-xap-filters` meaning · full EXIF-write testing · HTTP/FTP EXIF support · new decoders (Adobe "Ducky" segment, Apple plist, ICC profiles) · EXIF field decoders (DeviceSettings, SpatialFrequencyResponse, UserComment, OECF, SubjectArea) · Float/Double types · IPTC extended datasets · JFIF/JFXX thumbnail decoding · UTF-16 testing.
