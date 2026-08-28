# php-image Agent Guidelines

## Build, Lint, and Test Commands

### Running Tests
- **All Unit Tests**: `vendor/bin/phpunit --testsuite unit` - runs all unit tests
- **Test Filter**: `vendor/bin/phpunit --testsuite unit --filter <test function, class, or directory>`

### Linting and Code Analysis
- **PHPStan Analysis**: `vendor/bin/phpstan analyse src/ --memory-limit=512M`
- **PHP CS Fixer (Dry-run)**: `vendor/bin/php-cs-fixer fix --dry-run src/` (check)
- **PHP CS Fixer (Fix)**: `vendor/bin/php-cs-fixer fix src/` (apply fixes)

### Build Commands
- **Install Dependencies**: `composer install` - installs all dependencies
- **Updating Dependencies**: `composer update` - updates all dependencies

## Code Style Guidelines
- "if" has a statement block after
- Use php-cs-fixer to correct code styles

### PHP Coding Standards
- Follow PSR-4 autoloading standard
- All PHP files must begin with `<?php` tag (short open tags not allowed)
- Use 1 tab for indentations (no spaces)
- All class names must be in PascalCase
- All method names must be in camelCase
- All variable names must be in camelCase
- Constants must be in SCREAMING_SNAKE_CASE
- All class properties must be declared with visibility modifiers (public, protected, private)

### Naming Conventions
- Class names: `PascalCase` (e.g., `ImageFile`, `TIFFDocument`); no class-name prefix
- Interfaces: `PascalCase` with an `Interface` suffix (e.g., `CompressorInterface`)
- Method names: `camelCase` (e.g., `getComponent`)
- Variables: `camelCase` (e.g., `$componentName`)
- Constants: `SCREAMING_SNAKE_CASE` (e.g., `MAX_RETRY_COUNT`)
- Namespace: `Belisoful\Image\{Module}` (e.g., `Belisoful\Image\Meta\EXIF`)

### Documentation Standards
- All public methods must have PHPDoc comments with:
  - `@param` for parameters
  - `@return` for return values  
  - `@throws` for exceptions
- Classes must have a clear and comprehensive docblock at the top with class description with:
  - Examples, where necessary
  - `@author` for attribution
- Inline comments should be in English and start with `//`
- Do NOT add `@since` tags: the library is at its initial release (v1.0.0), so every symbol is "since 1.0.0" and the tag carries no information.
- All documentation should be written in present perfect tense

### Error Handling
- Use try/catch blocks for operations that can fail
- Throw PHP's own SPL exceptions; the package defines none of its own. Use `\InvalidArgumentException` for a bad argument or type from the caller, `\UnexpectedValueException` for data that parsed but is not what the format requires, and `\RuntimeException` for IO, environment, and state failures.
- Return false or null for methods that are designed to fail gracefully
- All methods should handle edge cases and validate input parameters
- Exception messages are written at the throw site, with `sprintf()` when they interpolate values. There is no message-code indirection and no message catalogue.

### Imports and Includes
- Use PSR-4 autoloading - no manual includes required
- Third-party libraries are loaded via Composer
- Use proper `use` statements for namespaces at the top of PHP files


