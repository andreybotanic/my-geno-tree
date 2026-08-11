<?php

namespace App\Tests\Api\Tree;

use App\Tests\Api\ApiWebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * @phpstan-type ExchangePlace array{name: string, coordinates: array{latitude: float, longitude: float}|null}
 * @phpstan-type ExchangePerson array{id: string, givenName: array{state: string, value?: string}, birthPlace: ExchangePlace|null}
 * @phpstan-type ExchangeMarriage array{marriagePlace: ExchangePlace|null, dissolutionPlace: ExchangePlace|null}
 * @phpstan-type ExchangeSnapshot array{people: list<ExchangePerson>, marriages: list<ExchangeMarriage>}
 * @phpstan-type ExchangeEvent array{type: string, date: array<string, mixed>, place: ExchangePlace|null}
 */
final class TreeExchangeApiTest extends ApiWebTestCase
{
    private const string GEDCOM = <<<'GEDCOM'
0 HEAD
1 GEDC
2 VERS 5.5.1
1 CHAR UTF-8
0 @I1@ INDI
1 NAME Иван /Петров/
2 GIVN Иван
2 SURN Петров
1 SEX M
1 BIRT
2 DATE 1900
1 OCCU Столяр
2 DATE 1930
2 NOTE Семейная мастерская
0 @I2@ INDI
1 NAME Анна /Сидорова/
1 SEX F
1 BIRT
2 DATE MAR 1905
0 @I3@ INDI
1 NAME Пётр /Петров/
1 SEX M
1 BIRT
2 DATE 12 APR 1932
1 FAMC @F1@
0 @F1@ FAM
1 HUSB @I1@
1 WIFE @I2@
1 CHIL @I3@
1 _MGT_UNMARRIED Y
0 TRLR
GEDCOM;

    public function testInspectImportAndRoundTripExport(): void
    {
        $token = $this->register(language: 'ru');
        $this->multipart('/api/v1/tree-imports/inspect', $token, self::GEDCOM);
        self::assertResponseIsSuccessful();
        self::assertSame('GEDCOM 5.5.1', $this->responseString('format'));
        self::assertSame(3, $this->responseData()['personCount']);
        self::assertSame(1, $this->responseData()['familyCount']);

        $this->multipart('/api/v1/tree-imports', $token, self::GEDCOM, 'Imported family');
        self::assertResponseStatusCodeSame(201);
        $import = $this->responseData();
        self::assertSame([], $import['warnings']);
        $tree = $import['tree'];
        self::assertIsArray($tree);
        self::assertSame(3, $tree['personCount']);
        self::assertSame('Imported family', $tree['name']);
        $treeId = $this->responseString('tree', 'id');

        $this->jsonRequest('GET', '/api/v1/trees/' . $treeId . '/snapshot', headers: [
            'Authorization' => 'Bearer ' . $token,
        ]);
        $snapshot = $this->responseData();
        self::assertIsArray($snapshot['people']);
        self::assertIsArray($snapshot['parentLinks']);
        self::assertIsArray($snapshot['marriages']);
        self::assertCount(3, $snapshot['people']);
        self::assertCount(2, $snapshot['parentLinks']);
        self::assertCount(0, $snapshot['marriages']);

        $this->jsonRequest('POST', '/api/v1/trees/' . $treeId . '/exports', [
            'format'   => 'gedcom-5.5.1',
            'fileName' => 'Imported family',
        ], ['Authorization' => 'Bearer ' . $token]);
        self::assertResponseIsSuccessful();
        $export = $this->client->getResponse()->getContent();
        self::assertIsString($export);
        self::assertStringContainsString("2 VERS 5.5.1\r\n", $export);
        self::assertStringContainsString("1 _MGT_UNMARRIED Y\r\n", $export);
        self::assertStringContainsString('2 TYPE MyGenoTree UUID', $export);
        self::assertStringContainsString('2 TYPE Работа', $export);

        $this->multipart('/api/v1/tree-imports', $token, $export, 'Round trip');
        self::assertResponseStatusCodeSame(201);
        $roundTrip = $this->responseData()['tree'];
        self::assertIsArray($roundTrip);
        self::assertSame(3, $roundTrip['personCount']);
        $roundTripId = $this->responseString('tree', 'id');
        $this->jsonRequest('GET', '/api/v1/trees/' . $roundTripId . '/snapshot', headers: [
            'Authorization' => 'Bearer ' . $token,
        ]);
        $roundTripSnapshot = $this->responseData();
        self::assertIsArray($roundTripSnapshot['parentLinks']);
        self::assertIsArray($roundTripSnapshot['marriages']);
        self::assertCount(2, $roundTripSnapshot['parentLinks']);
        self::assertCount(0, $roundTripSnapshot['marriages']);
    }

