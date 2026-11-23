# ADR 0005: A built-in recipe engine, not Ansible

Status: accepted
Date: 2026-11-04

## Context

The platform must install and configure nginx, PHP-FPM, supervisor, certbot
and the rest of the stack idempotently, and correct drift on a schedule.
Ansible is the obvious off-the-shelf answer.

## Decision

A small declarative engine in the agent (`internal/recipe`), with a tagged
union wire format the control plane emits as JSON.

Step types are a closed set: file, dir, symlink, absent, command, package,
service. An unrecognised type is an error, never a fallthrough to a shell.

## Reasoning

**Ansible needs a Python runtime on every node.** That is a second dependency
surface on a machine we are deliberately keeping minimal, and a second thing
to patch.

**Ansible is SSH-shaped.** The point of ADR 0001 is that we do not connect
inbound. Using Ansible would mean either reintroducing SSH or running
`ansible-local` on the node from a playbook we shipped, at which point the
orchestration value is gone and only the YAML remains.

**Structured progress.** Each step reports create, update, delete, run or
noop, and whether it changed anything. That distinction drives the deploy log,
the "nothing drifted" signal, and dry-run previews. Recovering it by parsing
Ansible output is possible and unpleasant.

**A closed step set is a security control.** The agent runs as root, and a
recipe arrives over the network. Restricting what a recipe can express to
seven primitives, with no shell among them, bounds what a compromised or buggy
control plane can instruct a node to do. Adding a capability requires shipping
a new agent, which is the point. An Ansible playbook is arbitrary execution by
construction.

## Consequences

- We own the engine, including every primitive we later need. Scope must stay
  narrow or this becomes the quarter-consuming item PLAN.md warns about in its
  risk register.
- No access to Ansible Galaxy. Given the supported stack is deliberately one
  nginx layout and one supervisor layout, there is little to reuse.
- Command steps are not idempotent by nature, so they take an optional guard.
  A command without one honestly reports that it ran.
- The engine is testable without touching a host, because everything that
  shells out goes through a `CommandRunner` interface.

## Review trigger

Revisit if the supported stack broadens enough that maintaining primitives
costs more than a Python runtime on every node. That is not near.
