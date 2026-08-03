<?php

declare(strict_types=1);

namespace App\Platform\Security;

use App\Platform\Http\ApiException;
use App\Platform\Http\ErrorCode;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

/**
 * The authenticated account for the current request.
 *
 * Ownership is always derived from the session through this service and never
 * from the request body, which is the single rule that prevents a client from
 * acting on someone else's character by supplying their id.
 * See docs/architecture.md section 5.
 */
final class CurrentAccount
{
    public function __construct(private readonly Security $security)
    {
    }

    public function id(): Uuid
    {
        return $this->idOrNull()
            ?? throw ApiException::of(ErrorCode::AuthenticationRequired, 'Authentication is required.');
    }

    public function idOrNull(): ?Uuid
    {
        $user = $this->security->getUser();

        if (!$user instanceof AuthenticatedAccount) {
            return null;
        }

        return Uuid::fromString($user->accountId());
    }
}
