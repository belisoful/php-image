<?php

use Belisoful\Image\Meta\XMP;
use Belisoful\Image\Meta\XMPSchemas;
use Belisoful\Image\JPEGImage;
use Belisoful\Image\PNGImage;
use Belisoful\Image\RIFFChunkType;
use Belisoful\Image\WebPImage;

/**
 * The full XMP value grammar: language alternatives, arrays of structures, nested
 * structures, qualifiers, paths, enumeration — plus the carriers (JPEG with extended
 * XMP, PNG iTXt, WebP XMP chunk).
 */
class XMPFullTest extends PHPUnit\Framework\TestCase
{
	private const REAL_WORLD = <<<'XML'
		<x:xmpmeta xmlns:x="adobe:ns:meta/">
		 <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
		  <rdf:Description rdf:about=""
		    xmlns:dc="http://purl.org/dc/elements/1.1/"
		    xmlns:xmpMM="http://ns.adobe.com/xap/1.0/mm/"
		    xmlns:stEvt="http://ns.adobe.com/xap/1.0/sType/ResourceEvent#"
		    xmlns:stRef="http://ns.adobe.com/xap/1.0/sType/ResourceRef#"
		    xmlns:tiff="http://ns.adobe.com/tiff/1.0/">
		   <dc:title>
		    <rdf:Alt>
		     <rdf:li xml:lang="x-default">Sunset</rdf:li>
		     <rdf:li xml:lang="de">Sonnenuntergang</rdf:li>
		     <rdf:li xml:lang="fr-CA">Coucher</rdf:li>
		    </rdf:Alt>
		   </dc:title>
		   <xmpMM:History>
		    <rdf:Seq>
		     <rdf:li rdf:parseType="Resource">
		      <stEvt:action>created</stEvt:action>
		      <stEvt:when>2026-01-02T03:04:05+00:00</stEvt:when>
		     </rdf:li>
		     <rdf:li rdf:parseType="Resource">
		      <stEvt:action>edited</stEvt:action>
		      <stEvt:softwareAgent>prado-image</stEvt:softwareAgent>
		     </rdf:li>
		    </rdf:Seq>
		   </xmpMM:History>
		   <xmpMM:DerivedFrom rdf:parseType="Resource">
		    <stRef:instanceID>xmp.iid:1234</stRef:instanceID>
		    <stRef:documentID>xmp.did:5678</stRef:documentID>
		   </xmpMM:DerivedFrom>
		   <tiff:Make>PradoCam</tiff:Make>
		  </rdf:Description>
		 </rdf:RDF>
		</x:xmpmeta>
		XML;

	private function jpegBytes(int $w = 12, int $h = 9): string
	{
		$im = imagecreatetruecolor($w, $h);
		ob_start();
		imagejpeg($im);
		imagedestroy($im);
		return ob_get_clean();
	}

	public function testLanguageAlternatives()
	{
		$xmp = XMP::parse(self::REAL_WORLD);
		self::assertSame(
			['x-default' => 'Sunset', 'de' => 'Sonnenuntergang', 'fr-CA' => 'Coucher'],
			$xmp->getLangAlt(XMP::NS_DC, 'title'),
		);
		self::assertSame('Sonnenuntergang', $xmp->getLangAltValue(XMP::NS_DC, 'title', 'de'));
		self::assertSame('Coucher', $xmp->getLangAltValue(XMP::NS_DC, 'title', 'fr-CA'));
		self::assertSame('Coucher', $xmp->getLangAltValue(XMP::NS_DC, 'title', 'fr'));   // primary-language match
		self::assertSame('Sunset', $xmp->getLangAltValue(XMP::NS_DC, 'title', 'ja'));    // x-default fallback
		self::assertSame('Sunset', $xmp->getTitle());
		self::assertSame('Sonnenuntergang', $xmp->getTitle('de'));
		self::assertNull($xmp->getLangAltValue(XMP::NS_DC, 'rights'));
	}

	public function testWriteLanguageAlternatives()
	{
		$xmp = XMP::blank();
		$xmp->setLangAlt(XMP::NS_DC, 'description', ['de' => 'Beschreibung', 'x-default' => 'Caption']);
		$xml = $xmp->toXml();
		// x-default leads, as the specification requires.
		self::assertLessThan(strpos($xml, 'Beschreibung'), strpos($xml, 'Caption'));

		$reparsed = XMP::parse($xml);
		self::assertSame(['x-default' => 'Caption', 'de' => 'Beschreibung'], $reparsed->getLangAlt(XMP::NS_DC, 'description'));
		self::assertSame('Alt', $reparsed->getArrayType(XMP::NS_DC, 'description'));

		// The convenience setters accept a language map too.
		$xmp->setTitle(['x-default' => 'T', 'es' => 'Título']);
		self::assertSame('Título', XMP::parse($xmp->toXml())->getTitle('es'));
	}

