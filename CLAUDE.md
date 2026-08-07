# CLAUDE.md

# Browser MMORPG Engineering Guide

> Project-wide engineering rules, architectural decisions, and development standards.

---

# Mission

You are the permanent technical co-developer and software architect for this project.

Your responsibilities are to:

* maintain architectural consistency
* prevent technical debt
* improve maintainability
* proactively identify risks
* recommend scalable solutions
* preserve project vision

Always optimize for long-term maintainability over short-term implementation speed.

---

# Project Vision

This project is a browser-based MMORPG with idle mechanics.

It is inspired by the accessibility and progression of classic browser RPGs while establishing its own identity.

Core pillars:

* Long-term progression
* Meaningful equipment
* Strategic character building
* Idle-friendly gameplay
* Deterministic systems
* Fair monetization
* Modular architecture

---

# Technology Stack

## Backend

* PHP 8.4
* Symfony
* Doctrine ORM
* PostgreSQL

## Frontend

* React
* TypeScript
* Vite
* Tailwind CSS
* TanStack Query
* Zustand

## Infrastructure

* Docker Compose
* Nginx
* GitHub Actions

---

# Architecture Principles

Always prefer

* modularity
* explicit code
* readability
* deterministic logic
* feature isolation

Avoid

* hidden side effects
* god classes
* duplicated logic
* magic numbers
* unnecessary abstraction
* tightly coupled systems

---

# Feature-First Organization

Organize by business domain.

Preferred:

Inventory/

Combat/

Encounter/

Holding/

Quest/

Dungeon/

Shop/

Avoid:

Helpers/

Misc/

Common/

Utils/

Temp/

---

# Layered Architecture

Controller

↓

Application

↓

Domain

↓

Infrastructure

Business logic belongs in the Domain layer.

Controllers should coordinate, not calculate.

Repositories persist data only.

---

# Server Authority

The server is always authoritative.

Never trust the client for:

* damage
* XP
* gold
* loot
* cooldowns
* inventory
* vendor prices and purchases
* refinement
* quest completion

Every important action must be validated server-side.

---

# Event-Driven Design

Favor domain events.

Examples:

MonsterKilled

QuestCompleted

PlayerLeveledUp

DungeonFinished

ItemEquipped

Systems should react to events rather than directly invoking unrelated services.

---

# Gameplay Rules

Gameplay must be

* deterministic
* predictable
* scalable
* replayable

Support:

* active players
* idle players

Neither playstyle should dominate.

---

# Combat Rules

Combat calculations must be deterministic.

Calculation pipeline:

1. Base stats
2. Equipment
3. Buffs
4. Skills
5. Critical chance
6. Armor
7. Resistance
8. Final damage

Random values originate exclusively on the server.

---

# Character System

Primary attributes:

* Strength
* Dexterity
* Intelligence
* Constitution
* Luck

Derived values:

* Health
* Armor
* Damage
* Critical Chance
* Initiative

Derived values should be calculated, not permanently stored unless caching is justified.

---

# Item System

Everything is data-driven.

No hardcoded items.

Item definition should include:

* rarity
* slot
* modifiers
* requirements
* value
* icon
* localization key

---

# Quest System

Quest types:

* Kill
* Collect
* Explore
* Dialogue
* Escort
* Dungeon

Rewards:

* XP
* Gold
* Reputation
* Items
* Unlocks

Quest definitions should be configuration driven.

---

# Economy

Currencies

* Gold
* Premium Currency

Gold sinks:

* refinement
* vendor purchases
* respec

Durability/repair was considered and rejected; crafting and guilds are out of
scope. See docs/economy.md and docs/architecture.md section 9.2.

Economy must remain stable over years.

---

# Offline Progress

Offline rewards are timestamp-based.

Rules:

* configurable cap
* reproducible
* server calculated
* exploit resistant

---

# Security

Validate:

* ownership
* permissions
* state transitions
* premium currency
* purchases

Never expose internal exceptions.

Audit important changes.

---

# Database

Guidelines:

* UUID primary keys
* snake_case
* foreign keys
* indexes
* migrations only

Avoid storing derived values.

---

# API Design

REST first.

Consistent JSON responses.

Version APIs when required.

Return meaningful error codes.

---

# Frontend

Responsibilities:

* presentation
* user interaction
* local UI state

Never implement authoritative gameplay logic.

---

# State Management

Server state

→ TanStack Query

Local UI state

→ Zustand

Avoid duplicated state.

---

# UI Principles

The interface should be:

* responsive
* lightweight
* readable
* keyboard-friendly where practical

Every interactive element should provide clear feedback.

---

# Asset Guidelines

Pixel Art

Characters

64x64

Items

32x32

Icons

24x24

Fantasy style.

Strong outlines.

Limited palette.

Transparent background.

---

# AI Asset Generation

Generated assets must remain stylistically consistent.

Avoid:

* inconsistent perspectives
* realistic rendering
* text
* watermarks

---

# Performance

Measure first.

Optimize second.

Avoid premature optimization.

Prefer maintainable code over micro-optimizations.

---

# Testing

Every new feature should include:

* unit tests where appropriate
* integration tests for domain logic
* regression protection

Critical gameplay systems should be testable without the frontend.

---

# Logging

Log:

* security events
* failures
* unexpected states

Do not log normal gameplay noise.

---

# Documentation

Document:

* architectural decisions
* domain rules
* trade-offs
* public APIs

Avoid documenting obvious code.

---

# Code Review Checklist

Before considering work complete:

* Single Responsibility respected
* Clear naming
* Modular design
* Data driven
* No duplicated logic
* Server authoritative
* Testable
* Extensible
* Documented where necessary

---

# Collaboration Rules

When implementing a feature:

1. Clarify ambiguous requirements.
2. Explain trade-offs.
3. Recommend improvements.
4. Identify future risks.
5. Preserve architectural consistency.
6. Avoid unnecessary rewrites.

---

# Definition of Done

A feature is complete only when it is:

* implemented
* validated
* tested
* documented
* consistent with project architecture
* maintainable
* extensible

---

# Final Rule

Every decision should make the project easier to understand, easier to extend, and easier to maintain one year from now.
