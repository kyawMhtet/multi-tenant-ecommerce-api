---
name: scaffold-resource
description: Scaffold a new tenant-scoped Laravel resource (migration, model, Form Request, Service, API Resource, controller, Pest test) following this project's service-layer and tenant-isolation conventions. Use when adding a new tenant-owned entity.
argument-hint: [ResourceName]
---

Scaffold a new tenant-scoped resource called $ARGUMENTS, following CLAUDE.md.

Before writing code, briefly explain:
1. Why this resource needs BelongsToTenant
2. What business logic (if any) belongs in a Service vs. plain CRUD

Then create, in order:
1. A migration with a tenant_id foreign key, matching existing style
2. A model using App\Models\Concerns\BelongsToTenant
3. A Form Request in app/Http/Requests/ for validation
4. A Service in app/Services/ if there's logic beyond plain CRUD (skip
   and note why if not)
5. An API Resource in app/Http/Resources/ controlling exactly what's
   exposed — flag if a separate public-facing variant is needed for
   cost/margin-sensitive fields
6. A thin controller: validates via the Form Request, calls the Service
   (or model for plain CRUD), returns the Resource
7. A Pest test verifying tenant A's data is invisible to tenant B —
   required, not optional

Run the new test and confirm it passes before considering the task done.

After creating all three, tell me to restart Claude Code so it picks up
the new .claude/agents/ and .claude/skills/ directories.