	public function testArraysOfStructures()
	{
		$xmp = XMP::parse(self::REAL_WORLD);
		$history = $xmp->getProperty(XMP::NS_MM, 'History');
		self::assertIsArray($history);
		self::assertCount(2, $history);
		self::assertSame(['action' => 'created', 'when' => '2026-01-02T03:04:05+00:00'], $history[0]);
		self::assertSame(['action' => 'edited', 'softwareAgent' => 'prado-image'], $history[1]);
		self::assertSame('Seq', $xmp->getArrayType(XMP::NS_MM, 'History'));
	}

	public function testStructuresAndPaths()
	{
		$xmp = XMP::parse(self::REAL_WORLD);
		self::assertSame(
			['instanceID' => 'xmp.iid:1234', 'documentID' => 'xmp.did:5678'],
			$xmp->getProperty(XMP::NS_MM, 'DerivedFrom'),
		);

		self::assertSame('created', $xmp->getByPath('xmpMM:History[1]/stEvt:action'));
		self::assertSame('edited', $xmp->getByPath('xmpMM:History[2]/stEvt:action'));
		self::assertSame('xmp.iid:1234', $xmp->getByPath('xmpMM:DerivedFrom/stRef:instanceID'));
		self::assertSame('PradoCam', $xmp->getByPath('tiff:Make'));
		self::assertSame('Sunset', $xmp->getByPath('dc:title[1]'));
		self::assertNull($xmp->getByPath('xmpMM:History[9]/stEvt:action'));
		self::assertNull($xmp->getByPath('nosuch:Property'));
	}

	public function testWriteNestedStructuresAndArraysOfStructures()
	{
		$xmp = XMP::blank();
		$xmp->setProperty(XMP::NS_MM, 'History', [
			['stEvt:action' => 'created', 'stEvt:when' => '2026-07-28T10:00:00+00:00'],
			['stEvt:action' => 'converted', 'stEvt:parameters' => 'to WebP'],
		], 'Seq');

		$reparsed = XMP::parse($xmp->toXml());
		$history = $reparsed->getProperty(XMP::NS_MM, 'History');
		self::assertCount(2, $history);
		self::assertSame('created', $history[0]['action']);
		self::assertSame('to WebP', $history[1]['parameters']);
		self::assertSame('converted', $reparsed->getByPath('xmpMM:History[2]/stEvt:action'));
		// The field elements really are in the stEvt namespace.
		self::assertStringContainsString('stEvt:action', $xmp->toXml());

		// A structure nested inside a structure.
		$xmp->setProperty(XMP::NS_MM, 'Pantry', ['name' => 'outer', 'inner' => ['a' => '1', 'b' => '2']]);
		$pantry = XMP::parse($xmp->toXml())->getProperty(XMP::NS_MM, 'Pantry');
		self::assertSame(['name' => 'outer', 'inner' => ['a' => '1', 'b' => '2']], $pantry);
	}

	public function testAttributeShorthandStructuresAndQualifiers()
	{
		$xml = <<<'XML'
			<x:xmpmeta xmlns:x="adobe:ns:meta/">
			 <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
			  <rdf:Description rdf:about=""
			    xmlns:xmpMM="http://ns.adobe.com/xap/1.0/mm/"
			    xmlns:stRef="http://ns.adobe.com/xap/1.0/sType/ResourceRef#"
			    xmlns:dc="http://purl.org/dc/elements/1.1/">
			   <xmpMM:DerivedFrom stRef:instanceID="iid:99" stRef:documentID="did:99"/>
			   <dc:format>
			    <rdf:Description>
			     <rdf:value>image/jpeg</rdf:value>
			     <dc:source>camera</dc:source>
			    </rdf:Description>
			   </dc:format>
			  </rdf:Description>
			 </rdf:RDF>
			</x:xmpmeta>
			XML;
		$xmp = XMP::parse($xml);

		// Structure written as namespaced attributes (the shorthand form).
		self::assertSame(['instanceID' => 'iid:99', 'documentID' => 'did:99'], $xmp->getProperty(XMP::NS_MM, 'DerivedFrom'));

		// A qualified value reads as its value, with the qualifiers beside it.
		self::assertSame('image/jpeg', $xmp->getProperty(XMP::NS_DC, 'format'));
		self::assertSame(['source' => 'camera'], $xmp->getQualifiers(XMP::NS_DC, 'format'));
		self::assertSame([], $xmp->getQualifiers(XMP::NS_MM, 'DerivedFrom'));
	}

