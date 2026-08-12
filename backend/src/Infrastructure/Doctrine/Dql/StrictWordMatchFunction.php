<?php

namespace App\Infrastructure\Doctrine\Dql;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

final class StrictWordMatchFunction extends FunctionNode
{
    private Node $query;
    private Node $value;

    public function getSql(SqlWalker $sqlWalker): string
    {
        return sprintf(
            '%s <<%% %s',
            $sqlWalker->walkStringPrimary($this->query),
            $sqlWalker->walkStringPrimary($this->value),
        );
    }

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->query = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->value = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