    public function testImportIsAtomicAndDuplicateNameIsRejected(): void
    {
        $token = $this->register();
        $this->multipart('/api/v1/tree-imports', $token, self::GEDCOM, 'Only once');
        self::assertResponseStatusCodeSame(201);
        $this->multipart('/api/v1/tree-imports', $token, self::GEDCOM, 'Only once');
        self::assertResponseStatusCodeSame(409);

        $invalid = str_replace('1 CHIL @I3@', '1 CHIL @MISSING@', self::GEDCOM);
        $this->multipart('/api/v1/tree-imports', $token, $invalid, 'Must not exist');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('TREE_IMPORT_BROKEN_REFERENCE', $this->responseString('code'));
        $this->jsonRequest('GET', '/api/v1/trees', headers: ['Authorization' => 'Bearer ' . $token]);
        $items = $this->responseData()['items'];
        self::assertIsArray($items);
        self::assertCount(1, $items);
    }

    public function testImportsAndExportsMarriageAndDivorcePeriods(): void
    {
        $token = $this->register();
        $marriedGedcom = str_replace(
            '1 _MGT_UNMARRIED Y',
            "1 MARR\n2 DATE 1929\n1 DIV\n2 DATE 1940",
            self::GEDCOM,
        );
        $this->multipart('/api/v1/tree-imports', $token, $marriedGedcom, 'Marriage periods');
        self::assertResponseStatusCodeSame(201);
        $treeId = $this->responseString('tree', 'id');

        $this->jsonRequest('GET', '/api/v1/trees/' . $treeId . '/snapshot', headers: [
            'Authorization' => 'Bearer ' . $token,
        ]);
        $marriages = $this->responseData()['marriages'];
        self::assertIsArray($marriages);
        self::assertCount(1, $marriages);

        $this->jsonRequest('POST', '/api/v1/trees/' . $treeId . '/exports', [
            'format'   => 'gedcom-5.5.1',
            'fileName' => 'Marriage periods',
        ], ['Authorization' => 'Bearer ' . $token]);
        $export = $this->client->getResponse()->getContent();
        self::assertIsString($export);
        self::assertStringContainsString("1 MARR\r\n2 DATE 1929\r\n", $export);
        self::assertStringContainsString("1 DIV\r\n2 DATE 1940\r\n", $export);
        self::assertStringNotContainsString('_MGT_UNMARRIED', $export);
    }

