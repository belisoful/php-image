# Specification sources

The full specifications this extension implements are large PDFs with licenses that
do not permit redistribution, so they are **not** committed. Each is linked here as a
clickable internet shortcut (`*.pdf.url` — double-click opens the source in a browser)
and, for agents, kept as a local working copy under the git-ignored `local/` folder.

| Standard | Web source | Local working copy |
|---|---|---|
| TIFF 6.0 | [download.osgeo.org/libtiff/doc/TIFF6.pdf](https://download.osgeo.org/libtiff/doc/TIFF6.pdf) — see [`TIFF6.pdf.url`](TIFF6.pdf.url) | `local/TIFF6.pdf` |
| Exif 3.1 — CIPA DC-008-2026 (English) | [cipa.jp download (accept the disclaimer)](https://cipa.jp/std/documents/download_e.html?CIPA_DC-008-2026-E=) — see [`CIPA_DC-008-2026-E.pdf.url`](CIPA_DC-008-2026-E.pdf.url) | `local/CIPA_DC-008-2026-E.pdf` |

To work with a spec locally, download it from the link above into `local/`. The
`local/` folder is git-ignored, so nothing license-encumbered enters the repository.

`local/` also holds `PHP_JPEG_Metadata_Toolkit_1.12.zip`, GPL-licensed reference
material analyzed for feature parity — its makers, tag names, and enumerated values (the
factual settings, which are not copyrightable) were reimplemented, never its code.
