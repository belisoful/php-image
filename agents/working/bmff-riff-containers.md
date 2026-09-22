# QuickTime/BMFF and RIFF container expansion — design

**Status: all five steps of section 6 are landed** (`RIFFList`, `AVIImage`, `BMFFBox`, `JXLImage`, `BMFFFile`). What is left is
named in section 7. This records
the scope decision and the plan so a later session does not re-derive them. Keep this file synchronized with
`../prado-image/agents/working/bmff-riff-containers.md` (see the mirror-repository rule in CLAUDE.md); the names differ, the design does not.

## Scope decision (2026-09-21)

The feature is **QuickTime/RIFF metadata extraction for JPEG, ISO BMFF, TIFF, JXL, and AVI
containers**. Two decisions frame it:

- **Read-write, the same contract as every other container.** Not an extraction-only reader.
  A setter that cannot store what it is given **throws**; it never silently drops data.
- **Media payload is preserved opaquely — never decoded, never re-encoded.** "Read-write AVI"
  means its metadata carriers round-trip byte-faithfully, not that the library understands video.
  This is the rule TIFF strips and GIF frames already follow.

## 1. What the work actually is

The prompt names two container *grammars* and five *carriers* of the same four payloads.

**ISO BMFF / QuickTime grammar** — `[uint32 size][4CC type][payload]`, with `size == 1` meaning a
64-bit largesize follows and `size == 0` meaning "to end of file"; `uuid` boxes are keyed by a
16-byte UUID. QuickTime `.mov` is the ancestor; MP4, HEIF, AVIF, and JXL are ISO BMFF profiles
distinguished by the `ftyp` brand.

**RIFF grammar** — `[4CC id][uint32 LE size][payload]`, payload padded to an even length, with
`LIST` as the recursive node. WebP and AVI are two *form types* of the identical grammar
(`RIFF....WEBP` versus `RIFF....AVI `).

**Four payloads, unchanged in every carrier** — TIFF/EXIF, XMP packet, ICC profile, JUMBF. All
four are already read-write here. The work is carrier plumbing, not metadata parsing.

## 2. Current state

| Container | State |
|---|---|
| JPEG | Done. APP1/APP2/APP13, and JUMBF in APP11. `src/Meta/JUMBF/JUMBFBox.php` is **already an ISO BMFF box reader** — 64-bit largesize, `uuid`, `jp2c`, a nesting-depth cap. It is the seed of the general box model. |
| TIFF | Done, and it is the payload the other four carry: a BMFF `Exif` box or a RIFF `EXIF` chunk *is* a TIFF header. `EXIF::scanStream()` already lifts one out of a seekable stream without touching pixel data. |
| RIFF/WebP | Done (metadata carriers, and now nested). `src/RIFFContainer.php` has the grammar, deferred chunks (`fromStreamLazy()`/`streamTo()`), and the `ICCP`/`EXIF`/`XMP ` wiring. `parseChunks()` is one walker for the container body and every `LIST` payload, so a list reads as a `RIFFList`; `RIFX` (every size big-endian) and RF64/BW64 (the `0xFFFFFFFF` sentinel resolved through `ds64`) are read and rewritten in the form they were found. |
| AVI | Done (metadata carriers). `AVIImage` reads and writes `LIST INFO`, `_PMX` (XMP), and `IDIT`; `LIST movi` stays deferred on the lazy path. Every write obeys the never-move rule of section 6 — own slot, then the file's padding pool, then append — except where `getCanRearrange()` proves a move safe. |
| ISO BMFF | Done (`BMFFFile`). `ftyp` brand dispatch, the `meta`/`iinf`/`iloc`/`idat` item machinery, the Adobe `uuid` XMP box, and item rewriting that moves nothing. QuickTime `udta` text atoms and the iTunes `ilst` form are both read and written, the `colr` ICC property lives in `iprp`/`ipco`, and items can be created, not only repointed. |
| JXL | Done (`JXLImage`). `Exif`, `xml `, and `jumb` read-write over the flat box grammar; a bare codestream is promoted to a container on the first write; pixel dimensions come from the codestream's bit-packed `SizeHeader` (`src/JXLSizeHeader.php`). `brob` is recognized but not decompressed. |

