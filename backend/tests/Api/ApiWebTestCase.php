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
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $this->client->jsonRequest(
            $method,
            'https://localhost'.$path,
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

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    protected function validRegistration(array $overrides = []): array
    {
        return array_replace([
            'username' => 'family_admin',
            'email' => 'family@example.test',
            'password' => 'StrongPass1!',
            'language' => 'en',
        ], $overrides);
    }
}