	public function testEnumerationAndArrayHelpers()
	{
		$xmp = XMP::parse(self::REAL_WORLD);
		$properties = $xmp->getProperties();
		self::assertArrayHasKey('dc:title', $properties);
		self::assertArrayHasKey('xmpMM:History', $properties);
		self::assertArrayHasKey('tiff:Make', $properties);
		self::assertSame('PradoCam', $properties['tiff:Make']);
		self::assertCount(4, $xmp->getPropertyNames());

		$xmp->addArrayItem(XMP::NS_DC, 'subject', 'sunset');
		$xmp->addArrayItem(XMP::NS_DC, 'subject', 'norway');
		self::assertSame(['sunset', 'norway'], $xmp->getArrayItems(XMP::NS_DC, 'subject'));
		self::assertSame('Bag', $xmp->getArrayType(XMP::NS_DC, 'subject'));
		self::assertSame(['PradoCam'], $xmp->getArrayItems(XMP::NS_TIFF, 'Make'));   // simple value as a list
		self::assertSame([], $xmp->getArrayItems(XMP::NS_DC, 'nothing'));
		self::assertNull($xmp->getArrayType(XMP::NS_TIFF, 'Make'));
	}

	public function testDatesBooleansAndRemoval()
	{
		$xmp = XMP::blank();
		$when = new DateTimeImmutable('2026-07-28 12:34:56', new DateTimeZone('+02:00'));
		$xmp->setDateProperty(XMP::NS_XMP, 'CreateDate', $when);
		self::assertSame('2026-07-28T12:34:56+02:00', $xmp->getProperty(XMP::NS_XMP, 'CreateDate'));
		self::assertSame($when->getTimestamp(), $xmp->getDateProperty(XMP::NS_XMP, 'CreateDate')->getTimestamp());

		$xmp->setProperty(XMP::NS_RIGHTS, 'Marked', true);
		self::assertSame('True', $xmp->getProperty(XMP::NS_RIGHTS, 'Marked'));

		self::assertTrue($xmp->removeProperty(XMP::NS_RIGHTS, 'Marked'));
		self::assertFalse($xmp->removeProperty(XMP::NS_RIGHTS, 'Marked'));
		$xmp->setDateProperty(XMP::NS_XMP, 'CreateDate', null);
		self::assertNull($xmp->getDateProperty(XMP::NS_XMP, 'CreateDate'));
	}

	public function testNamespaceRegistrationAndSerializationOptions()
	{
		$xmp = XMP::blank();
		$xmp->registerNamespace('http://example.org/ns/1.0/', 'ex');
		$xmp->setProperty('http://example.org/ns/1.0/', 'Custom', 'value');
		self::assertStringContainsString('ex:Custom', $xmp->toXml());
		self::assertSame('http://example.org/ns/1.0/', $xmp->namespaceFor('ex'));
		self::assertSame('ex', $xmp->prefixFor('http://example.org/ns/1.0/'));
		self::assertSame('value', XMP::parse($xmp->toXml())->getByPath('ex:Custom'));

		$xmp->setPacketPadding(0);
		self::assertSame(0, $xmp->getPacketPadding());
		self::assertStringNotContainsString('   ', $xmp->toPacketText());

		$xmp->setIsWritable(false);
		self::assertStringContainsString('<?xpacket end="r"?>', $xmp->toPacketText());
		self::assertFalse($xmp->getIsWritable());
	}

	public function testSchemaFormsAreAppliedAutomatically()
	{
		$xmp = XMP::blank();
		// No $arrayType given: each property takes the form its schema defines.
		$xmp->setProperty(XMP::NS_DC, 'subject', ['a', 'b']);          // Bag
		$xmp->setProperty(XMP::NS_DC, 'creator', ['One', 'Two']);      // Seq
		$xmp->setProperty(XMP::NS_DC, 'title', 'A Title');             // LangAlt from a string
		$xmp->setProperty(XMP::NS_PHOTOSHOP, 'City', 'Bergen');        // simple

		$reparsed = XMP::parse($xmp->toXml());
		self::assertSame('Bag', $reparsed->getArrayType(XMP::NS_DC, 'subject'));
		self::assertSame('Seq', $reparsed->getArrayType(XMP::NS_DC, 'creator'));
		self::assertSame('Alt', $reparsed->getArrayType(XMP::NS_DC, 'title'));
		self::assertSame(['x-default' => 'A Title'], $reparsed->getLangAlt(XMP::NS_DC, 'title'));
		self::assertSame('Bergen', $reparsed->getProperty(XMP::NS_PHOTOSHOP, 'City'));

		// An explicit form still wins, and unknown schemas are written as asked.
		$xmp->setProperty(XMP::NS_DC, 'subject', ['x'], 'Seq');
		self::assertSame('Seq', XMP::parse($xmp->toXml())->getArrayType(XMP::NS_DC, 'subject'));

		self::assertSame(XMPSchemas::LangAlt, $xmp->schemaFormOf(XMP::NS_DC, 'rights'));
		self::assertSame(XMPSchemas::Struct, $xmp->schemaFormOf(XMP::NS_MM, 'DerivedFrom'));
		self::assertNull($xmp->schemaFormOf('http://example.org/', 'Whatever'));
		self::assertSame('Seq', XMPSchemas::arrayFormOf(XMP::NS_MM, 'History'));
		self::assertNotSame([], XMPSchemas::schema(XMP::NS_DC));
		self::assertSame([], XMPSchemas::schema('http://example.org/'));
	}

