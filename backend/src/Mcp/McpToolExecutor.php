<?php

namespace App\Mcp;

use App\Api\ApiException;
use App\Application\Tree\TreeActionService;
use App\Security\McpTokenHandler;
use App\Tool\TreeReadTools;
use App\Tool\TreeWriteTools;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Elicitation\BooleanSchemaDefinition;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Enum\ElicitAction;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Server\Builder;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Uid\Uuid;

/** Keep application errors machine-readable while sharing tools with Symfony AI. */
final readonly class McpToolExecutor implements ReferenceHandlerInterface
{
    private const int CONFIRMATION_VERSION = 2;

    public function __construct(
        #[AutowireLocator([TreeReadTools::class, TreeWriteTools::class])]
        private ContainerInterface $tools,
        private TreeActionService $actions,
        #[Autowire('%kernel.secret%')]
        private string $secret,
        #[Target('cache.mcp_identity')]
        private CacheItemPoolInterface $approvals,
        private LockFactory $locks,
        private RequestStack $requests,
        private McpTokenHandler $tokens,
    ) {
    }

    public function configure(Builder $builder): Builder
    {
        return $builder
            ->setReferenceHandler($this)
            ->setRequestState(hash('sha256', 'mcp-confirmations:' . $this->secret, true), 120);
    }

    public function handle(ElementReference $reference, array $arguments): mixed
    {
        try {
            if (is_array($reference->handler) && TreeWriteTools::class === $reference->handler[0]) {
                $name = TreeWriteTools::OPERATIONS[$reference->handler[1]];
                if (TreeActionService::needsConfirmation($name)) {
                    $pending = $this->confirm($name, $arguments);
                    if (null !== $pending) {
                        return $pending;
                    }
                }
            }
            $result = new ReferenceHandler($this->tools)->handle($reference, $arguments);
            if (is_array($result) && isset($result['error'])) {
                return new CallToolResult(
                    [new TextContent(json_encode($result, JSON_THROW_ON_ERROR))],
                    true,
                    $result,
                );
            }

            return $result;
        } catch (ApiException $error) {
            $data = [
                'code'    => $error->getErrorCode(),
                'status'  => $error->getStatus(),
                'fields'  => $error->getFields(),
                'details' => $error->getDetails(),
            ];

            return new CallToolResult([new TextContent(json_encode($data, JSON_THROW_ON_ERROR))], true, $data);
        }
    }

    /** @param array<string, mixed> $arguments */
    private function confirm(string $name, array $arguments): ?InputRequiredResult
    {
        $summary = $this->actions->describe($name, $arguments);
        if (!($arguments['_session'] ?? null) instanceof SessionInterface
            || !($arguments['_request'] ?? null) instanceof Request) {
            throw new ApiException(409, 'CONFIRMATION_UNSUPPORTED');
        }
        $context = new RequestContext($arguments['_session'], $arguments['_request']);
        $client = $context->getClientGateway();
        $capabilities = (array)$context->getSession()->get('client_capabilities', []);
        $modes = (array)($capabilities['elicitation'] ?? []);
        if (!$client->supportsElicitation() || (isset($modes['url']) && !isset($modes['form']))) {
            throw new ApiException(409, 'CONFIRMATION_UNSUPPORTED');
        }
        $message = 'Allow ' . $name . '? ' . json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $schema = new ElicitationSchema(
            ['approved' => new BooleanSchemaDefinition(title: 'Allow this action', default: false)],
            ['approved'],
        );
        if ($context->getProtocolVersion()->isModern()) {
            return $this->confirmRoundTrip($context, $name, $arguments, $message, $schema);
        }
        try {
            $answer = $client->elicit(
                $message,
                $schema,
            );
        } catch (\Throwable $error) {
            throw new ApiException(409, 'CONFIRMATION_CANCELLED', previous: $error);
        }
        if (ElicitAction::Accept !== $answer->action || true !== ($answer->content['approved'] ?? null)) {
            throw new ApiException(409, 'TOOL_DENIED');
        }
        // A legacy elicitation resumes in the original HTTP request; its bearer may have expired meanwhile.
        $authorization = $this->requests->getMainRequest()?->headers->get('Authorization', '') ?? '';
        try {
            $this->tokens->getUserBadgeFrom(substr($authorization, 7));
        } catch (\Throwable $error) {
            throw new ApiException(401, 'AUTH_REQUIRED', previous: $error);
        }
        $this->actions->describe($name, $arguments);

        return null;
    }

    /** @param array<string, mixed> $arguments */
    private function confirmRoundTrip(
        RequestContext $context,
        string $name,
        array $arguments,
        string $message,
        ElicitationSchema $schema,
    ): ?InputRequiredResult {
        $identity = $this->requests->getMainRequest()?->attributes->get('mcp_identity');
        $public = array_filter($arguments, static fn ($key) => !str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY);
        $digest = hash(
            'sha256',
            json_encode(
                [self::CONFIRMATION_VERSION, $name, $this->canonical($public)],
                JSON_THROW_ON_ERROR,
            )
        );
        $input = $context->getInputContext();
        if (null === $input) {
            $id = Uuid::v7()->toRfc4122();
            $item = $this->approvals->getItem('approval_' . $id);
            $this->approvals->save($item->set('pending')->expiresAfter(120));

            return new InputRequiredResult(
                ['approval' => ElicitRequest::forForm($message, $schema)],
                $context->mintRequestState(['id' => $id, 'digest' => $digest, 'identity' => $identity]),
            );
        }
        $state = $input->requestState();
        if (($state['digest'] ?? null) !== $digest || ($state['identity'] ?? null) !== $identity
            || !is_string($state['id'] ?? null) || !Uuid::isValid($state['id'])) {
            throw new ApiException(409, 'CONFIRMATION_INVALID');
        }
        $answer = $input->elicitResult('approval');
        $lock = $this->locks->createLock('mcp_approval_' . $state['id']);
        $lock->acquire(true);
        try {
            $item = $this->approvals->getItem('approval_' . $state['id']);
            if ('pending' !== $item->get()) {
                throw new ApiException(409, 'CONFIRMATION_USED');
            }
            $this->approvals->save($item->set('consumed')->expiresAfter(120));
        } finally {
            $lock->release();
        }
        if (null === $answer || ElicitAction::Accept !== $answer->action
            || true !== ($answer->content['approved'] ?? null)) {
            throw new ApiException(409, 'TOOL_DENIED');
        }

        return null;
    }

    /** @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private function canonical(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonical($item);
            }
        }

        return $value;
    }
}
