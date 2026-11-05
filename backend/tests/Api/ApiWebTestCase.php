<?php

namespace App\Tests\Api;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class ApiWebTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $connection = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $connection->executeStatement(
            'TRUNCATE refresh_token, auth_session, app_user CASCADE',
        );
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    protected function jsonRequest(
        string $method,
        string $path,
        array $body = [],
        array $headers = [],
    ): void {
        $server = ['HTTPS' => 'on'];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $this->client->jsonRequest(
            $method,
            'https://localhost' . $path,
            $body,
            $server,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function responseData(): array
    {
        $content = $this->client->getResponse()->getContent();
        self::assertIsString($content);

        $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    /** @return array<array-key, mixed> */
    protected function responseArray(string ...$path): array
    {
        $value = $this->responseValue(...$path);
        self::assertIsArray($value);

        return $value;
    }

    protected function responseInt(string ...$path): int
    {
        $value = $this->responseValue(...$path);
        self::assertIsInt($value);

        return $value;
    }

    protected function responseString(string ...$path): string
    {
        $value = $this->responseValue(...$path);
        self::assertIsString($value);

        return $value;
    }

    /**
     * @return list<string>
     */
    protected function responseStringList(string ...$path): array
    {
        $value = $this->responseValue(...$path);
        self::assertIsArray($value);
        self::assertTrue(array_is_list($value));
        foreach ($value as $item) {
            self::assertIsString($item);
        }

        return $value;
    }

    private function responseValue(string ...$path): mixed
    {
        $value = $this->responseData();
        foreach ($path as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    protected function validRegistration(array $overrides = []): array
    {
        return array_replace([
            'username' => 'family_admin',
            'email'    => 'family@example.test',
            'password' => 'StrongPass1!',
            'language' => 'en',
        ], $overrides);
    }
}