	public function testAddArrayItemUsesSchemaForm()
	{
		$xmp = XMP::blank();
		$xmp->addArrayItem(XMP::NS_DC, 'creator', 'First');
		$xmp->addArrayItem(XMP::NS_DC, 'creator', 'Second');
		self::assertSame('Seq', $xmp->getArrayType(XMP::NS_DC, 'creator'));
		self::assertSame(['First', 'Second'], $xmp->getArrayItems(XMP::NS_DC, 'creator'));
	}

	public function testValidateReportsSchemaMismatches()
	{
		$xmp = XMP::blank();
		$xmp->setTitle('Fine');
		$xmp->setKeywords(['ok']);
		$xmp->setProperty(XMP::NS_PHOTOSHOP, 'City', 'Bergen');
		self::assertSame([], $xmp->validate());

		// Force the wrong forms past the schema-aware writer.
		$wrong = XMP::blank();
		$wrong->setProperty(XMP::NS_DC, 'subject', ['a'], 'Seq');            // Bag expected
		$wrong->setProperty(XMP::NS_MM, 'DerivedFrom', 'not-a-structure');   // Struct expected
		$wrong->setProperty(XMP::NS_PHOTOSHOP, 'City', ['a', 'b'], 'Bag');   // simple expected

		$problems = $wrong->validate();
		self::assertArrayHasKey('dc:subject', $problems);
		self::assertStringContainsString('rdf:Bag', $problems['dc:subject']);
		self::assertArrayHasKey('xmpMM:DerivedFrom', $problems);
		self::assertSame('expected a structure', $problems['xmpMM:DerivedFrom']);
		self::assertArrayHasKey('photoshop:City', $problems);
		self::assertSame('expected a simple value', $problems['photoshop:City']);
	}

	public function testMerge()
	{
		$main = XMP::blank();
		$main->setTitle('Main title');
		$main->setProperty(XMP::NS_TIFF, 'Make', 'MainCam');

		$other = XMP::blank();
		$other->setProperty(XMP::NS_TIFF, 'Make', 'OtherCam');
		$other->setProperty(XMP::NS_TIFF, 'Model', 'M-1');

		$merged = XMP::parse($main->toXml());
		$merged->merge($other);
		self::assertSame('OtherCam', $merged->getProperty(XMP::NS_TIFF, 'Make'));
		self::assertSame('M-1', $merged->getProperty(XMP::NS_TIFF, 'Model'));
		self::assertSame('Main title', $merged->getTitle());

		$keep = XMP::parse($main->toXml());
		$keep->merge($other, false);
		self::assertSame('MainCam', $keep->getProperty(XMP::NS_TIFF, 'Make'));
		self::assertSame('M-1', $keep->getProperty(XMP::NS_TIFF, 'Model'));
	}

	public function testPrefixesOfUndeclaredAndCustomNamespaces()
	{
		$xml = <<<'XML'
			<x:xmpmeta xmlns:x="adobe:ns:meta/">
			 <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
			  <rdf:Description rdf:about="" xmlns:acme="http://acme.example/ns/1.0/">
			   <acme:Widget>gear</acme:Widget>
			  </rdf:Description>
			 </rdf:RDF>
			</x:xmpmeta>
			XML;
		$xmp = XMP::parse($xml);

		// The packet's own declaration supplies the prefix of a namespace no table names.
		self::assertSame('acme', $xmp->prefixFor('http://acme.example/ns/1.0/'));
		self::assertSame(['acme:Widget' => 'gear'], $xmp->getProperties());

		// A namespace neither registered, canonical, nor declared gets a generated prefix.
		self::assertSame('ns1', $xmp->prefixFor('http://nowhere.example/ns/'));
	}

	public function testDomWithoutAnRdfRootReadsAsEmpty()
	{
		$dom = new DOMDocument('1.0', 'UTF-8');
		$dom->appendChild($dom->createElement('notxmp'));
		$xmp = new XMP($dom);

		self::assertSame([], $xmp->getPropertyNames());
		self::assertSame([], $xmp->getProperties());
		self::assertSame([], $xmp->getNamespaces());
		self::assertFalse($xmp->containsProperty(XMP::NS_DC, 'title'));
		self::assertNull($xmp->getTitle());
	}