### Library Specific Guidelines
- Classes are plain PHP: no component base class, no events, no behaviors.  Property access is explicit `getX()`/`setX()` accessors.
- Parse factories are static (`fromString()`/`fromStream()`/`fromFile()`); classes whose factories call `new static()` declare a **final** constructor so subclasses stay instantiable there.
- This is a new, pre-release library with no published API to preserve, so backward compatibility is NOT a constraint; prefer the better design over a compatible one
- A full check consists of the 4 checks (in order): `php -l` compile, php-cs-fixer, phpstan, phpunit (all checks must pass successfully)
- A full check must be done for code to be ready for git commit.
- The current version of this library is **v1.0.0** (initial release). It requires PHP 8.1+ and `psr/http-message` only. Because it is the initial release, source docblocks carry no `@since` tags.
- Classes live under `Belisoful\Image\`, `Belisoful\Image\TIFF\`, `Belisoful\Image\GIF\`, `Belisoful\Image\PNG\`, `Belisoful\Image\ICC\`, `Belisoful\Image\Meta\`, `Belisoful\Image\Meta\Makernote\`, `Belisoful\Image\Compression\`, and the infrastructure namespaces `Belisoful\Image\Stream\` and `Belisoful\Image\Util\` (PSR-4 `Belisoful\Image\` → `src/`).
- The tag knowledge bases (`EXIFTags`, `MakernoteTags`, `MakernoteTables`, `PhotoshopResourceNames`) are fact tables from the public specs; keep them complete and factual when extending.
- EXIF rewrites must keep the makernote pinned at its original offset (the `TIFFTag::setPreserveOffset()` invariant). The pin predicate lives in **one** place — `TIFFDocument::isPinned()` — which both `collectPins()` (the compose reservation) and `layoutIfd()` (the actual placement) call, so the reserved-space list can never drift from what the writer pins; do not re-inline that condition. `EXIF`/`TIFFImage` surface those ranges as `getReservedSpaces()`, with `getFreeSpaces()` as the complement over the composed length, so a caller windows the bytes itself; the library ships no stream decorator for this. TIFF files are read-write: keep the `TIFFTag::setExternalData()` strip/tile capture-and-relocate mechanism (and its offsets/byte-counts pairing) intact on any writer change.
- Raster work goes through the `Belisoful\Image\Compression` codecs and `ImageGraphics`; the TIFF 6.0 coverage table is `agents/working/TIFF6-coverage.md`.
- `\UnexpectedValueException` extends `\RuntimeException`, so `catch (\RuntimeException)` answers every malformed-data and IO failure the library raises; `\InvalidArgumentException` (a `\LogicException`) is deliberately outside that net, because a bad argument is a caller bug rather than a runtime condition.
- The library consumes PSR-7 streams but ships none. `Belisoful\Image\Stream\StreamIO` is static whole-string helpers (`readAll`/`writeAll`/`rewind`/`temp`) over a `StreamInterface` or a raw resource; `Belisoful\Image\Stream\BinaryReader` is the offset-driven typed reader the lazy scan needs (a binary reader, not a stream — no write/close/detach/metadata, so it does not re-implement PSR-7). The bit-level IO (`Belisoful\Image\Util`) works on strings, and the compression codecs expose both a whole-string `CompressorInterface` and an incremental `StreamCodec` engine. The only runtime dependency is `psr/http-message`.
- The readers parse and rewrite the image **container** (segments/chunks); they never decode or re-encode the pixel data. Keep that property: an edit-and-save round trip must be byte-faithful outside the edited metadata.
- Known chunk/block type codes live in a per-format vocabulary — `PNGChunkType` and `RIFFChunkType` (4CC strings) and `GIFBlockType` (byte labels), each a plain class of constants — not as inline literals; reference the constants and add new codes there. These are **open vocabularies of the known codes, not closed types**: `ImageChunk::getType()` stays a raw string so unknown/private chunks round-trip byte-faithfully, so never type a chunk id as the enum or reject an id for being absent from it.
- Raster conversion is dual-backend via `ImageGraphics` (`Belisoful\Image\ImageGraphics`), which routes to an `ImageGraphicsLibraryInterface` implementation — `ImageGraphicsGD` or `ImageGraphicsImagick`: image-taking methods accept `\GdImage|\Imagick` and route by the image's own type, image-producing methods take an optional `ImageGraphicsMode` name (null = default; GD preferred, Imagick fallback). Do NOT call gd/imagick functions directly in the metadata classes; add the primitive to `ImageGraphicsLibraryInterface`, implement it in BOTH backends, and delegate from the facade. When only one backend can do something, declare an `ImageGraphicsLibraryInterface::Capability*` and gate on `ImageGraphics::supports()`; every capability needs an operation behind it (`CapabilityHighBitDepth` is the one documented exception). Prefer an honest software fallback in the weaker backend (as `ICCTransform` does for GD's ICC conversions) over approximating a result — a conversion that cannot be done exactly returns false/null so the caller can choose the capable library via `ImageGraphics::getCapableLibrary()`.

## Testing Guidelines
- The testing platform is "phpunit"
- All new code must include unit tests
- Unit test functions must comprehensively assert both typical and edge cases
- Maximal coverage of code execution paths of a class is required
- Test error conditions and exception handling
- Use mock objects where appropriate
- Functional tests should verify complete user workflows
- Tests should be isolated from each other (no shared state)
- Test images are generated in memory with `ext-gd` (imagejpeg/imagepng/imagewebp); do not commit binary image fixtures.
- Imagick-path tests must `markTestSkipped` when `ext-imagick` is not loaded; never hard-fail on a missing optional extension.
- When unit testing one or cluster of classes, only run the unit tests for that class or cluster/directory.
- Coverage drivers disagree about which line an `else` belongs to. Xdebug on PHP 8.1 has
  been observed marking an `else` body executed when only the `if` branch ran (measured in
  `ICCProfile::utf16BeToUtf8()`), while CI's pcov on 8.2+ reports it correctly — so a local
  100% can hide a branch no test drives. The gate in CI is the authority; when a line is
  reported uncovered there but covered locally, believe CI and write the test that actually
  exercises the branch. Do not chase it by weakening the gate.
- Coverage is gated at two depths and both are expected to hold. **Lines: 99.82%** —
  `tests/test_tools/coverage-gate.php`, run on every push. **Branches: 99.69%** —
  `tests/test_tools/branch-gate.php`, run by the `branches` job of `.github/workflows/php-image.yml` on pull requests and main,
  because a `--path-coverage` run takes far longer than the suite itself. Branch coverage is
  the stronger measure: it catches a decision that only ever goes one way, which a covered
  line hides. The branch job runs against **`phpunit-branch.xml`**, which holds `src/Stream`
  and `src/Util` out of the path-coverage scope — path coverage records every distinct path
  through a function, and the codecs call `BitReader::readBits()`, `BitWriter::writeBits()`,
  and `Stream::read()`/`write()` tens of thousands of times per test, so including them makes
  the run combinatorial. Measured: `LZWCompressorTest` alone takes 24 s out of scope and over
  four minutes in; the whole suite had not finished after two and a half hours in scope, and
  out of scope lands near the 4131 s the equivalent run takes on the pre-port repository,
  where these classes were framework code and never in `src` at all. They remain in the line
  gate and under PHPStan level 4, which reports the never-flipping condition branch coverage
  is for. Do not widen that scope without re-measuring the runtime first. Every one of the 19 remaining untaken branches is unreachable by construction,
  and the gate's per-file figures are **maximums with a total cap**, not exact counts: the
  compiler emits these edges, so which site carries one moves between PHP versions — PHP 8.1
  reports the dead multi-catch rethrow in `TIFFDocument::scanIfd()` and PHP 8.3 the identical
  one in `EXIF::scanStream()`. A file under its maximum is reported, not failed.
  and most are not code anyone wrote — PHP emits an implicit `UnhandledMatchError` edge for a
  `match` behind a range guard, an implicit `return null` after a `while (true)` that only
  exits by return or throw, an implicit `default` for a `switch` over a validated private
  field, and an implicit rethrow for a multi-catch whose `try` can only raise the listed
  types. The rest are guards made redundant by an identical earlier check. Do not chase them.
- Line coverage of `src` is **99.82%** and is expected to stay there: a change that adds
  an uncovered line is a change that needs a test.  Thirteen lines are knowingly
  unreachable from a test, and each is unreachable for a stated reason — do not "cover"
  them with contrived tests, and do not silence them with `@codeCoverageIgnore`:
  - `CCITTFaxCompressor::writeRun()` — the `$makeup < 64` break.  Every multiple of 64
    from 64 to 2560 has a code in `ExtendedCodes` or in both colour tables, so the
    make-up search always succeeds on its first iteration.
  - `JUMBFBox::toBinary()` — the 64-bit extended length.  Emitting it needs a single
    in-memory payload larger than 4 GiB.
  - `ImageGraphicsGD::monoPixels()` — the allocation guard.  `imagecreatetruecolor()`
    is called with the *source's* dimensions, so it can only fail for a source of
    ~537 M pixels that must already exist to be passed in (measured: 33 s, 3.7 GB).
    A low `memory_limit` does not help — GD allocates outside PHP's memory manager.
  - `ImageGraphicsImagick::paletteQuantize()` — the over-budget palette lookup.
    ImageMagick caps `quantizeImage(256, …)` at the requested colour count and the
    pixel export can only merge colours, never split them.  The branch's arithmetic is
    asserted directly by `TSmallApiTest::testGraphicsClosestPaletteIndex`.
  - `StreamIO`/`BinaryReader` — `fopen('php://temp')` and `ftell()` failing on a live
    handle; PHP offers no way to make either fail without OS-level fault injection.
  - `BitReader`/`BitWriter` — the 33-64-bit-field rejection reachable only on a 32-bit
    PHP build (the matching tests skip on 64-bit builds).
  - Static analysis, not tests, is the tool for the other kind of gap: a branch that
    runs but whose condition never flips.  PHPStan level 4 found one such dead guard
    (`KonicaMinoltaMakernote`) that 100% line coverage would never have revealed.
- NEVER add/change phpunit command options when unit testing; only run project unit tests as specified

## Development Environment
- PHP 8.1 or higher required
- PHP extensions: ctype, dom, intl, json, pcre, spl (required); gd (required for the unit tests' generated fixtures; one of gd/imagick is required for raster conversion); imagick (optional alternate graphics library); iconv (IPTC charset conversion)
- Composer for dependency management
- Required developer dependencies for code checking: phpunit/phpunit, phpstan/phpstan, friendsofphp/php-cs-fixer
- Presume that project dependencies are installed

## Cursor/Copilot Instructions
No specific Cursor or Copilot rules currently defined for this project.

# php-image Agent Safeguards -- ANTI-PATTERNS
Between the next brackets, it is required without exception:
{
- NEVER (without exception) execute the following "git" commands without asking the developer for approval first: clone, checkout, mv, restore, rm, branch, add, commit, merge, rebase, reset, pull, push, fetch
- NEVER (without exception) execute "rm" commands on any paths without asking the developer for approval first
- NEVER remove composer --dev dependencies because those are a required for development on the Project
- NEVER perform an action that erases or overwrites files for the task of unit testing and fixing; file changes are important and must be kept, because the changes themselves are being unit tested.
- NEVER delete any folders or files until the associated task is absolutely and totally complete.
}
