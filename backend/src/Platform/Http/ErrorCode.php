<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Symfony\Component\HttpFoundation\Response;

/**
 * The stable, machine-readable error vocabulary.
 *
 * Clients branch on the code and never on the message: the code is part of the
 * API contract, the message is a developer-facing English fallback. Player-facing
 * text is resolved client-side from a localisation key derived from the code, so
 * the server never needs to know the player's language.
 *
 * See docs/api.md section 2.
 */
enum ErrorCode: string
{
    case ValidationFailed = 'VALIDATION_FAILED';
    case MalformedRequest = 'MALFORMED_REQUEST';
    case AuthenticationRequired = 'AUTHENTICATION_REQUIRED';
    case InvalidCredentials = 'INVALID_CREDENTIALS';
    case Forbidden = 'FORBIDDEN';
    case NotFound = 'NOT_FOUND';
    case Conflict = 'CONFLICT';
    case EmailAlreadyRegistered = 'EMAIL_ALREADY_REGISTERED';
    case CharacterNameTaken = 'CHARACTER_NAME_TAKEN';
    case CharacterLimitReached = 'CHARACTER_LIMIT_REACHED';
    case InsufficientVigor = 'INSUFFICIENT_VIGOR';
    case ActivityInProgress = 'ACTIVITY_IN_PROGRESS';
    case InsufficientGold = 'INSUFFICIENT_GOLD';
    case InsufficientMaterial = 'INSUFFICIENT_MATERIAL';
    case InsufficientPoints = 'INSUFFICIENT_POINTS';
    case RequirementNotMet = 'REQUIREMENT_NOT_MET';
    case IdempotencyConflict = 'IDEMPOTENCY_CONFLICT';
    case RateLimited = 'RATE_LIMITED';
    case InternalError = 'INTERNAL_ERROR';

    public function httpStatus(): int
    {
        return match ($this) {
            self::MalformedRequest => Response::HTTP_BAD_REQUEST,
            self::AuthenticationRequired, self::InvalidCredentials => Response::HTTP_UNAUTHORIZED,
            self::Forbidden => Response::HTTP_FORBIDDEN,
            self::NotFound => Response::HTTP_NOT_FOUND,
            self::Conflict,
            self::EmailAlreadyRegistered,
            self::CharacterNameTaken,
            // A state conflict rather than throttling: the character is busy,
            // and the response says for how long. 429 would invite the generic
            // backoff-and-retry behaviour built into most HTTP clients, which
            // is wrong here — the caller should wait the stated interval and
            // the UI should show a countdown, not retry blindly.
            self::ActivityInProgress,
            self::IdempotencyConflict => Response::HTTP_CONFLICT,
            self::ValidationFailed,
            self::CharacterLimitReached,
            self::InsufficientVigor,
            self::InsufficientGold,
            self::InsufficientMaterial,
            self::InsufficientPoints,
            self::RequirementNotMet => Response::HTTP_UNPROCESSABLE_ENTITY,
            self::RateLimited => Response::HTTP_TOO_MANY_REQUESTS,
            self::InternalError => Response::HTTP_INTERNAL_SERVER_ERROR,
        };
    }
}
