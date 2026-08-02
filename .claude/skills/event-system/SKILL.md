---
name: Event System
description: Domain events.

Events:
- PlayerLeveledUp
- ItemEquipped
- QuestCompleted
- MonsterKilled

Consumers must not depend on each other.

Prefer publishing events over direct service calls.
