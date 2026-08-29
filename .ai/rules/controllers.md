---
paths:
  - 'app/Http/Controllers/**'
---

# Controllers

## Guard platform-default/foreign-tenant rows behind route-model binding with 404, not 403
Models using both BelongsToCompany and HasPlatformOrCompanyDefault (e.g. Holiday) resolve implicit route-model bindings ({holiday}) through the model's DEFAULT query, which keeps BelongsToCompany's 'company' global scope active. That scope's `where company_id = <active company>` never matches company_id IS NULL, so a platform-default row 404s via binding alone, same as a foreign-company row — no extra work needed for the common case.

Still add an explicit `abort_if($row->company_id !== app(CurrentCompany::class)->id(), 404)` guard in update()/destroy() as defense-in-depth: BelongsToCompany::bootBelongsToCompany() skips its where clause entirely when CurrentCompany::id() is null (console/seeders — "trusted contexts, not user-facing requests"), so if a company-scoped controller were ever reached without an active company resolved, binding would stop filtering and any row could resolve. Use 404, not 403, so a platform default and an out-of-tenant row stay indistinguishable to the client — matches what binding already produces for the cross-tenant case. See app/Http/Controllers/HolidayController.php::abortIfNotOwnedByActiveCompany() for the reference implementation and tests/Feature/HolidayTest.php for the test pattern (platform-default + cross-tenant, both assertNotFound). Relevant for any future controller over a HasPlatformOrCompanyDefault catalog (e.g. Fase 9's payroll_concept_definitions/social_security_entities).
