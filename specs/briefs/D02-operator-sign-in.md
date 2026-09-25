# D2 · Operator sign-in

**Call with**: `/speckit-specify D2` · **Source**: split from D1 by the owner at specify time, recorded 2026-09-25 (spec 006, FR-051)

## Description

Operators sign in to the back office with email and password, then a time-based one-time code (the second factor). Ten single-use recovery codes are issued at enrolment and shown once. When they are exhausted, two other operators must approve a re-enrolment. An artisan command creates the first operator. Every back-office route, including Livewire's update endpoint when it serves a back-office component, sits behind the separate admin guard and its second factor.

The D1 shell already accepts who is signed in (`OperatorPresence`) and a sign-out URL. This feature binds them through a view composer, with no shell edit. The operator sign-in screen is drawn in the design source ("Screen · Operator sign-in", English and Arabic).

## Considerations

- Every operator is an admin in MVP (owner decision, 2026-09-25). There are no finer roles.
- The one-time-code implementation is a small package (needs the owner's approval) or an in-house RFC 6238 implementation. Decide at planning.
- Operator accounts are a new entity owned by Admin, so add the **Operator Account** glossary entry before the spec uses it.
- The operator's language choice (session-scoped in D1) can become a stored preference here.
- Sign-in, sign-out, failed attempts, enrolment and re-enrolment approvals are audit-log entries through Identity's audit service (`actor_type = operator`).

## Reconcile at specify time

| Brief says | Already decided | Resolution needed |
|---|---|---|
| Second factor and recovery | Security baseline "Operator access": time-based codes, ten recovery codes, two-operator re-enrolment | Implement as written there |
| Separate guard | ADR-0006, P-08 rule 4, P-18 rule 4 | Required; application credentials never reach `/admin` |
| Route surface | D1 registers only the local gallery under `/admin` (`AdminRouteSurfaceTest`) | Update that test when the guarded routes arrive |
