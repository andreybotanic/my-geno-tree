<?php

namespace App\Infrastructure\Doctrine\Dql;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

final class LeftTextFunction extends FunctionNode
{
    private Node $value;
    private Node|string $length;

    public function getSql(SqlWalker $sqlWalker): string
    {
        return sprintf(
            'left(%s, %s)',
            $sqlWalker->walkStringPrimary($this->value),
            $sqlWalker->walkSimpleArithmeticExpression($this->length),
        );
    }

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->value = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->length = $parser->SimpleArithmeticExpression();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
