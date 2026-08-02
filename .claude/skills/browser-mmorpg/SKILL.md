---
name: Browser MMORPG
description: Defines gameplay rules and conventions for all browser MMORPG systems.
---

# Core Philosophy

Gameplay must be:
- Easy to learn
- Difficult to optimize
- Reward long-term progression
- Support idle and active play

# Core Entities

Player
Character
Inventory
Equipment
Quest
Monster
Dungeon
Guild
Mailbox
Shop
Crafting
Achievement
Companion
Pet

# Character Stats

Primary:
- Strength
- Dexterity
- Intelligence
- Constitution
- Luck

Derived:
- Health
- Damage
- Critical Chance
- Armor
- Initiative

Derived stats are calculated, never stored unless cached.

# Combat

- Turn-based.
- Deterministic on server.
- Random seed generated server-side.
- Damage pipeline:
  1. Base damage
  2. Equipment
  3. Buffs
  4. Critical
  5. Armor
  6. Resistance
  7. Final damage

# Inventory

Rules:
- Slot based.
- Stackable items define max stack.
- Equipment never stacks.
- Validation server-side.

# Equipment

Slots:
- Helmet
- Chest
- Gloves
- Boots
- Weapon
- Ring 1
- Ring 2
- Necklace
- Trinket

# Items

Rarities:
Common
Uncommon
Rare
Epic
Legendary
Mythic

Item definitions must be data-driven.

# Quests

Quest consists of:
- Objective
- Requirements
- Rewards
- Optional modifiers

Never hardcode rewards.

# Idle Progress

Offline progression:
- Timestamp based
- Maximum offline duration configurable
- Rewards reproducible

# Economy

Currencies:
- Gold
- Premium Currency

Avoid inflation by:
- Gold sinks
- Repair
- Crafting
- NPC vendors

# Events

Emit:
MonsterKilled
QuestCompleted
ItemEquipped
DungeonFinished
LevelUp

# Anti Cheat

Never trust:
- Damage
- XP
- Gold
- Quest completion
- Loot

# Checklist

- Server authoritative
- Data driven
- Reusable
- Testable
- Deterministic
