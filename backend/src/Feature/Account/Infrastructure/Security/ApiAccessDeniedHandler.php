<?php

declare(strict_types=1);

namespace App\Feature\Account\Infrastructure\Security;

use App\Platform\Http\ApiResponder;
use App\Platform\Http\ErrorCode;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;

/**
 * Renders "authenticated, but not permitted" in the API envelope.
 *
 * Distinct from {@see ApiEntryPoint}, which handles "not authenticated at all".
 * Keeping the two apart is what lets a client tell "log in" from "you cannot do
 * this" without parsing a message.
 */
final class ApiAccessDeniedHandler implements AccessDeniedHandlerInterface
{
    public function __construct(private readonly ApiResponder $responder)
    {
    }

    public function handle(Request $request, AccessDeniedException $accessDeniedException): Response
    {
        return $this->responder->error(
            ErrorCode::Forbidden,
            'You do not have permission to do that.',
        );
    }
}
