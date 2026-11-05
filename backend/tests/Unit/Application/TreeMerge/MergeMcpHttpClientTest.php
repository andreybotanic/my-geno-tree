<?php

namespace App\Tests\Unit\Application\TreeMerge;

use App\Application\TreeMerge\Ai\MergeMcpHttpClient;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpFoundation\RequestStack;

final class MergeMcpHttpClientTest extends TestCase
{
    public function testNoCredentialsLeaveTheAttemptOrConfiguredEndpoint(): void
    {
        $requests = new RequestStack();
        $http = new MockHttpClient(static fn () => throw new \LogicException('Unexpected network request'));
        $client = new MergeMcpHttpClient(new Psr18Client($http), $requests, 'http://mcp.test/agent');
        $request = new HttpRequest();
        foreach ([null, '', 'scoped-token'] as $token) {
            $request->attributes->set(MergeMcpHttpClient::class, $token);
            $requests->push($request);
            try {
                $client->sendRequest(new Request('POST', null === $token || '' === $token ? 'http://mcp.test/agent' : 'http://other.test/agent'));
                self::fail('Missing or misdirected credential accepted');
            } catch (\LogicException $error) {
                self::assertSame('MERGE_MCP_SCOPE_REQUIRED', $error->getMessage());
            } finally {
                $requests->pop();
            }
        }
        self::assertSame(0, $http->getRequestsCount());
    }
}
