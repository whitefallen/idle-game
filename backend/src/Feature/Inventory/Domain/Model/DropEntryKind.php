<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Domain\Model;

enum DropEntryKind: string
{
    case Nothing = 'nothing';
    case Material = 'material';
    case Item = 'item';
}
