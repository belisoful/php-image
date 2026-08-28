<?php

use Belisoful\Image\Meta\EXIF;
use Belisoful\Image\Meta\EXIFAudio;
use Belisoful\Image\RIFFContainer;

class EXIFAudioTest extends PHPUnit\Framework\TestCase
{
	/**
	 * Builds a small PCM WAVE file, optionally with an Exif attribute list.
	 * @param array<string, string> $exifChunks The exif list sub-chunks.
	 * @param int $samples
	 */
	private function waveBytes(array $exifChunks = [], int $samples = 8000): string
	{
		$channels = 1;
		$rate = 8000;
		$bits = 8;
		$byteRate = $rate * $channels * intdiv($bits, 8);
		$fmt = pack('vvVVvv', 1, $channels, $rate, $byteRate, $channels * intdiv($bits, 8), $bits);
		$audio = str_repeat("\x80", $samples);

		$body = 'WAVE';
		$body .= 'fmt ' . pack('V', strlen($fmt)) . $fmt;
		if ($exifChunks !== []) {
			$list = 'exif';
			foreach ($exifChunks as $id => $payload) {
				$list .= $id . pack('V', strlen($payload)) . $payload;
				if (strlen($payload) & 1) {
					$list .= "\0";
				}
			}
			$body .= 'LIST' . pack('V', strlen($list)) . $list;
		}
		$body .= 'data' . pack('V', strlen($audio)) . $audio;
		return 'RIFF' . pack('V', strlen($body)) . $body;
	}

	public function testReadsSpecAttributeChunks()
	{
		$bytes = $this->waveBytes([
			'ever' => '0300',
			'erel' => "DSC00001.JPG\0",
			'etim' => "10:05:10.130\0",
			'ecor' => "Digital Still Camera Corporation\0",
			'emdl' => "DSCamera1000\0",
			'emnt' => "\x01\x02\x03maker",
			'eucm' => "ASCII\0\0\0Voice memo",
		]);

		self::assertTrue(EXIFAudio::isWave($bytes));
		self::assertTrue(EXIFAudio::isExifAudio($bytes));

		$audio = EXIFAudio::fromString($bytes);
		self::assertTrue($audio->getHasExifList());
		self::assertSame('0300', $audio->getVersion());
		self::assertSame('DSC00001.JPG', $audio->getRelatedImage());
		self::assertSame('10:05:10.130', $audio->getRecordingTime());
		self::assertSame('Digital Still Camera Corporation', $audio->getManufacturer());
		self::assertSame('DSCamera1000', $audio->getModel());
		self::assertSame("\x01\x02\x03maker", $audio->getMakerNote());
		self::assertSame('Voice memo', $audio->getUserComment());
		self::assertSame('ASCII', $audio->getUserCommentCharset());
		self::assertCount(7, $audio->getAttributes());
	}

	public function testWaveFormatAndDuration()
	{
		$audio = EXIFAudio::fromString($this->waveBytes(['ever' => '0300'], 16000));
		$format = $audio->getFormat();
		self::assertSame(1, $format['format']);
		self::assertSame(1, $format['channels']);
		self::assertSame(8000, $format['sampleRate']);
		self::assertSame(8, $format['bitsPerSample']);
		self::assertEqualsWithDelta(2.0, $audio->getDurationSeconds(), 1e-9);
	}

	public function testEditPreservesAudioDataAndOtherChunks()
	{
		$original = $this->waveBytes(['ever' => '0300', 'erel' => "DSC00001.JPG\0"]);
		$audio = EXIFAudio::fromString($original);
		$audio->setRelatedImage('DSC00042.JPG');
		$audio->setManufacturer('PradoCam Corp');
		$audio->setUserComment('Edited memo');

		$reparsed = EXIFAudio::fromString($audio->toBinary());
		self::assertSame('DSC00042.JPG', $reparsed->getRelatedImage());
		self::assertSame('PradoCam Corp', $reparsed->getManufacturer());
		self::assertSame('Edited memo', $reparsed->getUserComment());
		self::assertSame('0300', $reparsed->getVersion());

		// The fmt and data chunks came through untouched.
		$before = RIFFContainer::fromString($original);
		$after = RIFFContainer::fromString($audio->toBinary());
		self::assertSame(bin2hex($before->getChunk('data')->getData()), bin2hex($after->getChunk('data')->getData()));
		self::assertSame(bin2hex($before->getChunk('fmt ')->getData()), bin2hex($after->getChunk('fmt ')->getData()));
	}