## 3. The contract a new container must satisfy

Read-write means each of `AVIImage`, `BMFFFile`, and `JXLImage` must:

- extend `ImageFile` and implement `parse()`, `compose()`, `getFormat()`, `streamTo()`,
  `getXMP()`/`setXMP()`, and the `*Direct` accessors for IPTC and ICC;
- register in `ImageFile::detect()` — note that BMFF is sniffed by the `ftyp` brand at offset 4,
  **not** by a leading magic, so `detect()` needs a second sniff shape;
- implement `clearFormatPrivateData()` for format-only identifying fields (RIFF `INFO` `IART`
  and `ICMT`, QuickTime `©nam`/`©cpy`, creation-time atoms, GPS atoms in `udta`);
- **throw** for every carrier the format has no home for rather than accepting and dropping it,
  and join the `ContainerReadWriteTest` matrix on both halves: the refusal — getter null,
  `hasX()` false, a null accepted, a value thrown, and nothing written by the refusal — and a
  compose-and-reparse round trip for every carrier the format does define;
- round-trip byte-faithfully with no edit;
- reach full line coverage (the gate is enforced, not advisory) and pass the branch gate;
- land identically in the sibling repository.

## 4. The crux: the first containers with internal absolute offsets

JPEG, PNG, GIF, and WebP hold no pointers into their own payload, so editing metadata is free.
AVI and BMFF both carry offset tables that point at media:

| Table | Where | Offsets are |
|---|---|---|
| `idx1` | AVI | relative to `movi` — *but* real writers emit file-absolute ones too; both exist in the wild |
| `indx` / `ix##` | OpenDML AVI | file-absolute |
| `stco` / `co64` | QuickTime/MP4 | file-absolute |
| `iloc` | HEIF/AVIF | file-absolute for `construction_method = 0` |

Insert one XMP box and every one of those goes stale. This repo has already solved that class of
problem twice, and both mechanisms are reusable:

1. **Relocate and fix up** — the `TIFFTag::setExternalData()` model: capture on parse, recompute on compose
   with the paired counts kept in sync. Fully general, but means modelling five offset tables.
2. **Pin and pad** — the makernote model (`TIFFTag::setPreserveOffset()`): never move the media, absorb the
   size delta into padding (`JUNK` in RIFF, `free`/`skip` in BMFF). Costs nothing, and it is what
   real muxers do.

**Decision — a third framing that makes (1) mostly unnecessary: never move the media box.** New
metadata boxes go *after* `mdat` (legal in BMFF; `moov` itself frequently is), and size deltas in
front of the media are absorbed into padding. Full offset fixup becomes the fallback for the one
case that forces it: a file with no slack whose format forbids trailing placement. This keeps the
existing invariant intact — the library does not rewrite bytes it does not have to.

JXL is exempt: its boxes are self-contained with no offset table, so it is read-write with no
fixup at all.

## 5. Per-container carrier matrix

The `throws` entries are the part that the read-write decision forces.

| | EXIF | XMP | ICC | IPTC |
|---|---|---|---|---|
| **AVI** | no TIFF carrier → **throws** (see the verified note below) | `_PMX` chunk ✅ | no carrier → **throws** ✅ | no carrier → **throws** ✅ |
| **QuickTime/MP4** | `moov/udta` | `uuid` box, Adobe's `BE7ACFCB-97A9-42E8-9C71-999491E3AFAC` | `colr` in the sample description | no carrier → **throws** |
| **HEIF/AVIF** | `Exif` item via `iloc` (4-byte TIFF-header offset prefix precedes the TIFF data) | `mime` item, `application/rdf+xml` | `colr` with `prof` | no carrier → **throws** |
| **JXL** | `Exif` box | `xml ` box | `jumb`/none | no carrier → **throws** |