	public function testEmptyStructuresAttributeShorthandAndUnqualifiedValues()
	{
		$xml = <<<'XML'
			<x:xmpmeta xmlns:x="adobe:ns:meta/">
			 <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
			  <rdf:Description rdf:about=""
			    xmlns:dc="http://purl.org/dc/elements/1.1/"
			    xmlns:xmpMM="http://ns.adobe.com/xap/1.0/mm/"
			    xmlns:photoshop="http://ns.adobe.com/photoshop/1.0/"
			    photoshop:City="Oslo" dc:format="image/jpeg">
			   <dc:title>Plain title</dc:title>
			   <xmpMM:DerivedFrom rdf:parseType="Resource"/>
			   <xmpMM:ManagedFrom><rdf:Description/></xmpMM:ManagedFrom>
			  </rdf:Description>
			  <rdf:Description rdf:about=""
			    xmlns:photoshop="http://ns.adobe.com/photoshop/1.0/" photoshop:City="Oslo"/>
			 </rdf:RDF>
			</x:xmpmeta>
			XML;
		$xmp = XMP::parse($xml);

		// Both structure forms hold no field: an empty structure, not the empty string.
		self::assertSame([], $xmp->getProperty(XMP::NS_MM, 'DerivedFrom'));
		self::assertSame([], $xmp->getProperty(XMP::NS_MM, 'ManagedFrom'));

		// A language alternative stored as a plain element or as an attribute answers as
		// the default language.
		self::assertSame(['x-default' => 'Plain title'], $xmp->getLangAlt(XMP::NS_DC, 'title'));
		self::assertSame(['x-default' => 'Oslo'], $xmp->getLangAlt(XMP::NS_PHOTOSHOP, 'City'));
		self::assertSame([], $xmp->getLangAlt(XMP::NS_DC, 'rights'));

		// Nothing here is a qualified value.
		self::assertSame([], $xmp->getQualifiers(XMP::NS_DC, 'rights'));         // absent
		self::assertSame([], $xmp->getQualifiers(XMP::NS_PHOTOSHOP, 'City'));    // attribute form
		self::assertSame([], $xmp->getQualifiers(XMP::NS_MM, 'DerivedFrom'));

		// The attribute-shorthand properties are enumerated once each, beside the elements.
		$names = $xmp->getPropertyNames();
		self::assertContains([XMP::NS_PHOTOSHOP, 'City'], $names);
		self::assertContains([XMP::NS_DC, 'format'], $names);
		self::assertCount(5, $names);
		self::assertSame('Oslo', $xmp->getProperties()['photoshop:City']);
	}

	public function testDatePropertyRejectsUnparsableText()
	{
		$xmp = XMP::blank();
		$xmp->setProperty(XMP::NS_XMP, 'CreateDate', 'garbage');
		self::assertSame('garbage', $xmp->getProperty(XMP::NS_XMP, 'CreateDate'));
		self::assertNull($xmp->getDateProperty(XMP::NS_XMP, 'CreateDate'));
		self::assertNull($xmp->getDateProperty(XMP::NS_XMP, 'ModifyDate'));
	}

	public function testPathsThatCannotResolve()
	{
		$xmp = XMP::parse(self::REAL_WORLD);
		self::assertNull($xmp->getByPath('dc:title[bad]'));                     // not a path step
		self::assertNull($xmp->getByPath('xmpMM:History[1]/stEvt:missing'));    // no such field
		self::assertNull($xmp->getByPath('tiff:Make/stEvt:action'));            // not a structure
	}

	public function testWritingAltCollectionsAndUnknownFieldPrefixes()
	{
		$xmp = XMP::blank();
		// An explicit Alt of plain strings marks its first item as the default language.
		$xmp->setProperty(XMP::NS_XMP, 'Thumbnails', ['first', 'second'], 'Alt');
		$reparsed = XMP::parse($xmp->toXml());
		self::assertSame('Alt', $reparsed->getArrayType(XMP::NS_XMP, 'Thumbnails'));
		self::assertSame(['x-default' => 'first', 1 => 'second'], $reparsed->getLangAlt(XMP::NS_XMP, 'Thumbnails'));

		// A field prefix that resolves to no namespace stays in the structure's own.
		$xmp->setProperty(XMP::NS_MM, 'DerivedFrom', ['zz:instanceID' => 'xmp.iid:7']);
		self::assertStringContainsString('xmpMM:instanceID', $xmp->toXml());
		self::assertSame(['instanceID' => 'xmp.iid:7'], XMP::parse($xmp->toXml())->getProperty(XMP::NS_MM, 'DerivedFrom'));
	}

	public function testLanguageAlternativesFromListValues()
	{
		$xmp = XMP::blank();
		// A list given to a language-alternative setter keeps its first item as x-default.
		$xmp->setTitle(['Primary', 'Ignored']);
		self::assertSame(['x-default' => 'Primary'], $xmp->getLangAlt(XMP::NS_DC, 'title'));

		$xmp->setTitle([]);
		self::assertNull($xmp->getTitle());
		self::assertFalse($xmp->containsProperty(XMP::NS_DC, 'title'));
	}

