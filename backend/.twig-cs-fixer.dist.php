<?php

$ruleset = (new TwigCsFixer\Ruleset\Ruleset())
    ->addStandard(new TwigCsFixer\Standard\TwigCsFixer())
    ->addStandard(new TwigCsFixer\Standard\Symfony())
    ->removeRule(TwigCsFixer\Rules\Function\IncludeFunctionRule::class)
;

$config = (new TwigCsFixer\Config\Config())
    ->setRuleset($ruleset)
    ->allowNonFixableRules()
;

return $config;
