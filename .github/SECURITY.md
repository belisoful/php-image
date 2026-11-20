# Security policy

php-image is meant to be handed files from strangers: uploads, scans, whatever a browser or a
camera produced. A file that makes it misbehave is a security bug, not an edge case.

## Reporting a vulnerability

Please report privately rather than in a public issue:

**<https://github.com/belisoful/php-image/security/advisories/new>**

That opens a private advisory only the maintainer can see. If you cannot use it, write to the
author instead: Brad Anderson <belisoful@icloud.com>.

A useful report says:

- the version or commit you tested, your PHP version and `memory_limit`;
- what happened: a hang, memory exhaustion, an error of a type the documentation does not
  promise, data left in a file after it was removed, or a file corrupted by a rewrite;
- a file that shows it, or the script that builds one. Small is better.

php-image and [prado-image](https://github.com/belisoful/prado-image) are mirrors of one library, so a
report against either is treated as a report against both. One report is enough.

## What to expect

This is a one-maintainer project. Reports are acknowledged as they are read, fixed in both
repositories together, and credited in the changelog unless you would rather not be named.
Please give a fix the chance to ship before publishing details.

## Advisories

A reported vulnerability is fixed in a tagged release, and an advisory is published with that
release at <https://github.com/belisoful/php-image/security/advisories>. It says what
was wrong, which versions are affected and which is patched, and carries a CVE identifier.
Advisories are filed for both Composer packages, `belisoful/php-image` and
`belisoful/prado-image`, so `composer audit` and Dependabot report them to anyone who depends
on either.

## Supported versions

The library is pre-1.0. Fixes land in the next release; earlier releases are not patched.

## Limits you can set

Four ceilings bound what an untrusted file can cost, and each can be moved:
`ImageFile::setMaxFileSize()` for the length of a file read whole,
`PNGImage::setMaxInflatedSize()` for compressed PNG chunks, `ImageGraphics::setMaxPixels()` for
the pure-PHP raster decoders, and `ImageFile::setMaxParts()` for the number of structural parts
a file may hold. All four are sized from a memory budget: half of PHP's `memory_limit` unless
`ImageFile::setMemoryBudget()` says otherwise, in bytes, as a size such as `'256M'`, or as a share
of the limit such as `'25%'`. The README
paragraph that begins "Files from strangers" describes them.