	public function testValidateReportsMissingArraysAndEmptyLanguageAlternatives()
	{
		$xml = <<<'XML'
			<x:xmpmeta xmlns:x="adobe:ns:meta/">
			 <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
			  <rdf:Description rdf:about=""
			    xmlns:dc="http://purl.org/dc/elements/1.1/"
			    xmlns:acme="http://acme.example/ns/1.0/">
			   <dc:subject>plain</dc:subject>
			   <dc:title><rdf:Alt></rdf:Alt></dc:title>
			   <acme:Widget>gear</acme:Widget>
			  </rdf:Description>
			 </rdf:RDF>
			</x:xmpmeta>
			XML;
		$problems = XMP::parse($xml)->validate();

		self::assertSame('expected an rdf:Bag array', $problems['dc:subject']);
		self::assertSame('expected language alternatives', $problems['dc:title']);
		// A property no schema names is never complained about.
		self::assertArrayNotHasKey('acme:Widget', $problems);
		self::assertCount(2, $problems);
	}

	public function testMergeCopiesAttributeFormAndSkipsUnresolvedNames()
	{
		$xml = <<<'XML'
			<x:xmpmeta xmlns:x="adobe:ns:meta/">
			 <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
			  <rdf:Description rdf:about=""
			    xmlns:photoshop="http://ns.adobe.com/photoshop/1.0/" photoshop:City="Oslo"/>
			 </rdf:RDF>
			</x:xmpmeta>
			XML;

		// The attribute shorthand merges as an attribute of the target's description.
		$target = XMP::blank();
		$target->merge(XMP::parse($xml));
		self::assertSame('Oslo', $target->getProperty(XMP::NS_PHOTOSHOP, 'City'));
		self::assertSame('Oslo', XMP::parse($target->toXml())->getProperty(XMP::NS_PHOTOSHOP, 'City'));

		// A name with no node behind it is skipped, not merged as an empty property.
		$phantom = TXMPPhantomNameStub::parse($xml);
		$other = XMP::blank();
		$other->merge($phantom);
		self::assertSame('Oslo', $other->getProperty(XMP::NS_PHOTOSHOP, 'City'));
		self::assertFalse($other->containsProperty(XMP::NS_DC, 'nosuchproperty'));
		self::assertCount(1, $other->getPropertyNames());
	}

	public function testJpegExtendedXmpRoundTrip()
	{
		// A packet well past the 64 KB segment limit must split and rejoin.
		$xmp = XMP::blank();
		$xmp->setTitle('Large packet');
		$xmp->setKeywords(array_map(fn ($i) => "keyword-$i-" . str_repeat('x', 200), range(1, 500)));
		$packet = $xmp->toPacketText();
		self::assertGreaterThan(100000, strlen($packet));

		$jpeg = JPEGImage::fromString($this->jpegBytes());
		$jpeg->setXMP($xmp);
		$bytes = $jpeg->toBinary();

		// The main packet carries the digest and the extension segments the rest.
		self::assertStringContainsString(JPEGImage::XMP_EXTENSION_IDENTIFIER, $bytes);
		self::assertStringContainsString('HasExtendedXMP', $bytes);

		$reparsed = JPEGImage::fromString($bytes);
		$readBack = $reparsed->getXMP();
		self::assertNotNull($readBack);
		self::assertSame('Large packet', $readBack->getTitle());
		self::assertCount(500, $readBack->getKeywords());
		self::assertSame($xmp->getKeywords(), $readBack->getKeywords());
		// The digest property is not left behind after the merge.
		self::assertNull($readBack->getProperty(XMP::NS_NOTE, 'HasExtendedXMP'));
		self::assertSame(12, $reparsed->getWidth());
	}

	public function testJpegSmallXmpStaysSingleSegment()
	{
		$jpeg = JPEGImage::fromString($this->jpegBytes());
		$xmp = XMP::blank();
		$xmp->setTitle('Small');
		$jpeg->setXMP($xmp);
		$bytes = $jpeg->toBinary();

		self::assertStringNotContainsString(JPEGImage::XMP_EXTENSION_IDENTIFIER, $bytes);
		self::assertSame('Small', JPEGImage::fromString($bytes)->getXMP()->getTitle());
	}

	public function testPngXmpChunk()
	{
		$im = imagecreatetruecolor(8, 6);
		ob_start();
		imagepng($im);
		imagedestroy($im);
		$png = PNGImage::fromString(ob_get_clean());
		self::assertNull($png->getXmpText());

		$xmp = XMP::blank();
		$xmp->setTitle('PNG metadata');
		$xmp->setKeywords(['png', 'xmp']);
		$png->setXMP($xmp);

		$reparsed = PNGImage::fromString($png->toBinary());
		self::assertNotNull($reparsed->getXMP());
		self::assertSame('PNG metadata', $reparsed->getXMP()->getTitle());
		self::assertSame(['png', 'xmp'], $reparsed->getXMP()->getKeywords());
		self::assertSame(8, $reparsed->getWidth());

		// The chunk is a proper iTXt keyed for XMP, placed before the image data.
		$types = array_map(fn ($c) => $c->getType(), $reparsed->getChunks());
		self::assertContains('iTXt', $types);
		self::assertLessThan(array_search('IDAT', $types, true), array_search('iTXt', $types, true));

		$reparsed->setXmpText(null);
		self::assertNull(PNGImage::fromString($reparsed->toBinary())->getXmpText());
	}

