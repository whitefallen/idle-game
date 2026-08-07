<?php

declare(strict_types=1);

namespace App\Platform\Event;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The dispatcher, on Symfony's event dispatcher.
 *
 * Symfony's is used rather than a hand-rolled registry because it is already in
 * the container, its #[AsEventListener] attribute gives subscribers a
 * declaration that lives next to the code that reacts, and its dispatch is
 * synchronous — which is the guarantee this interface promises.
 *
 * The wrapper is thin on purpose. Its whole value is that the Application layer
 * depends on a one-method interface describing a domain concept instead of on
 * the framework's dispatcher, which also carries kernel events and a listener
 * API this project has no use for.
 */
final readonly class SymfonyDomainEventDispatcher implements DomainEventDispatcher
{
    public function __construct(private EventDispatcherInterface $events)
    {
    }

    public function dispatch(object $event): void
    {
        // Dispatched by class name rather than by a string name: subscribers
        // declare the event type they take, so a renamed or deleted event is a
        // static analysis error instead of a subscriber that silently stops
        // firing.
        $this->events->dispatch($event);
    }
}
