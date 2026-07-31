<?php

namespace App\Entity\Value;

enum KnowledgeState: string
{
    case KNOWN = 'known';
    case UNKNOWN = 'unknown';
}
