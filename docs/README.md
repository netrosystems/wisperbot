# WisperBot Documentation Index

This directory and the root specification files form the durable project memory for engineers, operators, product owners, and coding agents.

## Core Specifications (Root)

- [**Agent Routing Guide (`AGENTS.md`)**](../AGENTS.md) — Lightweight instructions & decision routing table for AI coding agents to minimize token burn.
- [**Technical Architecture Specification (`ARCHITECTURE.md`)**](../ARCHITECTURE.md) — System boundaries, modular monolith architecture, multi-tenancy, queue pipelines, WebSocket events, and quality gates.
- [**UI/UX Design System (`DESIGNSYSTEM.md`)**](../DESIGNSYSTEM.md) — Space Grotesk & Fraunces typography, Orange & Amber brand tokens, layout archetypes, border-driven surfaces, and UI component primitives.
- [**Feature Plan & Specifications (`PLAN.md`)**](../PLAN.md) — Core user journeys, feature module breakdowns, UI state machines, and testing verification matrices.

## Detailed Domain Guides (`docs/`)

1. [Handoff](HANDOFF.md) — Current state and immediate cautions.
2. [Product Decisions](PRODUCT_DECISIONS.md) — Why important behavior and terminology exist.
3. [Integrations](INTEGRATIONS.md) — Provider configuration, OAuth scopes, and limitations.
4. [Operations](OPERATIONS.md) — Local and production environment operation.
5. [Security](SECURITY.md) — Trust boundaries and required safeguards.
6. [Known Issues](KNOWN_ISSUES.md) — Unfinished or fragile areas.
7. [Changelog](CHANGELOG.md) — Documentation-level release history.

## Additional Setup Guides

- [Deployment](../DEPLOYMENT.md)
- [eBay Seller Messaging](../EBAY_SELLER_MESSAGING_SETUP.md)
- [Amazon Seller Messaging](../AMAZON_SELLER_MESSAGING_SETUP.md)

## Maintenance rule

Documentation updates are part of the definition of done. See [AGENTS.md](../AGENTS.md) for the required documentation impact matrix. `docs/HANDOFF.md` is a status snapshot, while the other documents describe durable system behavior.
