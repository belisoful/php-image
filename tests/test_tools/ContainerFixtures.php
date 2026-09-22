<?php

/**
 * Minimal, valid bytes for the containers whose fixtures more than one test needs.
 *
 * A test about one format's own layout still builds its own bytes, because there the layout
 * is the point.  These are for the tests that need *a file of this format* rather than a file
 * of a particular shape: the carrier matrix and the privacy scrub.
 *
 * The structures are spelled out as format literals rather than as the constants of the
 * classes under test, so a fixture cannot agree with a typo in one of those constants.
 */
class ContainerFixtures
{
	/** A RIFF chunk, padded to an even length as the format requires. */
	public static function chunk(string $id, string $payload): string
	{
		return $id . pack('V', strlen($payload)) . $payload . ((strlen($payload) & 1) ? "\0" : '');
	}

	/** An ISO base media box. */
	public static function box(string $type, string $payload = ''): string
	{
		return pack('N', 8 + strlen($payload)) . $type . $payload;
	}

	/** A FullBox: a box whose payload opens with a version byte and three flag bytes. */
	public static function fullBox(string $type, string $payload, int $version = 0): string
	{
		return self::box($type, chr($version) . "\x00\x00\x00" . $payload);
	}

	/** A minimal AVI: the header list, any extra chunks, the media list, then the index. */
	public static function avi(string ...$extra): string
	{
		$body = 'AVI '
			. self::chunk('LIST', 'hdrl' . self::chunk(
				'avih',
				pack('VVVVVVVVVVVVVV', 40000, 0, 0, 0x10, 12, 0, 1, 0, 320, 240, 0, 0, 0, 0),
			))
			. implode('', $extra)
			. self::chunk('LIST', 'movi' . self::chunk('00dc', str_repeat("\x11", 64)))
			. self::chunk('idx1', str_repeat("\x22", 16));
		return 'RIFF' . pack('V', strlen($body)) . $body;
	}

	/**
	 * A HEIF still shaped the way libheif writes one: a picture item whose bytes sit in a
	 * trailing `mdat` addressed by the `iloc` **base** offset, and an `iprp` property tree
	 * for an ICC profile to land in.
	 */
	public static function heif(): string
	{
		$data = 'picture bytes';
		$iprp = self::box(
			'iprp',
			self::box('ipco', self::fullBox('ispe', pack('NN', 6, 4)))
				. self::box('ipma', "\x00\x00\x00\x00" . pack('N', 1) . pack('n', 1) . chr(1) . chr(1)),
		);
		$ftyp = self::box('ftyp', 'heic' . pack('N', 0) . 'mif1heic');
		$meta = fn (int $base): string => self::fullBox(
			'meta',
			self::box('hdlr', str_repeat("\0", 8) . 'pict' . str_repeat("\0", 12))
				. self::fullBox('iinf', pack('n', 1)
					. self::fullBox('infe', pack('n', 1) . pack('n', 0) . 'hvc1' . "image\0", 2))
				. self::fullBox('iloc', chr(0x44) . chr(0x40) . pack('n', 1) . pack('n', 1) . pack('n', 0)
					. pack('N', $base) . pack('n', 1) . pack('N', 0) . pack('N', strlen($data)))
				. self::fullBox('pitm', pack('n', 1))
				. $iprp,
		);
		return $ftyp . $meta(strlen($ftyp) + strlen($meta(0)) + 8) . self::box('mdat', $data);
	}

	/**
	 * An MP4 movie with the media ahead of `moov`, which is the ordering that lets `moov`
	 * grow: nothing addressed by an offset follows it.
	 * @param string[] $userData
	 */
	public static function movie(string ...$userData): string
	{
		return self::box('ftyp', 'isom' . pack('N', 0) . 'isommp41')
			. self::box('mdat', str_repeat("\x11", 32))
			. self::box('moov', self::box('mvhd', str_repeat("\0", 100))
				. ($userData ? self::box('udta', implode('', $userData)) : ''));
	}

	/** A QuickTime user-data text atom: a length, a language, then the text. */
	public static function userDataAtom(string $type, string $text): string
	{
		return self::box($type, pack('n', strlen($text)) . pack('n', 0) . $text);
	}

	/**
	 * A JPEG XL container: the signature and file-type boxes, any extra boxes, then a real
	 * `cjxl` codestream whose size header states 64x48.
	 * @param string[] $extra
	 */
	public static function jxl(string ...$extra): string
	{
		return "\x00\x00\x00\x0CJXL \x0D\x0A\x87\x0A"
			. self::box('ftyp', 'jxl ' . pack('N', 0) . 'jxl ')
			. implode('', $extra)
			. self::box('jxlc', (string) hex2bin('ff0acb060013880200f000b5'));
	}
}
