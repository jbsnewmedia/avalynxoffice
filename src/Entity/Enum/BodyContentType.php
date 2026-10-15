<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum BodyContentType: string
{
    case TEXT = 'text';
    case HTML = 'html';
}