	public function testAddsExifListToPlainWave()
	{
		$plain = $this->waveBytes();
		self::assertTrue(EXIFAudio::isWave($plain));
		self::assertFalse(EXIFAudio::isExifAudio($plain));

		$audio = EXIFAudio::fromString($plain);
		$audio->setVersion('0300');
		$audio->setRelatedImage('DSC00007.JPG');
		$audio->setRecordingTime(new DateTimeImmutable('2026-07-28 14:03:09.250'));

		$bytes = $audio->toBinary();
		self::assertTrue(EXIFAudio::isExifAudio($bytes));
		$reparsed = EXIFAudio::fromString($bytes);
		self::assertSame('0300', $reparsed->getVersion());
		self::assertSame('DSC00007.JPG', $reparsed->getRelatedImage());
		self::assertSame('14:03:09.250', $reparsed->getRecordingTime());
		// Still a valid plain WAVE for any other reader.
		self::assertNotNull(RIFFContainer::fromString($bytes)->getChunk('data'));
	}

	public function testUnicodeUserCommentAndRemoval()
	{
		$audio = EXIFAudio::fromString($this->waveBytes(['ever' => '0300']));
		$audio->setUserComment('メモ 音声');
		self::assertSame('UNICODE', $audio->getUserCommentCharset());

		$reparsed = EXIFAudio::fromString($audio->toBinary());
		self::assertSame('メモ 音声', $reparsed->getUserComment());

		$reparsed->setUserComment(null);
		self::assertNull($reparsed->getUserComment());
		self::assertNull(EXIFAudio::fromString($reparsed->toBinary())->getUserComment());
	}

	public function testOddLengthChunksPadCorrectly()
	{
		$audio = EXIFAudio::fromString($this->waveBytes(['ever' => '0300']));
		$audio->setModel('ODD');                       // 3 + NUL = 4 (even)
		$audio->setManufacturer('FIVEC');              // 5 + NUL = 6 (even)
		$audio->setMakerNote("\x01\x02\x03");          // 3 bytes: needs a pad byte
		$audio->setRelatedImage('A.JPG');              // 5 + NUL = 6

		$reparsed = EXIFAudio::fromString($audio->toBinary());
		self::assertSame('ODD', $reparsed->getModel());
		self::assertSame('FIVEC', $reparsed->getManufacturer());
		self::assertSame("\x01\x02\x03", $reparsed->getMakerNote());
		self::assertSame('A.JPG', $reparsed->getRelatedImage());
	}

	public function testImageAudioLinkage()
	{
		// The image half of the link is the EXIF RelatedSoundFile tag.
		$exif = new EXIF();
		$exif->setValueByName('RelatedSoundFile', 'SND00001.WAV');
		$reparsed = EXIF::fromSegment($exif->toBinary());
		self::assertSame('SND00001.WAV', $reparsed->getValueByName('RelatedSoundFile'));

		$audio = EXIFAudio::fromString($this->waveBytes(['ever' => '0300']));
		$audio->setRelatedImage('DSC00001.JPG');
		self::assertSame('DSC00001.JPG', EXIFAudio::fromString($audio->toBinary())->getRelatedImage());
	}

	public function testStreamAndFileIo()
	{
		$audio = EXIFAudio::fromString($this->waveBytes(['ever' => '0300', 'ecor' => "PradoCam\0"]));

		$stream = new TestPsr7Stream('');
		$audio->writeTo($stream);
		$stream->rewind();
		self::assertSame('PradoCam', EXIFAudio::fromStream($stream)->getManufacturer());

		$path = tempnam(sys_get_temp_dir(), 'exifaudio');
		try {
			self::assertGreaterThan(0, $audio->save($path));
			self::assertSame('PradoCam', EXIFAudio::fromFile($path)->getManufacturer());
		} finally {
			@unlink($path);
		}

		self::expectException(\RuntimeException::class);
		EXIFAudio::fromFile('/nonexistent/nope.wav');
	}

	public function testBlankAudioAndRawAttributeAccess()
	{
		// A blank instance builds its own WAVE container.
		$audio = new EXIFAudio();
		self::assertSame('WAVE', $audio->getRiff()->getFormType());
		self::assertFalse($audio->getHasExifList());

		$audio->setVersion('0300');
		$audio->setAttribute('exlt', "\x09custom");
		self::assertSame("\x09custom", $audio->getAttribute('exlt'));
		self::assertNull($audio->getAttribute('none'));

		$reparsed = EXIFAudio::fromString($audio->toBinary());
		self::assertTrue($reparsed->getHasExifList());
		self::assertSame("\x09custom", $reparsed->getAttribute('exlt'));
		self::assertSame('0300', $reparsed->getVersion());
	}