The `brob` asymmetry resolved differently than planned. Reading one needs Brotli, which PHP does
not bundle, and a branch that only runs with an optional extension cannot satisfy the coverage
gate — so `brob` is **recognized but never decompressed**: a carrier present only in compressed
form reads as absent, and `getHasBrotliCarrier()` lets a caller tell that from a genuinely empty
file. Writing a carrier **removes** the `brob` that stood for it, so a file never holds a plain
copy and a compressed copy that disagree. Adding real decompression later means adding an
ext-brotli read path *and* a way to cover it in CI.

## 6. Order of work

1. ~~Make `RIFFContainer::parse()` recurse into `LIST`.~~ **Done.** `RIFFContainer::parseChunks()` is the shared
   walker for the container body and every list payload; `RIFFList` (`src/RIFFList.php`) is a chunk to whatever
   holds it and a chunk collection in its own right. Three invariants the tests pin down:
   children are materialized on demand, but *once materialized the payload is always recomposed
   from them* (so a child edited in place is never lost to a stale copy); the walker returns the
   trailing bytes that are not a whole chunk so a rewrite stays byte-faithful; and a **deferred**
   list stays an opaque chunk, because not parsing it is what a lazy read is for. `LIST` defers by
   its bare id or by the qualified `LIST:<listType>`, which is how AVI streams `movi` past while
   still reading `hdrl` and `INFO`.
2. ~~`AVIImage` on top of it.~~ **Done** (`src/AVIImage.php`). `LIST INFO` read-write (`getInfo()`/`setInfoValue()`),
   XMP in `_PMX`, the `IDIT` timestamp, privacy scrubbing by category, and `movi` deferred on
   the lazy path. The `idx1` policy landed as a single placement rule in `placeChunk()`: **the
   `movi` list never moves.** A new chunk is appended past everything; a chunk after `movi` is
   rewritten where it is; a chunk before `movi` is rewritten in place only while its length is
   unchanged, and otherwise its slot is overwritten with `JUNK` of exactly that length and the
   new chunk is appended at the end. No index is ever fixed up because nothing an index
   addresses ever moves — which also removes the OpenDML `indx` case entirely.
3. ~~Lift the BMFF box model out of `JUMBFBox` into a shared box tree.~~ **Done** (`src/BMFF/BMFFBox.php`).
   `BMFFBox` owns the grammar — the 32-bit size, the `size == 1` 64-bit form, the `size == 0`
   run-to-the-end form, `uuid` user types, and depth-capped nesting — and `JUMBFBox` keeps only
   what JUMBF *means* by those boxes (it went from 373 lines to 210). Which types are
   containers is format knowledge, so a subclass names them in `ContainerTypes`; the base
   names none, which is exactly a flat JPEG XL container. Two invariants beyond the old
   code: the **stored size form is kept**, so a box written with the 64-bit or
   run-to-the-end length is written back that way rather than re-encoded, and a container
   keeps its **remainder** of trailing non-box bytes. One non-obvious consequence found by
   a test: container-ness must be **per instance**, not derived from the type alone — a
   container stopped by `MaxDepth` holds bytes, and a type-derived predicate made its
   rewrite compose an empty container and drop the payload.
4. ~~`JXLImage` — the cheapest real win.~~ **Done** (`src/JXLImage.php`). It needed **no subclass of `BMFFBox`**:
   JXL declares no container boxes, so the base grammar's flat sequence is exactly a JXL
   container, which is the clearest evidence step 3 factored the right thing. Carriers:
   `Exif` (honouring the four-byte TIFF-header offset, written as zero), `xml `, and `jumb`
   through the existing JUMBF class. ICC lives in the codestream and IPTC has no carrier, so
   both setters throw. Setting metadata on a **bare codestream promotes it to a container**,
   carrying the codestream verbatim into `jxlc` — losing nothing rather than refusing.
