<?php

namespace App\Application\OAuth;

use App\Entity\OAuthClient;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\AbstractClient;
use League\Bundle\OAuth2ServerBundle\Model\ClientInterface;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use League\OAuth2\Server\Exception\OAuthServerException;
use Nyholm\Psr7\ServerRequest;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\PasswordHasher\Hasher\NativePasswordHasher;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Register DCR clients and resolve CIMD documents; client/token persistence remains with the League bundle. */
final readonly class OAuthClientRegistration
{
    public function __construct(private ClientManagerInterface $clients, #[Autowire(service: 'http_client')] private HttpClientInterface $http)
    {
    }

    /** @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public function register(array $metadata): array
    {
        $method = $metadata['token_endpoint_auth_method'] ?? 'none';
        if (!in_array($method, ['none', 'client_secret_post', 'client_secret_basic'], true)) {
            throw OAuthServerException::invalidRequest('token_endpoint_auth_method');
        }
        $id = Uuid::v7()->toRfc4122();
        $secret = 'none' === $method ? null : bin2hex(random_bytes(32));
        $this->save($metadata, $id, $secret);

        return [
            'client_id' => $id,
            ...([] === array_filter([$secret]) ? [] : ['client_secret' => $secret, 'client_secret_expires_at' => 0]),
            'client_name'                => $metadata['client_name'] ?? 'MCP client',
            'redirect_uris'              => $metadata['redirect_uris'],
            'grant_types'                => ['authorization_code', 'refresh_token'],
            'response_types'             => ['code'],
            'token_endpoint_auth_method' => $method,
            'scope'                      => 'mcp',
        ];
    }

    public function resolve(string $id): ClientInterface
    {
        if (str_starts_with($id, 'https://')) {
            if (strlen($id) > 2048 || null !== parse_url($id, PHP_URL_FRAGMENT) || null !== parse_url($id, PHP_URL_USER)) {
                throw OAuthServerException::invalidRequest('client_id');
            }
            try {
                $http = new NoPrivateNetworkHttpClient($this->http);
                $response = $http->request('GET', $id, ['max_redirects' => 0, 'timeout' => 5, 'max_duration' => 5]);
                $chunks = [];
                $bytes = 0;
                foreach ($http->stream($response) as $chunk) {
                    $content = $chunk->getContent();
                    $bytes += strlen($content);
                    if ($bytes > 65536) {
                        $response->cancel();
                        throw new \UnexpectedValueException('Metadata too large');
                    }
                    $chunks[] = $content;
                }
                if (200 !== $response->getStatusCode()) {
                    throw new \UnexpectedValueException('Metadata unavailable');
                }
                $metadata = json_decode(implode('', $chunks), true, 16, JSON_THROW_ON_ERROR);
                if (!is_array($metadata) || ($metadata['client_id'] ?? null) !== $id || 'none' !== ($metadata['token_endpoint_auth_method'] ?? 'none')) {
                    throw new \UnexpectedValueException('Invalid public client metadata');
                }

                /* @var array<string, mixed> $metadata */
                return $this->save($metadata, $id, null);
            } catch (\Exception) {
                throw OAuthServerException::invalidRequest('client_id');
            }
        }

        return $this->clients->find($id) ?? throw OAuthServerException::invalidClient(new ServerRequest('GET', $id));
    }

    /** @param array<string, mixed> $metadata */
    private function save(array $metadata, string $id, ?string $secret): ClientInterface
    {
        $uris = $metadata['redirect_uris'] ?? null;
        $name = $metadata['client_name'] ?? 'MCP client';
        if (!is_string($name) || '' === trim($name) || mb_strlen($name) > 200 || !is_array($uris) || !array_is_list($uris) || [] === $uris || count($uris) > 10
            || !in_array($metadata['scope'] ?? 'mcp', ['', 'mcp'], true)
            || '' === $id
            || ['code'] !== ($metadata['response_types'] ?? ['code'])) {
            throw OAuthServerException::invalidRequest('client_metadata');
        }
        $grants = $metadata['grant_types'] ?? ['authorization_code', 'refresh_token'];
        if (!is_array($grants) || !array_is_list($grants) || !in_array('authorization_code', $grants, true)) {
            throw OAuthServerException::invalidRequest('grant_types');
        }
        foreach ($grants as $grant) {
            if (!in_array($grant, ['authorization_code', 'refresh_token'], true)) {
                throw OAuthServerException::invalidRequest('grant_types');
            }
        }
        $redirects = [];
        foreach ($uris as $uri) {
            if (!is_string($uri) || '' === $uri || strlen($uri) > 2048 || false === filter_var($uri, FILTER_VALIDATE_URL) || null !== parse_url($uri, PHP_URL_FRAGMENT)
                || null !== parse_url($uri, PHP_URL_USER) || !('https' === parse_url($uri, PHP_URL_SCHEME)
                    || ('http' === parse_url($uri, PHP_URL_SCHEME) && in_array(parse_url($uri, PHP_URL_HOST), ['localhost', '127.0.0.1', '[::1]'], true)))) {
                throw OAuthServerException::invalidRequest('redirect_uris');
            }
            $redirects[] = new RedirectUri($uri);
        }
        $client = $this->clients->find($id) ?? new OAuthClient($name, $id, null === $secret ? null : new NativePasswordHasher()->hash($secret));
        if (!$client instanceof AbstractClient) {
            throw OAuthServerException::invalidRequest('client_id');
        }
        $client->setName($name);
        $client->setRedirectUris(...$redirects);
        $client->setGrants(new Grant('authorization_code'), new Grant('refresh_token'));
        $client->setScopes(new Scope('mcp'));
        $this->clients->save($client);

        return $client;
    }
}
