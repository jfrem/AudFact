<?php

declare(strict_types=1);

namespace App\Services\Audit;

enum SemanticMatchPurpose: string
{
    case PRODUCT = 'article_homologation';
    case PERSON = 'person_name_match';
}
