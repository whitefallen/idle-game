---
name: Symfony Backend
description: Backend conventions.
---

# Principles

- Thin controllers
- Services contain business logic
- Repositories only query/persist
- DTOs for API boundaries
- Validation before domain logic
- Domain Events for cross-module communication

## Layers

Controller
Application Service
Domain
Infrastructure

Never skip layers for convenience.

## Doctrine

- UUID primary keys
- Explicit relations
- Avoid lazy loading in hot paths
- Migrations only

## Messenger

Use for:
- Emails
- Notifications
- Long-running jobs

Avoid for synchronous game actions.