	public function testWebpXmpChunkAddsExtendedHeader()
	{
		if (!function_exists('imagewebp')) {
			self::markTestSkipped('GD lacks WebP support.');
		}
		$im = imagecreatetruecolor(16, 10);
		ob_start();
		imagewebp($im);
		imagedestroy($im);
		$webp = WebPImage::fromString(ob_get_clean());
		self::assertNull($webp->getXmpText());
		self::assertNull($webp->getRIFF()->getChunk(RIFFChunkType::Vp8Extended));

		$xmp = XMP::blank();
		$xmp->setTitle('WebP metadata');
		$webp->setXMP($xmp);

		$reparsed = WebPImage::fromString($webp->toBinary());
		self::assertSame('WebP metadata', $reparsed->getXMP()->getTitle());
		self::assertSame(16, $reparsed->getWidth());
		self::assertSame(10, $reparsed->getHeight());

		// A simple file gained the extended header with the XMP flag set and leading.
		$vp8x = $reparsed->getRIFF()->getChunk(RIFFChunkType::Vp8Extended);
		self::assertNotNull($vp8x);
		self::assertSame(WebPImage::Vp8xXmpFlag, ord($vp8x->getData()[0]) & WebPImage::Vp8xXmpFlag);
		self::assertSame('VP8X', $reparsed->getRIFF()->getChunks()[0]->getType());

		$reparsed->setXmpText(null);
		$stripped = WebPImage::fromString($reparsed->toBinary());
		self::assertNull($stripped->getXmpText());
		self::assertSame(0, ord($stripped->getRIFF()->getChunk(RIFFChunkType::Vp8Extended)->getData()[0]) & WebPImage::Vp8xXmpFlag);
	}

	public function testTiffXmpThroughExif()
	{
		$exif = new Belisoful\Image\Meta\EXIF();
		$xmp = XMP::blank();
		$xmp->setTitle('TIFF tag 700');
		$exif->setXMP($xmp);

		$reparsed = Belisoful\Image\Meta\EXIF::fromSegment($exif->toBinary());
		self::assertSame('TIFF tag 700', $reparsed->getXMP()->getTitle());
	}

	public function testDefaultNamespaceDeclarationIsKeyedByTheEmptyPrefix()
	{
		$xml = <<<'XML'
			<x:xmpmeta xmlns:x="adobe:ns:meta/">
			 <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
			  <rdf:Description rdf:about=""
			    xmlns="http://acme.example/default/"
			    xmlns:dc="http://purl.org/dc/elements/1.1/">
			   <dc:format>image/jpeg</dc:format>
			  </rdf:Description>
			 </rdf:RDF>
			</x:xmpmeta>
			XML;
		$namespaces = XMP::parse($xml)->getNamespaces();

		// A default declaration (xmlns="…") has no prefix, so it is keyed by the empty string.
		self::assertArrayHasKey('', $namespaces);
		self::assertSame('http://acme.example/default/', $namespaces['']);
		self::assertSame(XMP::NS_DC, $namespaces['dc']);
		// The rdf, x, and xml declarations are structural, not schema namespaces.
		self::assertArrayNotHasKey('rdf', $namespaces);
		self::assertArrayNotHasKey('x', $namespaces);
		self::assertArrayNotHasKey('xml', $namespaces);
	}

	public function testLanguageAlternativeWhoseFirstItemHasNoLanguage()
	{
		$xml = <<<'XML'
			<x:xmpmeta xmlns:x="adobe:ns:meta/">
			 <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
			  <rdf:Description rdf:about="" xmlns:dc="http://purl.org/dc/elements/1.1/">
			   <dc:title>
			    <rdf:Alt>
			     <rdf:li>Untagged</rdf:li>
			     <rdf:li>Second</rdf:li>
			     <rdf:li xml:lang="de">Zweite</rdf:li>
			    </rdf:Alt>
			   </dc:title>
			  </rdf:Description>
			 </rdf:RDF>
			</x:xmpmeta>
			XML;
		$xmp = XMP::parse($xml);

		// An unlabelled first item stands in for x-default; later unlabelled items are
		// keyed by their position instead.
		self::assertSame(['x-default' => 'Untagged', 1 => 'Second', 'de' => 'Zweite'], $xmp->getLangAlt(XMP::NS_DC, 'title'));
		self::assertSame('Untagged', $xmp->getTitle());
	}

	public function testAPathWithoutAPrefixResolvesToNothing()
	{
		$xmp = XMP::parse(self::REAL_WORLD);
		// The first step names the schema; a bare name has no namespace to look in.
		self::assertNull($xmp->getByPath('Make'));
		self::assertNull($xmp->getByPath('title[1]'));
		self::assertSame('PradoCam', $xmp->getByPath('tiff:Make'));
	}