	public function testAbsentTextAttributesReadAsNull()
	{
		// A WAVE carrying only the mandatory version has none of the text chunks, and
		// none of them is invented as an empty string.
		$audio = EXIFAudio::fromString($this->waveBytes(['ever' => '0300']));
		self::assertNull($audio->getRelatedImage());
		self::assertNull($audio->getRecordingTime());
		self::assertNull($audio->getManufacturer());
		self::assertNull($audio->getModel());
		self::assertNull($audio->getUserComment());
		self::assertNull($audio->getUserCommentCharset());
		self::assertCount(1, $audio->getAttributes());
	}

	public function testRemovingATextAttributeDropsItsChunk()
	{
		$audio = EXIFAudio::fromString($this->waveBytes([
			'ever' => '0300',
			'erel' => "DSC00001.JPG\0",
			'etim' => "10:05:10.130\0",
			'ecor' => "PradoCam\0",
			'emdl' => "PC-2000\0",
		]));
		$audio->setRelatedImage(null);
		$audio->setRecordingTime(null);
		$audio->setManufacturer(null);
		$audio->setModel(null);

		self::assertNull($audio->getAttribute(EXIFAudio::RelatedImageChunk));
		self::assertSame(['ever'], array_keys($audio->getAttributes()));

		$reparsed = EXIFAudio::fromString($audio->toBinary());
		self::assertTrue($reparsed->getHasExifList());
		self::assertSame('0300', $reparsed->getVersion());
		self::assertNull($reparsed->getRelatedImage());
		self::assertNull($reparsed->getRecordingTime());
		self::assertNull($reparsed->getManufacturer());
		self::assertNull($reparsed->getModel());
	}

	public function testDroppingEveryAttributeRemovesTheExifList()
	{
		$audio = EXIFAudio::fromString($this->waveBytes(['ever' => '0300', 'erel' => "DSC00001.JPG\0"]));
		self::assertTrue($audio->getHasExifList());

		foreach (array_keys($audio->getAttributes()) as $id) {
			$audio->setAttribute($id, null);
		}
		self::assertSame([], $audio->getAttributes());

		$bytes = $audio->toBinary();
		self::assertTrue(EXIFAudio::isWave($bytes));
		self::assertFalse(EXIFAudio::isExifAudio($bytes));
		self::assertNull(RIFFContainer::fromString($bytes)->getChunk('LIST'));
		// The audio itself came through untouched.
		self::assertSame(8000, strlen(RIFFContainer::fromString($bytes)->getChunk('data')->getData()));

		// A WAVE that never had a list composes unchanged too.
		$plain = EXIFAudio::fromString($this->waveBytes());
		self::assertFalse($plain->getHasExifList());
		self::assertNull(RIFFContainer::fromString($plain->toBinary())->getChunk('LIST'));
	}

	public function testWaveWithoutFormatOrDataChunks()
	{
		$body = 'WAVE' . 'data' . pack('V', 4) . "\x01\x02\x03\x04";
		$audio = EXIFAudio::fromString('RIFF' . pack('V', strlen($body)) . $body);
		self::assertNull($audio->getFormat());
		self::assertNull($audio->getDurationSeconds());

		// A format chunk too short to hold the sixteen mandatory bytes is not read.
		$short = 'WAVE' . 'fmt ' . pack('V', 8) . pack('vvV', 1, 1, 8000);
		$truncated = EXIFAudio::fromString('RIFF' . pack('V', strlen($short)) . $short);
		self::assertNull($truncated->getFormat());
		self::assertNull($truncated->getDurationSeconds());
	}

	public function testIsExifAudioRejectsNonWaveBytes()
	{
		self::assertFalse(EXIFAudio::isExifAudio('not a riff container at all'));
		self::assertFalse(EXIFAudio::isExifAudio('RIFF' . pack('V', 4) . 'AVI '));
	}

	public function testSaveToAnUnwritablePath()
	{
		$audio = EXIFAudio::fromString($this->waveBytes(['ever' => '0300']));
		try {
			$audio->save('/no-such-directory-for-prado/SND00001.WAV');
			self::fail('save() accepted an unwritable path');
		} catch (\RuntimeException $e) {
			self::assertInstanceOf(\RuntimeException::class, $e);
		}
	}

	public function testRejectsNonWaveRiff()
	{
		$riff = 'RIFF' . pack('V', 4) . 'AVI ';
		self::assertFalse(EXIFAudio::isWave($riff));
		self::expectException(\RuntimeException::class);
		EXIFAudio::fromString($riff);
	}
}