    public function testRoundTripsResolvedAndTextOnlyPlacesAndResidence(): void
    {
        $gedcom = <<<'GEDCOM'
0 HEAD
1 GEDC
2 VERS 5.5.1
1 CHAR UTF-8
0 @I1@ INDI
1 NAME Anna /Petrova/
1 SEX F
1 BIRT
2 DATE 1905
2 PLAC Novosibirsk
3 MAP
4 LATI N55.0302
4 LONG E82.9204
1 RESI
2 PLAC Tomsk
0 @I2@ INDI
1 NAME Ivan /Petrov/
1 SEX M
1 BIRT
2 DATE 1900
2 PLAC Old village
0 @F1@ FAM
1 HUSB @I2@
1 WIFE @I1@
1 MARR
2 DATE 1929
2 PLAC Moscow
3 MAP
4 LATI N55.7558
4 LONG E37.6173
1 DIV
2 DATE 1940
2 PLAC Unknown court
3 MAP
4 LATI N55.7
0 TRLR
GEDCOM;
        $token = $this->register('place_exchange', 'place_exchange@example.test');

        $this->multipart('/api/v1/tree-imports/inspect', $token, $gedcom);
        self::assertResponseIsSuccessful();
        self::assertSame([
            ['code' => 'place_coordinates_invalid', 'count' => 1],
        ], $this->responseData()['warnings']);

        $this->multipart('/api/v1/tree-imports', $token, $gedcom, 'Places');
        self::assertResponseStatusCodeSame(201);
        $treeId = $this->responseString('tree', 'id');
        $snapshot = $this->snapshot($token, $treeId);
        self::assertCount(2, $snapshot['people']);
        self::assertCount(1, $snapshot['marriages']);

        $anna = null;
        $ivan = null;
        foreach ($snapshot['people'] as $person) {
            if ('Anna' === ($person['givenName']['value'] ?? null)) {
                $anna = $person;
            }
            if ('Ivan' === ($person['givenName']['value'] ?? null)) {
                $ivan = $person;
            }
        }
        self::assertIsArray($anna);
        self::assertIsArray($ivan);
        self::assertIsArray($anna['birthPlace']);
        self::assertIsArray($anna['birthPlace']['coordinates']);
        self::assertIsArray($ivan['birthPlace']);
        self::assertSame('Novosibirsk', $anna['birthPlace']['name']);
        self::assertSame(55.0302, $anna['birthPlace']['coordinates']['latitude']);
        self::assertSame('Old village', $ivan['birthPlace']['name']);
        self::assertNull($ivan['birthPlace']['coordinates']);

        $marriage = $snapshot['marriages'][0];
        self::assertIsArray($marriage['marriagePlace']);
        self::assertIsArray($marriage['marriagePlace']['coordinates']);
        self::assertIsArray($marriage['dissolutionPlace']);
        self::assertSame('Moscow', $marriage['marriagePlace']['name']);
        self::assertSame(37.6173, $marriage['marriagePlace']['coordinates']['longitude']);
        self::assertSame('Unknown court', $marriage['dissolutionPlace']['name']);
        self::assertNull($marriage['dissolutionPlace']['coordinates']);

        $this->jsonRequest(
            'GET',
            sprintf('/api/v1/trees/%s/people/%s/events', $treeId, $anna['id']),
            headers: ['Authorization' => 'Bearer ' . $token],
        );
        self::assertResponseIsSuccessful();
        $residence = null;
        $eventResponse = $this->responseData();
        self::assertIsArray($eventResponse['items']);
        foreach ($eventResponse['items'] as $event) {
            if (is_array($event) && 'residence' === ($event['type'] ?? null)) {
                /** @var ExchangeEvent $event */
                $residence = $event;
            }
        }
        self::assertIsArray($residence);
        self::assertIsArray($residence['place']);
        self::assertSame(['state' => 'unknown'], $residence['date']);
        self::assertSame('Tomsk', $residence['place']['name']);
        self::assertNull($residence['place']['coordinates']);

        $this->jsonRequest('POST', '/api/v1/trees/' . $treeId . '/exports', [
            'format'   => 'gedcom-5.5.1',
            'fileName' => 'Places',
        ], ['Authorization' => 'Bearer ' . $token]);
        self::assertResponseIsSuccessful();
        $export = $this->client->getResponse()->getContent();
        self::assertIsString($export);
        self::assertStringContainsString("2 PLAC Novosibirsk\r\n3 MAP\r\n4 LATI N55.0302\r\n4 LONG E82.9204\r\n", $export);
        self::assertStringContainsString("1 RESI\r\n2 PLAC Tomsk\r\n", $export);
        self::assertStringContainsString("2 PLAC Old village\r\n", $export);
        self::assertStringNotContainsString("2 PLAC Old village\r\n3 MAP", $export);
        self::assertStringContainsString("2 PLAC Unknown court\r\n", $export);
        self::assertStringNotContainsString("2 PLAC Unknown court\r\n3 MAP", $export);

        $this->multipart('/api/v1/tree-imports', $token, $export, 'Places round trip');
        self::assertResponseStatusCodeSame(201);
        $roundTrip = $this->snapshot($token, $this->responseString('tree', 'id'));
        self::assertCount(2, $roundTrip['people']);
        self::assertCount(1, $roundTrip['marriages']);
        self::assertIsArray($roundTrip['marriages'][0]['marriagePlace']);
        self::assertIsArray($roundTrip['marriages'][0]['dissolutionPlace']);
        self::assertSame('Moscow', $roundTrip['marriages'][0]['marriagePlace']['name']);
        self::assertNull($roundTrip['marriages'][0]['dissolutionPlace']['coordinates']);
    }

