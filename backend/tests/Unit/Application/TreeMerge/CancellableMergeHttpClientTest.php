<?php

namespace App\Tests\Unit\Application\TreeMerge;

use App\Api\ApiException;
use App\Application\TreeMerge\Ai\CancellableMergeHttpClient;
use App\Application\TreeMerge\MergeActivity;
use App\Entity\FamilyTree;
use App\Entity\TreeMergeDraft;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class CancellableMergeHttpClientTest extends TestCase
{
    public function testStopsInferenceDuringProgressAndDoesNotStartAnotherRequest(): void
    {
        $activity = new MergeActivity(new ArrayAdapter(), new LockFactory(new InMemoryStore()), \App\Tests\Infrastructure\Ai\TestMergeEvents::publisher());
        $now = new \DateTimeImmutable();
        $draft = new TreeMergeDraft(new FamilyTree(new User('user', 'user@example.test', 'hash', 'en', $now), 'tree', $now), null, 'etag', $now);
        $draft->addMessage('turn', 'Add Vera.', $now);
        $requests = new RequestStack();
        $request = new Request();
        $request->attributes->set(TreeMergeDraft::class, $draft);
        $requests->push($request);
        $progress = null;
        $calls = 0;
        $http = new MockHttpClient(function ($method, $url, $options) use (&$progress, &$calls): MockResponse {
            ++$calls;
            $progress = $options['on_progress'];

            return new MockResponse('OK');
        });
        $client = new CancellableMergeHttpClient($http, $activity, $requests);
        $observed = [];
        $client->request('POST', 'http://model.test', ['on_progress' => static function ($downloaded, $size, $info) use (&$observed): void {
            $observed = [$downloaded, $size, $info];
        }])->getContent();
        self::assertIsCallable($progress);
        $progress(12, 30, ['http_code' => 200]);
        self::assertSame([12, 30, ['http_code' => 200]], $observed);
        $activity->stop($draft, 'turn');
        foreach ([static fn () => $progress(15, 30, []), static fn () => $client->request('POST', 'http://model.test')] as $operation) {
            try {
                $operation();
                self::fail('Stopped inference must abort.');
            } catch (ApiException $error) {
                self::assertSame('TREE_MERGE_STOPPED', $error->getErrorCode());
            }
        }
        self::assertSame(1, $calls);
        $draft->addMessage('next', 'Continue with Vera.', $now);
        self::assertSame('OK', $client->request('POST', 'http://model.test')->getContent());
        $requests->pop();
        self::assertSame('OK', $client->request('GET', 'http://unrelated.test')->getContent());
        self::assertSame(3, $calls);
    }
}
