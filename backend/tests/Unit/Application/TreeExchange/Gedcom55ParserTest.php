<?php

namespace App\Tests\Unit\Application\TreeExchange;

use App\Api\ApiException;
use App\Application\TreeExchange\Gedcom55Parser;
use PHPUnit\Framework\TestCase;

final class Gedcom55ParserTest extends TestCase
{
    public function testRecognizes55WithBomCrlfAndParsesContinuedEventsAndDates(): void
    {
        $document = (new Gedcom55Parser())->parse("\xEF\xBB\xBF" . str_replace("\n", "\r\n", <<<'GEDCOM'
0 HEAD
1 GEDC
2 VERS 5.5
1 CHAR UTF8
0 @I1@ INDI
1 NAME Ivan /Petrov/
2 GIVN Ivan
2 SURN Petrov
1 SEX M
1 BIRT
2 DATE ABT 12 FEB 1900
1 EVEN
2 TYPE Biography
2 DATE 12 FEB 1950
2 NOTE First
3 CONC  line
3 CONT Second line
0 TRLR

GEDCOM));

        self::assertSame('GEDCOM 5.5', $document->format);
        self::assertSame('Ivan', $document->people[0]['givenName']);
        self::assertTrue($document->people[0]['birthDate']->isApproximate());
        self::assertSame('EVEN', $document->people[0]['events'][0]['gedcomTag']);
        self::assertSame('Biography', $document->people[0]['events'][0]['title']);
        self::assertSame('First line' . "\n" . 'Second line', $document->people[0]['events'][0]['description']);
        self::assertSame(12, $document->people[0]['events'][0]['date']->getDay());
    }

    public function testUnsupportedValuesBecomeAggregatedWarnings(): void
    {
        $document = (new Gedcom55Parser())->parse(<<<'GEDCOM'
0 HEAD
1 GEDC
2 VERS 5.5.1
1 CHAR ASCII
0 @I1@ INDI
1 NAME A /B/
1 BIRT
2 DATE FROM 1900 TO 1910
2 PLAC Somewhere
1 SOUR @S1@
0 TRLR
GEDCOM);

        self::assertFalse($document->people[0]['birthDate']->isKnown());
        self::assertSame(1, $document->warnings['unsupported_date']);
        self::assertSame(1, $document->warnings['unsupported_place']);
        self::assertSame(1, $document->warnings['unsupported_source']);
    }

    public function testAllowsBurialAfterDeath(): void
    {
        $document = (new Gedcom55Parser())->parse(<<<'GEDCOM'
0 HEAD
1 GEDC
2 VERS 5.5.1
1 CHAR UTF-8
0 @I1@ INDI
1 NAME Anna /Petrova/
1 BIRT
2 DATE 1 JAN 1900
1 DEAT
2 DATE 22 NOV 1998
1 BURI
2 DATE 30 NOV 1998
0 TRLR
GEDCOM);

        self::assertSame('BURI', $document->people[0]['events'][0]['gedcomTag']);
        self::assertNull($document->people[0]['events'][0]['title']);
        self::assertSame(30, $document->people[0]['events'][0]['date']->getDay());
    }

    public function testKeepsDivorceWithUnknownDateWhenItIsAfterADeath(): void
    {
        $document = (new Gedcom55Parser())->parse(<<<'GEDCOM'
0 HEAD
1 GEDC
2 VERS 5.5.1
1 CHAR UTF-8
0 @I1@ INDI
1 NAME Ivan /Petrov/
1 SEX M
1 BIRT
2 DATE 1900
1 DEAT
2 DATE 1980
0 @I2@ INDI
1 NAME Anna /Petrova/
1 SEX F
1 BIRT
2 DATE 1905
0 @F1@ FAM
1 HUSB @I1@
1 WIFE @I2@
1 MARR
2 DATE 1930
1 DIV
2 DATE 1985
0 TRLR
GEDCOM);

        self::assertSame(1, $document->warnings['chronology_adjusted']);
        self::assertNotNull($document->families[0]['dissolutionDate']);
        self::assertFalse($document->families[0]['dissolutionDate']->isKnown());
    }

    public function testRejectsUnsupportedVersionEncodingAndBrokenReference(): void
    {
        foreach ([
            ["0 HEAD\n1 GEDC\n2 VERS 7.0\n1 CHAR UTF-8\n0 TRLR", 'TREE_IMPORT_FORMAT_UNSUPPORTED'],
            ["0 HEAD\n1 GEDC\n2 VERS 5.5.1\n1 CHAR ANSEL\n0 TRLR", 'TREE_IMPORT_ENCODING_UNSUPPORTED'],
            ["0 HEAD\n1 GEDC\n2 VERS 5.5.1\n1 CHAR UTF-8\n0 @I1@ INDI\n1 NAME A /B/\n0 @F1@ FAM\n1 HUSB @I2@\n1 CHIL @I1@\n0 TRLR", 'TREE_IMPORT_BROKEN_REFERENCE'],
        ] as [$contents, $code]) {
            try {
                (new Gedcom55Parser())->parse($contents);
                self::fail('Expected malformed GEDCOM to be rejected.');
            } catch (ApiException $exception) {
                self::assertSame($code, $exception->getErrorCode());
            }
        }
    }

    public function testRejectsDuplicateReferencesCyclesAndDisconnectedPeople(): void
    {
        foreach ([
            "0 HEAD\n1 GEDC\n2 VERS 5.5.1\n1 CHAR UTF-8\n0 @I1@ INDI\n1 NAME A /B/\n0 @I1@ INDI\n1 NAME C /D/\n0 TRLR"                                                                                               => 'TREE_IMPORT_DUPLICATE_REFERENCE',
            "0 HEAD\n1 GEDC\n2 VERS 5.5.1\n1 CHAR UTF-8\n0 @I1@ INDI\n1 NAME A /B/\n1 SEX M\n0 @I2@ INDI\n1 NAME C /D/\n1 SEX M\n0 @F1@ FAM\n1 HUSB @I1@\n1 CHIL @I2@\n0 @F2@ FAM\n1 HUSB @I2@\n1 CHIL @I1@\n0 TRLR" => 'TREE_IMPORT_ANCESTOR_CYCLE',
            "0 HEAD\n1 GEDC\n2 VERS 5.5.1\n1 CHAR UTF-8\n0 @I1@ INDI\n1 NAME A /B/\n0 @I2@ INDI\n1 NAME C /D/\n0 TRLR"                                                                                               => 'TREE_IMPORT_DISCONNECTED',
        ] as $contents => $code) {
            try {
                (new Gedcom55Parser())->parse($contents);
                self::fail('Expected invalid graph to be rejected.');
            } catch (ApiException $exception) {
                self::assertSame($code, $exception->getErrorCode());
            }
        }
    }
}
