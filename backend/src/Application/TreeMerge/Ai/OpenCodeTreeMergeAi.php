<?php

namespace App\Application\TreeMerge\Ai;

use App\Application\TreeMerge\Ai\Dto\MergeAction;
use App\Application\TreeMerge\Ai\Dto\MergeAiResponse;
use App\Application\TreeMerge\Ai\Dto\MergeIntent;
use App\Application\TreeMerge\MergeActivity;
use App\Entity\TreeMergeDraft;
use App\Security\TreeMergeReadGrant;

/** @phpstan-import-type ContextPerson from MergeAiResult */
final readonly class OpenCodeTreeMergeAi implements TreeMergeAiInterface
{
    public function __construct(private OpenCodeRunner $runner, private TreeMergeReadGrant $grants, private MergeActivity $activity)
    {
    }

    public function respond(TreeMergeDraft $draft, string $attemptId, ?MergeAiRepair $repair = null): MergeAiResult
    {
        $latest = array_find(array_reverse($draft->messages), static fn ($message) => 'user' === $message['role'])['content'] ?? '';
        if (MergePrompt::locallyRefused($latest)) {
            return new MergeAiResult(new MergeAiResponse());
        }
        if (preg_match('/^\s*(?:применяй|примени|сохрани|apply|save)(?:\s|[.!]|$)/iu', $latest)) {
            $response = new MergeAiResponse();
            $response->action = MergeAction::TREE_ANSWER;

            return new MergeAiResult($response);
        }
        $input = json_encode(['interfaceLanguage' => $draft->owner->getPreferredLanguage(), 'treeId' => $draft->tree->getId()->toRfc4122(),
            'messages'                            => $draft->sourceMessages(), 'reviewedProposal' => $draft->proposal,
            'corrections'                         => array_slice($draft->corrections, -1)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (null !== $repair) {
            $response = $this->runner->run($draft, $input . "\nCorrect the COMPLETE proposal using this server validation feedback. Preserve supported facts. "
                . 'If facts are missing, ask concrete questions. Use only this previously observed MCP context: '
                . json_encode(['contexts' => $repair->previous->contexts, 'previousProposal' => $repair->previous->response,
                    'validation'          => $repair->feedback()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), MergeAiResponse::class);

            return new MergeAiResult($response, $repair->previous->people, $repair->previous->contexts);
        }
        $intent = $this->runner->run($draft, 'Classify only this latest user request. No tools are available. '
            . 'Questions about a named person in the selected family tree are in scope; unrelated requests must be refused. '
            . json_encode(['latestMessage' => $latest], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), MergeIntent::class);
        if (MergeAction::REFUSAL === $intent->action) {
            return new MergeAiResult(new MergeAiResponse());
        }
        $token = $this->grants->issue($draft, $attemptId);
        try {
            $response = $this->runner->run($draft, $input . "\nFirst call get_tree_context for the supplied tree. "
                . 'For a populated tree, search named people and read relevant candidates using MCP. At most eight read calls are allowed in total. '
                . 'Then prepare the complete proposal, necessary clarification, or tree answer. Return the structured result.', MergeAiResponse::class, $token);
            $contexts = $this->activity->read($draft)['contexts'];
            if ([] === $contexts) {
                throw new \RuntimeException('TREE_MERGE_MCP_CONTEXT_MISSING');
            }
            $people = [];
            foreach ($contexts as $context) {
                foreach (is_array($context['people'] ?? null) ? $context['people'] : [] as $person) {
                    if (is_array($person) && is_string($person['id'] ?? null)) {
                        /** @var ContextPerson $observed */
                        $observed = $person;
                        $people[$observed['id']] = $observed;
                    }
                }
            }

            return new MergeAiResult($response, $people, $contexts);
        } finally {
            $this->grants->revoke($token);
        }
    }
}