	public function testSetPropertyTakesALanguageMapForALanguageAlternative()
	{
		$xmp = XMP::blank();
		// dc:title is a language alternative in its schema, so a map is written as one
		// even though no $arrayType says so.
		$xmp->setProperty(XMP::NS_DC, 'title', ['x-default' => 'Sunset', 'de' => 'Sonnenuntergang']);

		$reparsed = XMP::parse($xmp->toXml());
		self::assertSame('Alt', $reparsed->getArrayType(XMP::NS_DC, 'title'));
		self::assertSame(['x-default' => 'Sunset', 'de' => 'Sonnenuntergang'], $reparsed->getLangAlt(XMP::NS_DC, 'title'));
		self::assertSame('Sonnenuntergang', $reparsed->getTitle('de'));
	}

	public function testBooleanValuesAreWrittenAsTrueAndFalse()
	{
		$xmp = XMP::blank();
		$xmp->setProperty(XMP::NS_RIGHTS, 'Marked', false);
		self::assertSame('False', $xmp->getProperty(XMP::NS_RIGHTS, 'Marked'));
		self::assertSame('False', XMP::parse($xmp->toXml())->getProperty(XMP::NS_RIGHTS, 'Marked'));

		$xmp->setProperty(XMP::NS_RIGHTS, 'Marked', true);
		self::assertSame('True', XMP::parse($xmp->toXml())->getProperty(XMP::NS_RIGHTS, 'Marked'));

		// A boolean inside a structure takes the same form.
		$xmp->setProperty(XMP::NS_MM, 'DerivedFrom', ['stRef:instanceID' => 'iid:1', 'stRef:renditionClass' => false]);
		self::assertSame(
			['instanceID' => 'iid:1', 'renditionClass' => 'False'],
			XMP::parse($xmp->toXml())->getProperty(XMP::NS_MM, 'DerivedFrom'),
		);
	}

	public function testAnUnknownCollectionFormFallsBackToBag()
	{
		$xmp = XMP::blank();
		// 'Ordered' is not an rdf collection: the value is written as a Bag, not as the
		// dc:creator schema's Seq (an explicit form keeps the schema out of it).
		$xmp->setProperty(XMP::NS_DC, 'creator', ['One', 'Two'], 'Ordered');

		$reparsed = XMP::parse($xmp->toXml());
		self::assertSame('Bag', $reparsed->getArrayType(XMP::NS_DC, 'creator'));
		self::assertSame(['One', 'Two'], $reparsed->getArrayItems(XMP::NS_DC, 'creator'));
		self::assertStringContainsString('<rdf:Bag>', $xmp->toXml());
		self::assertStringNotContainsString('rdf:Ordered', $xmp->toXml());
	}

	public function testCreatorsAndKeywordsHeldAsSimpleValues()
	{
		$xml = <<<'XML'
			<x:xmpmeta xmlns:x="adobe:ns:meta/">
			 <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
			  <rdf:Description rdf:about=""
			    xmlns:dc="http://purl.org/dc/elements/1.1/"
			    dc:creator="Solo Author" dc:subject="lone-keyword"/>
			 </rdf:RDF>
			</x:xmpmeta>
			XML;
		$xmp = XMP::parse($xml);

		// A packet that stores them as plain values still answers with lists.
		self::assertSame(['Solo Author'], $xmp->getCreators());
		self::assertSame(['lone-keyword'], $xmp->getKeywords());

		// An absent property is the empty list, not a list holding nothing.
		$empty = XMP::blank();
		self::assertSame([], $empty->getCreators());
		self::assertSame([], $empty->getKeywords());
	}

	public function testValidateAcceptsAStructureAndRejectsAListInItsPlace()
	{
		// xmpMM:DerivedFrom really is a structure here, so nothing is reported for it.
		self::assertArrayNotHasKey('xmpMM:DerivedFrom', XMP::parse(self::REAL_WORLD)->validate());

		// An array that is a list is as wrong as a plain string would be.
		$listed = XMP::blank();
		$listed->setProperty(XMP::NS_MM, 'DerivedFrom', ['one', 'two'], 'Bag');
		self::assertSame(['one', 'two'], $listed->getProperty(XMP::NS_MM, 'DerivedFrom'));
		self::assertSame('expected a structure', $listed->validate()['xmpMM:DerivedFrom']);
	}
}

/**
 * A packet that reports one property name more than it holds, so
 * {@see XMP::merge()} meets a name it cannot resolve to a node.
 */
class TXMPPhantomNameStub extends XMP
{
	/**
	 * @return array<int, array{0: string, 1: string}> The real names plus one phantom.
	 */
	public function getPropertyNames(): array
	{
		return array_merge(parent::getPropertyNames(), [[XMP::NS_DC, 'nosuchproperty']]);
	}
}
