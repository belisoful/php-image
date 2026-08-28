<?php

/**
 * IPTCTags class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\Meta;

/**
 * IPTCTags class.
 *
 * Enumerates the IPTC IIM 4.1 dataset identifiers in the `record#dataset` string form
 * that {@see IPTC} uses as map keys, covering the Envelope (record 1), Application
 * (record 2), NewsPhoto (record 3), and object-data (records 7-9) sets.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @see https://exiftool.org/TagNames/IPTC.html
 * @see https://www.iptc.org/std/IIM/4.1/specification/IIMV4.1.pdf The official spec, without Record 3 (News Photo) info
 * @see https://www.iptc.org/std/IIM/4.1/specification/Dnprv4.pdf The official Record 3 (News Photo) information.
 */
class IPTCTags
{
	//	Envelope Records
	public const EnvelopeRecordVersion = '1#000';
	public const Destination = '1#005';
	public const FileFormat = '1#020';
	public const FileVersion = '1#022';
	public const ServiceIdentifier = '1#030';
	public const EnvelopeNumber = '1#040';
	public const ProductID = '1#050';
	public const EnvelopePriority = '1#060';
	public const DateSent = '1#070';
	public const TimeSent = '1#080';
	public const CodedCharacterSet = '1#090';
	public const UniqueObjectName = '1#100';
	public const ARMIdentifier = '1#120';
	public const ARMVersion = '1#122';

	//	Application Records
	public const ApplicationRecordVersion = '2#000';
	public const ObjectTypeReference = '2#003';
	public const ObjectAttributeReference = '2#004';
	public const ObjectName = '2#005';
	public const EditStatus = '2#007';
	public const EditorialUpdate = '2#008';
	public const Urgency = '2#010';
	public const SubjectReference = '2#012';
	public const Category = '2#015';
	public const SupplementalCategories = '2#020';
	public const FixtureIdentifier = '2#022';
	public const Keywords = '2#025';
	public const ContentLocationCode = '2#026';
	public const ContentLocationName = '2#027';
	public const ReleaseDate = '2#030';
	public const ReleaseTime = '2#035';
	public const ExpirationDate = '2#037';
	public const ExpirationTime = '2#038';
	public const SpecialInstructions = '2#040';
	public const ActionAdvised = '2#042';
	public const ReferenceService = '2#045';
	public const ReferenceDate = '2#047';
	public const ReferenceNumber = '2#050';
	public const DateCreated = '2#055';
	public const TimeCreated = '2#060';
	public const DigitalCreationDate = '2#062';
	public const DigitalCreationTime = '2#063';
	public const OriginatingProgram = '2#065';
	public const ProgramVersion = '2#070';
	public const ObjectCycle = '2#075';
	public const ByLine = '2#080';
	public const ByLineTitle = '2#085';
	public const City = '2#090';
	public const SubLocation = '2#092';
	public const ProvinceState = '2#095';
	public const CountryPrimaryLocationCode = '2#100';
	public const CountryPrimaryLocationName = '2#101';
	public const OriginalTransmissionReference = '2#103';
	public const Headline = '2#105';
	public const Credit = '2#110';
	public const Source = '2#115';
	public const CopyrightNotice = '2#116';
	public const Contact = '2#118';
	public const CaptionAbstract = '2#120';
	public const LocalCaption = '2#121';
	public const WriterEditor = '2#122';
	public const RasterizedCaption = '2#125';
	public const ImageType = '2#130';
	public const ImageOrientation = '2#131';
	public const LanguageIdentifier = '2#135';
	public const AudioType = '2#150';
	public const AudioSamplingRate = '2#151';
	public const AudioSamplingResolution = '2#152';
	public const AudioDuration = '2#153';
	public const AudioOutcue = '2#154';
	public const JobID = '2#184';
	public const MasterDocumentID = '2#185';
	public const ShortDocumentID = '2#186';
	public const UniqueDocumentID = '2#187';
	public const OwnerID = '2#188';
	public const ObjectPreviewFileFormat = '2#200';
	public const ObjectPreviewFileVersion = '2#201';
	public const ObjectPreviewData = '2#202';
	public const Prefs = '2#221';
	public const ClassifyState = '2#225';
	public const SimilarityIndex = '2#228';
	public const DocumentNotes = '2#230';
	public const DocumentHistory = '2#231';
	public const ExifCameraInfo = '2#232';
	public const CatalogSets = '2#255';

	//	News Photo Records
	public const NewsPhotoVersion = '3#000';
	public const IPTCPictureNumber = '3#010';
	public const IPTCImageWidth = '3#020';
	public const IPTCImageHeight = '3#030';
	public const IPTCPixelWidth = '3#040';
	public const IPTCPixelHeight = '3#050';
	public const SupplementalType = '3#055';
	public const ColorRepresentation = '3#060';
	public const InterchangeColorSpace = '3#064';
	public const ColorSequence = '3#065';
	public const ICC_Profile = '3#066';
	public const ColorCalibrationMatrix = '3#070';
	public const LookupTable = '3#080';
	public const NumIndexEntries = '3#084';
	public const ColorPalette = '3#085';
	public const IPTCBitsPerSample = '3#086';
	public const SampleStructure = '3#090';
	public const ScanningDirection = '3#100';
	public const IPTCImageRotation = '3#102';
	public const DataCompressionMethod = '3#110';
	public const QuantizationMethod = '3#120';
	public const EndPoints = '3#125';
	public const ExcursionTolerance = '3#130';
	public const BitsPerComponent = '3#135';
	public const MaximumDensityRange = '3#140';
	public const GammaCompensatedValue = '3#145';

	//	Pre Object Data Records
	public const SizeMode = '7#010';
	public const MaxSubfileSize = '7#020';
	public const ObjectSizeAnnounced = '7#090';
	public const MaximumObjectSize = '7#095';

	//	Object Data Record
	public const SubFile = '8#010';

	//	Post Object Data Record
	public const ConfirmedObjectSize = '9#010';
}
