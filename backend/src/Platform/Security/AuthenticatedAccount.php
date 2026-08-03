<?php

declare(strict_types=1);

namespace App\Platform\Security;

/**
 * Implemented by whatever the security component authenticates.
 *
 * Exists so that Platform and the Http layer can ask "which account is this?"
 * without importing the Account feature's Infrastructure adapter, which the
 * layering rules forbid and which would couple every controller to one
 * feature's security implementation.
 */
interface AuthenticatedAccount
{
    /** RFC 4122 representation of the account identifier. */
    public function accountId(): string;
}
