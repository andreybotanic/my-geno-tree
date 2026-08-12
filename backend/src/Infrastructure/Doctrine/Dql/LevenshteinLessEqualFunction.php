<?php

namespace App\Infrastructure\Doctrine\Dql;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

final class LevenshteinLessEqualFunction extends FunctionNode
{
    private Node $left;
    private Node $right;
    private Node|string $maximumDistance;

    public function getSql(SqlWalker $sqlWalker): string
    {
        return sprintf(
            'levenshtein_less_equal(%s, %s, %s)',
            $sqlWalker->walkStringPrimary($this->left),
            $sqlWalker->walkStringPrimary($this->right),
            $sqlWalker->walkSimpleArithmeticExpression($this->maximumDistance),
        );
    }

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->left = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->right = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->maximumDistance = $parser->SimpleArithmeticExpression();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
