---
name: API Design
description: REST API conventions.
---

# Endpoints

Use nouns.

GET /characters
GET /inventory
POST /quests/{id}/accept

Errors:
- Consistent JSON
- Validation messages
- Stable error codes

Never expose internal exceptions.
