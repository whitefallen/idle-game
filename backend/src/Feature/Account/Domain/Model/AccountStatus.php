<?php

declare(strict_types=1);

namespace App\Feature\Account\Domain\Model;

enum AccountStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';

    /**
     * Soft-deleted. Rows are retained rather than removed so that audit records
     * and stored combat logs keep referring to something real.
     */
    case Deleted = 'deleted';

    public function canAuthenticate(): bool
    {
        return $this === self::Active;
    }
}
