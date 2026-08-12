<?php

namespace App\Infrastructure\Doctrine\Dql;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

final class LeastIntegerFunction extends FunctionNode
{
    /** @var list<Node|string> */
    private array $expressions = [];

    public function getSql(SqlWalker $sqlWalker): string
    {
        return sprintf(
            'LEAST(%s)',
            implode(', ', array_map(
                static fn (Node|string $expression): string => $sqlWalker->walkSimpleArithmeticExpression($expression),
                $this->expressions,
            )),
        );
    }

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->expressions[] = $parser->SimpleArithmeticExpression();

        while ($parser->getLexer()->isNextToken(TokenType::T_COMMA)) {
            $parser->match(TokenType::T_COMMA);
            $this->expressions[] = $parser->SimpleArithmeticExpression();
        }

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
