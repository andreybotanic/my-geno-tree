<?php

namespace App\EventListener;

use App\Api\ApiException;
use App\Api\TreeMergeDraftViewFactory;
use App\Application\TreeMerge\TreeMergeChat;
use App\Application\TreeMerge\TreeMergeDraftService;
use App\Application\TreeMerge\TreeMergeEvents;
use App\Entity\AuthSession;
use App\Entity\TreeMergeDraft;
use App\Mcp\TreeMergeAgentTools;
use App\Security\TreeMergeAgentContext;
use Symfony\AI\Agent\Attribute\AsInputProcessor;
use Symfony\AI\Agent\Attribute\AsOutputProcessor;
use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\InputProcessorInterface;
use Symfony\AI\Agent\Output;
use Symfony\AI\Agent\OutputProcessorInterface;
use Symfony\AI\Agent\Toolbox\Event\ToolCallRequested;
use Symfony\AI\Agent\Toolbox\Event\ToolCallSucceeded;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Event\InvocationEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;

#[AsOutputProcessor(agent: 'ai.agent.tree_merge')]
#[AsInputProcessor(agent: 'ai.agent.tree_merge', priority: 1000)]
final readonly class TreeMergeToolListener implements InputProcessorInterface, OutputProcessorInterface
{
    public function __construct(
        private TreeMergeDraftService $drafts,
        private RequestStack $requests,
        private TreeMergeEvents $events,
        private TreeMergeDraftViewFactory $views,
    ) {
    }

    public function processInput(Input $input): void
    {
        $draft = $this->requests->getCurrentRequest()?->attributes->get(TreeMergeDraft::class);
        if ($draft instanceof TreeMergeDraft) {
            // Bind before prompt processors clone the MessageBag; native tool-round saves share this state.
            $input->getMessageBag()->getMetadata()->add(TreeMergeChat::REVIEW_METADATA, $draft->review);
        }
    }

    #[AsEventListener(priority: 10)]
    public function beforeModel(InvocationEvent $event): void
    {
        $context = $this->requests->getCurrentRequest()?->attributes->get(TreeMergeAgentContext::class);
        if ($context instanceof TreeMergeAgentContext) {
            $this->drafts->active($context->treeId);
        }
    }

    public function processOutput(Output $output): void
    {
        $context = $this->requests->getCurrentRequest()?->attributes->get(TreeMergeAgentContext::class);
        if ($context instanceof TreeMergeAgentContext) {
            $this->drafts->active($context->treeId);
        }
    }

    #[AsEventListener]
    public function beforeCall(ToolCallRequested $event): void
    {
        if (TreeMergeAgentTools::class !== $event->getDefinition()->getReference()->getClass()) {
            return;
        }
        $treeId = $event->getToolCall()->getArguments()['treeId'] ?? null;
        if (!is_string($treeId)) {
            return;
        }
        try {
            $this->drafts->active($treeId);
        } catch (ApiException $error) {
            $event->setResult(new ToolResult($event->getToolCall(), ['error' => [
                'code' => $error->getErrorCode(), 'fields' => $error->getFields(), 'details' => $error->getDetails(),
            ]]));
        }
    }

    #[AsEventListener]
    public function afterCall(ToolCallSucceeded $event): void
    {
        $result = $event->getResult()->getResult();
        $session = $this->requests->getMainRequest()?->attributes->get(AuthSession::class);
        if (!$event->getTool() instanceof TreeMergeAgentTools || 'merge' !== $event->getDefinition()->getName()
            || !is_array($result) || isset($result['error']) || !$session instanceof AuthSession) {
            return;
        }
        $treeId = $event->getArguments()['treeId'] ?? null;
        if (is_string($treeId)) {
            $draft = $this->drafts->active($treeId);
            $this->events->begin($draft);
            $this->events->draft($session, $this->views->create($draft));
        }
    }
}