5. ~~`BMFFFile` — `ftyp` dispatch, `meta`/`iloc` items, the padding strategy.~~ **Done** (`src/BMFFFile.php`),
   as one class rather than a separate QuickTime one: the brand only names the flavour, and
   both flavours are the same box tree. `BMFFFileBox` carries the container list and the one
   thing that breaks every naive reader — **`meta` is a FullBox**, so four bytes of version
   and flags sit before its children; treating it as a plain container reads them as a box
   length and desynchronizes the rest of the file. `BMFFItem` joins an `iinf`/`infe`
   description to its `iloc` location.

   The write strategy turned out better than the "pad and append" plan: **an item's bytes are
   appended in a fresh `mdat` and its `iloc` extent is patched in place**, which works because
   patching two numbers does not change `iloc`'s length. Measured on a real libheif HEIC:
   rewriting an item changes **exactly two bytes** of the original file, both inside `iloc`,
   and `mdat` does not move. If a new offset or length would not fit the widths `iloc` already
   uses, the write throws rather than widening a field and shifting the file. Top-level boxes
   (the Adobe `uuid` XMP box) still use the AVI rule: in place at unchanged length, otherwise
   `free` over the old slot and append.

   One fact only a real file taught: libheif puts an item's whole address in the `iloc`
   **base offset** and leaves the extent offset zero, while other writers leave the base zero.
   A rewrite has to be relative to the base or it silently corrupts one of the two layouts.

Step 4 precedes step 5 deliberately: JXL exercises the new box model without the offset problem,
so the hardest container starts on proven code.

## 6a. Reusing a slot when the replacement is smaller

The never-move rule originally reused a slot only for a replacement of **exactly** the same
length; anything else vacated the slot to padding and appended at the end, which grows the file
and moves the metadata away from where a reader looks for it.

A smaller replacement can keep its slot too, by writing it where it was and filling what is left
over with a padding chunk (`JUNK` in RIFF, `free` in BMFF).  The constraint is that the leftover
has to hold that padding chunk's **own header** — eight bytes in both formats — so:

| Bytes freed | Result |
|---|---|
| 0 | written in place, no padding needed |
| 1–7 | **cannot** reuse the slot: too small to hold a padding chunk |
| 8 or more | written in place, with padding of (freed − 8) payload bytes |

RIFF pads payloads to an even length, so in practice the unusable shortfalls there are two, four
and six bytes.  `AVIImage::slotGap()` and `BMFFFile::slotGap()` are the one-line rule; both fall back to the
vacate-and-append path when it returns null.

Measured on real files: shortening an AVI's `INAM` title and an MP4's XMP `uuid` box both now
leave the file **exactly the same length** with the media unmoved, where before each grew by the
new content and left the old slot as dead padding.

### The file's padding is a pool, not just a slot

A chunk that outgrows its own slot does not have to go to the end of the file.  Padding
anywhere in the file — `JUNK` in RIFF, `free` in BMFF — is space already set aside, so writing
into it moves nothing.  The write therefore vacates its own slot, **merges any now-adjacent
padding** (two runs occupy the same bytes as one of their combined length, so merging moves
nothing either), and then takes the *smallest* run that can hold it, leaving the larger ones
for a larger write later.  Only when no run fits does it go to the end.

Measured on a real ffmpeg AVI, which carries a 1016-byte `JUNK`: growing the `LIST INFO` from
68 to 306 bytes merged the vacated slot into that padding and wrote into it — **file length
unchanged**, media unmoved, ffprobe still reading the result.

### When the never-move rule does not apply

The rule is conservative because a file may contain structures this library does not model.
When every structure that addresses the media can be *shown* to survive a move, it does not
apply and the write is simply made, with everything after it shifting.

