<?php

namespace App\Infrastructure\Doctrine\Dql;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

final class ConcatWithSeparatorFunction extends FunctionNode
{
    private Node $separator;

    /** @var list<Node> */
    private array $values = [];

    public function getSql(SqlWalker $sqlWalker): string
    {
        $arguments = [
            $sqlWalker->walkStringPrimary($this->separator),
            ...array_map(
                static fn (Node $value): string => $sqlWalker->walkStringPrimary($value),
                $this->values,
            ),
        ];

        return sprintf('concat_ws(%s)', implode(', ', $arguments));
    }

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->separator = $parser->StringPrimary();

        do {
            $parser->match(TokenType::T_COMMA);
            $this->values[] = $parser->StringPrimary();
        } while ($parser->getLexer()->isNextToken(TokenType::T_COMMA));

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
