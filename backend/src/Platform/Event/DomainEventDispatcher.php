<?php

declare(strict_types=1);

namespace App\Platform\Event;

/**
 * Publishes a domain event to subscribers synchronously, in the caller's
 * transaction.
 *
 * The in-transaction half of the split ADR-0004 decided; OutboxRecorder is the
 * deferred half. The two are deliberately different types rather than one type
 * with a flag, so the call site says which guarantee it is asking for and a
 * reader can tell the two apart without following the argument.
 *
 * Use this only for effects that must be atomic with the action. A subscriber
 * that throws rolls the whole command back — which is the point. If an effect
 * is allowed to fail on its own, it belongs on the outbox.
 *
 * Subscribers cannot return anything to the emitter. Where the emitter needs to
 * know what a subscriber did, it reads that feature's published read model
 * afterwards (docs/architecture.md section 3.1). See ADR-0007 for why this
 * restriction is the load-bearing part of the design.
 */
interface DomainEventDispatcher
{
    public function dispatch(object $event): void;
}