`AVIImage::getCanRearrange()` decides that for AVI, and it comes down to two questions: is there an
OpenDML index (`indx`, and the `ix##` chunks it points at, hold **file-absolute** positions —
refuse), and is `idx1` measured from `movi` rather than from the file (a relative table stays
correct however far the media moves — both conventions are in the wild, so it is checked
against where the media list's first chunk actually sits).  A deferred media list has not been
read, so nothing can be proven about it and the answer is no.

Measured on the same real AVI: `getCanRearrange()` is true, growing `INFO` from 68 to 498 bytes
grows the file by exactly that, the 1016-byte `JUNK` is left **untouched**, every `idx1` entry
still names the chunk it points at, and ffmpeg decodes the result cleanly.

BMFF keeps the conservative rule: a movie carries `stco`, `saio` and fragment offsets as well,
and a still already has the provable `iloc` relaxation of section 6a.

### The ICC property, and the index caveat

`colr` lives in `iprp`/`ipco`, where a property is addressed by its **position** — so the slot
trick cannot simply drop padding next to it: inserting a box in the middle renumbers every
association above it.  Two rules keep the indices still:

- padding always goes **last** in `ipco`, so no existing property changes index (a new `colr`
  is inserted just before it, which only moves the padding's own index, and nothing refers to
  that);
- **removal swaps the `colr` for a `free` box of exactly the same length** rather than deleting
  it, so its index stays occupied and nothing renumbers — only the association naming it goes.

Because padding is a property that already exists, a second shrink has **no eight-byte floor**:
it just grows.  The floor only applies to creating the padding in the first place.  A write also
pays for the association it adds or drops, so the trade is made against the measured change in
the whole `meta` box, and an attempt that cannot be paid for is undone and falls back to
`resizeMeta()`.

Measured on real libheif files: adding a profile still resizes (there is no padding yet), but
**shrinking it, growing it back, and removing it all leave the file exactly the same length**
with the media unmoved, and ImageMagick reads the profile at each step.  libheif was checked to
tolerate a `free` box among the item properties before this was built on.

## 7. To verify before implementing

- ~~Whether AVI EXIF is standardized as `LIST exif`.~~ **Verified** against ExifTool's RIFF
  module: `LIST exif` is an *attribute list* (`ecor` Make, `emdl` Model, `emnt` MakerNotes,
  `etim` TimeCreated, `ever` ExifVersion), not a TIFF block, and ExifTool documents it for WAV.
  AVI therefore has **no TIFF/Exif carrier**, so `setEXIF()` throws. The attribute list itself
  is the same structure `EXIFAudio` already models for WAVE; exposing it on AVI is follow-up
  work, not a gap in the carrier matrix.
- ~~Whether `idx1` offsets are `movi`-relative or file-absolute.~~ **Answered — and it turned
  out to matter after all.** Under the placement rule alone it is moot: `movi` never moves, so
  neither form is ever invalidated. But the exception to that rule rests on exactly this
  question. `AVIImage::getCanRearrange()` is true only when `idx1` is absent or measured from
  `movi`, which it decides by checking the table against where the media list's first chunk
  actually sits, because both conventions are in the wild. See *When the never-move rule does
  not apply* in section 6.
- ~~JXL pixel dimensions.~~ **Done** (`src/JXLSizeHeader.php`), once `cjxl` turned out to be available to
  validate against. The `SizeHeader` is bit-packed **least-significant first within each byte,
  with a field's first bit its low bit** — which is neither order the framework's bit reader
  offers (its LSB-first mode still packs a field most-significant first, so it reads 20 where
  the format means 5), so the few lines of bit reading are local. A `small` flag gives a
  five-bit height in eighths; otherwise the height is a `U32` (two-bit selector over 9/13/18/30
  bits, stored one less); then a three-bit aspect ratio derives the width, or zero means the
  width is stored the same way. `getCodestream()` assembles it from `jxlc`, or from the `jxlp`
  parts — each behind a **four-byte index whose high bit marks the last part**, read off a real
  `--lossless_jpeg` file — joined in index order. Checked against 24 `cjxl` files covering every
  ratio, both height forms and the 9/13/18-bit selectors; seven of their byte prefixes are kept
  in the tests as regression vectors.
- ~~Whether `RIFX`/RF64 are in scope.~~ **Done**, in `RIFFContainer` itself, so every form built on it
  gets them. `RIFX` is the same grammar with **every size big-endian**, and the byte order is
  carried into nested `RIFFList`s so a rewrite is correct at any depth. `RF64`/`BW64` put the
  sentinel `0xFFFFFFFF` in the container size and the real 64-bit sizes in a leading `ds64`
  chunk — one field for the container, one for `data`, and a table for anything else; a chunk
  whose stored size is the sentinel resolves through it, and a rewrite **updates `ds64` to
  what it actually wrote** while leaving the sample count alone. A 64-bit file with no usable
  `ds64` cannot resolve a sentinel, so the rewrite states the real size rather than keeping an
  unreadable one.

  Validated against ffmpeg's own `-rf64 always` output: read, edited, and **ffprobe still
  reads the edited file correctly**. `RIFX` has no third-party validator here — ffmpeg sniffs
  the magic but misreads the sizes (0.000002s against the true 0.05s, before any edit of
  ours), so it is checked cross-implementation instead: a Python-written `RIFX` conversion of
  a real WAV parses to the same chunk list as the little-endian original and rewrites
  byte-faithfully.
- ~~**QuickTime/MP4 `udta` text atoms and the iTunes `meta`/`ilst` form.**~~ **Done**, and done
  together as the deferral said they had to be. Both were read off real ffmpeg output: a MOV
  text atom is `[u16 text length][u16 language][text]` under `moov`/`udta`, and an MP4 tag is
  `moov`/`udta`/`meta`/`ilst`/`<key>`/`data` with a `[u32 type indicator][u32 locale][value]`
  body, type 1 being UTF-8. `getUserDataValue()`/`setUserDataValue()` read either and write the
  one the file already uses, falling back to the brand (`qt  ` gets an atom, anything else a
  tag); removing clears both so a value cannot survive in the convention that was not chosen.
  `©xyz` is the Location entry in the privacy list.

  Resizing `moov` is the new hazard, since it moves `mdat` when the file is faststart-ordered.
  `holdMediaInPlace()` walks forward from `moov`: a free-space box first means the difference
  is taken out of it and nothing later moves; media first means there is nothing to take it
  out of, so the edit is **undone and the write throws**; neither found means nothing addressed
  follows `moov` and it may grow freely. Verified on real files — an ordinary ffmpeg MP4 and
  MOV (`moov` last) accept any change, and a `+faststart` MP4 with a zero-payload `free` box
  refuses growth while still accepting a shrink, both without moving `mdat`.
- ~~**The `colr`/`prof` ICC property.**~~ **Done**, read and write. A profile lives in a `colr`
  box among the `iprp`/`ipco` item properties, associated to the primary item through `ipma`
  (non-essential, as libheif writes it); adding one appends the property and the association,
  removing one deletes it and **renumbers every higher index**, since a property is addressed
  by its position. `nclx` colour boxes state coefficients rather than a profile and read as none.

  This is the one place the never-move rule is relaxed, and only where relaxing it is provable:
  a profile of the same length is written in place, and any other change resizes `meta` and
  moves the media — allowed **only for a still**, where `iloc` is the whole set of absolute
  references and every one is corrected. A file with a `moov`/`moof` is refused, because `stco`,
  `saio` and the fragment offsets address the file too. Any refusal undoes the edit rather than
  leaving a half-written file.

  Real-writer fact that a shrink exposed: the shift must be applied to whichever `iloc` field
  carries the address — the **base offset** when the table has one (libheif's layout), the extent
  offset otherwise. Applying it to the extent alone produced a negative offset on removal.
  Verified end to end on libheif HEIC and AVIF: add a 552-byte profile, replace it with an
  864-byte one, remove it, and the file returns to its original size with the image item intact
  and ImageMagick still decoding it at each step.
- ~~**Creating** an item.~~ **Done** (`BMFFFile::addItem()`), and it needed no `stco`/`co64` fixup
  after all — that note predated `resizeMeta()`, which the ICC work built. Creating an item is
  the same operation: append the `infe` entry, the `iloc` extent and an `iref`/`cdsc` reference
  saying the new item describes the picture, let `resizeMeta()` correct every item location for
  the growth, then point the new item at bytes appended in a fresh `mdat`. Still-only, for the
  same provable reason, and a refusal undoes.

  The `iref`/`cdsc` link is the part that is easy to leave out and fatal to omit: without it a
  reader has no way to tell which image an `Exif` or XMP item belongs to. `setEXIF()` now
  creates the item instead of throwing, and `setXmpText()` on a still creates the `mime` item
  HEIF defines rather than falling back to the Adobe `uuid` box (a movie still gets the box).
  Verified by decoder, not just by round trip: ImageMagick/libheif reads back
  `exif:Artist` and the XMP profile from items this code created in real HEIC and AVIF files,
  with the picture item intact.
