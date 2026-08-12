<?php

namespace App\Infrastructure\Doctrine\Dql;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

final class NormalizeTextFunction extends FunctionNode
{
    private Node $value;

    public function getSql(SqlWalker $sqlWalker): string
    {
        $value = $sqlWalker->walkStringPrimary($this->value);

        return "trim(regexp_replace(lower(translate(COALESCE({$value}, ''), 'Ёё', 'Ее')), '[^[:alnum:]]+', ' ', 'g'))";
    }

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->value = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
