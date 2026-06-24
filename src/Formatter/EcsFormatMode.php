<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Formatter;

enum EcsFormatMode: string
{
    case Move = 'move';
    case Copy = 'copy';
}
