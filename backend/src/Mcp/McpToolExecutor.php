<?php

namespace App\Mcp;

use App\Api\ApiException;
use App\Tool\TreeMergeTool;
use App\Tool\TreeReadTools;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Builder;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/** Keep application errors machine-readable while sharing tools with Symfony AI. */
final readonly class McpToolExecutor implements ReferenceHandlerInterface
{
    public function __construct(
        #[AutowireLocator([TreeReadTools::class, TreeMergeTool::class])]
        private ContainerInterface $tools,
    ) {
    }

    public function configure(Builder $builder): Builder
    {
        return $builder->setReferenceHandler($this);
    }

    public function handle(ElementReference $reference, array $arguments): mixed
    {
        try {
            return new ReferenceHandler($this->tools)->handle($reference, $arguments);
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
}
