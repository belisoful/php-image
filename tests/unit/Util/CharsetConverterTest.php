<?php

use Belisoful\Image\Util\EscCharsetConverter;
use Belisoful\Image\Util\Utf8Converter;

/**
 * Unit tests for the charset helpers: {@see \Belisoful\Image\Util\EscCharsetConverter}
 * (ISO 2022 escape sequences ⇄ iconv names) and {@see \Belisoful\Image\Util\Utf8Converter}
 * (iconv round trips with the optional embedded locale).
 */
class CharsetConverterTest extends PHPUnit\Framework\TestCase
{
	public function testDecodeEscapeCharset()
	{
		self::assertSame('UTF-8', EscCharsetConverter::decodeEscapeCharset("\x1B\x25\x47"));
		self::assertSame('ISO-8859-1', EscCharsetConverter::decodeEscapeCharset("\x1B\x2C\x41"));
		self::assertNull(EscCharsetConverter::decodeEscapeCharset("\x1B\x7F\x7F"));
	}

	public function testEncodeEscapeCharset()
	{
		self::assertSame("\x1B\x25\x47", EscCharsetConverter::encodeEscapeCharset('UTF-8'));
		self::assertNull(EscCharsetConverter::encodeEscapeCharset('NO-SUCH-CHARSET'));
	}

	public function testToUTF8PassThroughAndConversion()
	{
		self::assertSame('plain', Utf8Converter::toUTF8('plain', 'UTF-8'));
		self::assertSame("caf\u{E9}", Utf8Converter::toUTF8("caf\xE9", 'ISO-8859-1'));
	}

	public function testFromUTF8PassThroughAndConversion()
	{
		self::assertSame('plain', Utf8Converter::fromUTF8('plain', 'UTF-8'));
		self::assertSame("caf\xE9", Utf8Converter::fromUTF8("caf\u{E9}", 'ISO-8859-1'));
	}

	public function testEmbeddedLanguageIsParsedAndLocaleRestored()
	{
		$before = setlocale(LC_CTYPE, '0');
		self::assertSame('abc', Utf8Converter::toUTF8('abc', 'ASCII.en_US'));
		self::assertSame('abc', Utf8Converter::fromUTF8('abc', 'ASCII.en_US'));
		self::assertSame($before, setlocale(LC_CTYPE, '0'), 'The locale is restored after conversion.');
	}

	public function testConversionFailureReturnsTheOriginalString()
	{
		// iconv returns false for bytes that are invalid in the source charset, and for a
		// character with no representation in the target; both fall back to the input.
		self::assertSame("\xFF", @Utf8Converter::toUTF8("\xFF", 'ASCII'));
		self::assertSame("\u{4E2D}", @Utf8Converter::fromUTF8("\u{4E2D}", 'ISO-8859-1'));
	}

	public function testParseEncodingLanguage()
	{
		$encoding = 'ASCII.de';
		$lang = null;
		Utf8Converter::parseEncodingLanguage($encoding, $lang);
		self::assertSame('ASCII', $encoding);
		self::assertSame('de', $lang);

		$plain = 'UTF-8';
		$lang = null;
		Utf8Converter::parseEncodingLanguage($plain, $lang);
		self::assertSame('UTF-8', $plain);
		self::assertNull($lang);
	}
}