    public function testImportRequiresAuthenticationAndExportHidesForeignTree(): void
    {
        $this->multipart('/api/v1/tree-imports/inspect', '', self::GEDCOM);
        self::assertResponseStatusCodeSame(401);

        $firstToken = $this->register();
        $this->multipart('/api/v1/tree-imports', $firstToken, self::GEDCOM, 'Private import');
        $treeId = $this->responseString('tree', 'id');
        $secondToken = $this->register('second_exchange', 'second_exchange@example.test');
        $this->jsonRequest('POST', '/api/v1/trees/' . $treeId . '/exports', [
            'format'   => 'gedcom-5.5.1',
            'fileName' => 'stolen',
        ], ['Authorization' => 'Bearer ' . $secondToken]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testInspectReportsUnsupportedDisconnectedAndOversizedFiles(): void
    {
        $token = $this->register();
        $unsupported = str_replace('2 VERS 5.5.1', '2 VERS 7.0', self::GEDCOM);
        $this->multipart('/api/v1/tree-imports/inspect', $token, $unsupported);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('TREE_IMPORT_FORMAT_UNSUPPORTED', $this->responseString('code'));

        $disconnected = str_replace("0 @F1@ FAM\n1 HUSB @I1@\n1 WIFE @I2@\n1 CHIL @I3@\n1 _MGT_UNMARRIED Y\n", '', self::GEDCOM);
        $this->multipart('/api/v1/tree-imports/inspect', $token, $disconnected);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('TREE_IMPORT_DISCONNECTED', $this->responseString('code'));

        $this->multipart('/api/v1/tree-imports/inspect', $token, str_repeat('x', 10 * 1024 * 1024 + 1));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('TREE_IMPORT_FILE_TOO_LARGE', $this->responseString('code'));
    }

    private function register(
        string $username = 'exchange_owner',
        string $email = 'exchange@example.test',
        string $language = 'en',
    ): string {
        $this->jsonRequest('POST', '/api/v1/auth/register', $this->validRegistration([
            'username' => $username,
            'email'    => $email,
            'language' => $language,
        ]));
        self::assertResponseStatusCodeSame(201);

        return $this->responseString('accessToken');
    }

    /** @return ExchangeSnapshot */
    private function snapshot(string $token, string $treeId): array
    {
        $this->jsonRequest(
            'GET',
            '/api/v1/trees/' . $treeId . '/snapshot',
            headers: ['Authorization' => 'Bearer ' . $token],
        );
        self::assertResponseIsSuccessful();

        /** @var ExchangeSnapshot $snapshot */
        $snapshot = $this->responseData();

        return $snapshot;
    }

    private function multipart(string $path, string $token, string $contents, ?string $name = null): void
    {
        $pathName = tempnam(sys_get_temp_dir(), 'gedcom-');
        self::assertIsString($pathName);
        file_put_contents($pathName, $contents);
        $parameters = null === $name ? [] : ['name' => $name];
        $server = ['HTTPS' => 'on'];
        if ('' !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        $this->client->request(
            'POST',
            'https://localhost' . $path,
            $parameters,
            ['file' => new UploadedFile($pathName, 'tree.ged', 'application/x-gedcom', null, true)],
            $server,
        );
    }
}
