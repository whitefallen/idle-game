<?php

declare(strict_types=1);

namespace App\Feature\Account\Infrastructure\Security;

use App\Platform\Http\ApiResponder;
use App\Platform\Http\ErrorCode;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * What happens when an unauthenticated request reaches a protected endpoint.
 *
 * Without an entry point the security component reports "access denied" (403)
 * for a caller that simply has no session, which tells a client to give up
 * rather than to log in. An API needs the distinction: 401 means authenticate,
 * 403 means authenticated but not permitted. See docs/api.md section 3.
 */
final class ApiEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(private readonly ApiResponder $responder)
    {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->responder->error(
            ErrorCode::AuthenticationRequired,
            'Authentication is required.',
        );
    }
}
