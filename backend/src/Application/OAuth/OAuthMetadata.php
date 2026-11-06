<?php

namespace App\Application\OAuth;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class OAuthMetadata
{
    public function __construct(#[Autowire('%env(APP_URL)%')] private string $origin)
    {
    }

    /** @return non-empty-string */
    public function issuer(): string
    {
        return rtrim($this->origin, '/') ?: throw new \LogicException('APP_URL must be configured.');
    }

    /** @return non-empty-string */
    public function resource(): string
    {
        return $this->issuer() . '/api/v1/mcp';
    }

    /** @return array<string, mixed> */
    public function authorizationServer(): array
    {
        return [
            'issuer'                                         => $this->issuer(),
            'authorization_endpoint'                         => $this->issuer() . '/oauth/authorize',
            'token_endpoint'                                 => $this->issuer() . '/api/v1/oauth/token',
            'registration_endpoint'                          => $this->issuer() . '/api/v1/oauth/register',
            'revocation_endpoint'                            => $this->issuer() . '/api/v1/oauth/revoke',
            'response_types_supported'                       => ['code'],
            'grant_types_supported'                          => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported'               => ['S256'],
            'token_endpoint_auth_methods_supported'          => ['none', 'client_secret_post', 'client_secret_basic'],
            'scopes_supported'                               => ['mcp'],
            'client_id_metadata_document_supported'          => true,
            'authorization_response_iss_parameter_supported' => true,
        ];
    }

    /** @return array<string, mixed> */
    public function protectedResource(): array
    {
        return [
            'resource'                 => $this->resource(),
            'authorization_servers'    => [$this->issuer()],
            'scopes_supported'         => ['mcp'],
            'bearer_methods_supported' => ['header'],
        ];
    }

    public function challenge(): string
    {
        return 'Bearer resource_metadata="' . $this->issuer()
            . '/.well-known/oauth-protected-resource/api/v1/mcp", scope="mcp"';
    }
}
