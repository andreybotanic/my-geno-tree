<?php

namespace App\Infrastructure\Doctrine\Dql;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

final class NormalizedFullNameFunction extends FunctionNode
{
    private Node $birthSurname;
    private Node $givenName;
    private Node $patronymic;

    public function getSql(SqlWalker $sqlWalker): string
    {
        $birthSurname = $sqlWalker->walkStringPrimary($this->birthSurname);
        $givenName = $sqlWalker->walkStringPrimary($this->givenName);
        $patronymic = $sqlWalker->walkStringPrimary($this->patronymic);

        return "trim(regexp_replace(lower(translate(COALESCE({$birthSurname}, '') || ' ' || COALESCE({$givenName}, '') || ' ' || COALESCE({$patronymic}, ''), 'Ёё', 'Ее')), '[^[:alnum:]]+', ' ', 'g'))";
    }

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->birthSurname = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->givenName = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->patronymic = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
