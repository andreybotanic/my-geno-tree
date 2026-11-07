# AGENTS.md

## Core Rules

- Treat `spec/spec.md` as the entry point and source of truth for product requirements. Read the linked domain, MVP, API, date, quality, and future-extension documents before implementing related behavior.
- Keep the original `spec` directory unchanged. Change `spec` only when the user explicitly requests a requirements update.
- Use PHP 8.5 and Symfony 8.1 capabilities as the default backend target.
- Use PostgreSQL 18, Doctrine ORM, Doctrine Migrations, and Flysystem as defined by the specification.
- Use Node.js 24 LTS and strict TypeScript for the frontend. The frontend may use React or Vue.js, but the selected framework must be used consistently.
- Prefer the newest stable framework and language features available within the version constraints of this project whenever they improve clarity.
- If Context7 does not contain enough detail for the required library version, use the library's official documentation as the source of truth.
- Keep Doctrine entity `id` properties nullable in PHP entities when the database generates the identifier.
- Preserve the extension boundaries documented in `spec/future.md`; do not implement deferred features, but do not introduce schema or architecture blockers for them.

## Execution Environment

- Run all `php`, `composer`, `bin/console`, Node.js, package-manager, test, static-analysis, lint, and build commands inside Docker containers only.
- From the host machine, use only `docker compose ...` commands for application work.
- Inspect `compose.yaml` or `docker-compose.yml` before choosing a service name; do not assume container names that are not declared by the project.
- Treat the backend and frontend application containers as their respective default execution environments.
- Use the local HTTPS endpoint provided by Docker Compose when validating browser behavior.
- Run database migrations through the application container. Do not modify the PostgreSQL schema manually.

## Symfony Console Preference

- Prefer Symfony console commands whenever an official command exists for the task.
- Create entities through Symfony Maker commands such as `php bin/console make:entity`.
- Create migrations through Symfony and Doctrine console commands such as `php bin/console make:migration` and execute them with `php bin/console doctrine:migrations:migrate`.
- Prefer Symfony console workflows over manual file creation or ad-hoc scripts wherever those commands are applicable.
- Inspect available services, autowiring aliases, routes, configuration, and Doctrine mappings with the corresponding `debug:*` or Doctrine console commands before guessing.

## Architecture Rules

- Keep controllers thin. Put use-case orchestration in application services and domain invariants in the domain layer.
- All tree and person creation must go through application services so future quota policies can be added without rewriting controllers or handlers.
- Direct database access from controllers is forbidden. Use Doctrine repositories, QueryBuilder, or dedicated read services.
- In Doctrine repositories, access data only through the ORM QueryBuilder; do not inject or use `Doctrine\\DBAL\\Connection` there.
- Any use of `Doctrine\\DBAL\\Connection` (including obtaining it from another Doctrine service) requires explicit user agreement before implementation.
- Avoid the N+1 query problem. Review every collection-loading path and list endpoint for query count.
- Use joins, explicit eager loading for the concrete use case, DTO projections, batched loading, or purpose-built repository queries when they avoid N+1 queries.
- Do not solve N+1 problems by marking broad associations globally eager unless that loading behavior is correct for every use case.
- Add query-count assertions or inspect the Symfony/Doctrine profiler for data-heavy changes where an N+1 regression is plausible.
- Keep transactions short and place all parts of a structural tree mutation, reference update, automatic-event synchronization, and ETag update in one transaction.
- Preserve tree ownership and connectivity checks on the backend. Frontend restrictions are not authorization or integrity enforcement.
- Use Symfony voters for subject-specific authorization. Do not scatter owner-ID comparisons across controllers and services when a voter can express the rule.
- Guard controller actions with `#[IsGranted(...)]` attributes that point to resolved controller subjects when the attribute can express the check.
- Prefer constructor autowiring. Use an explicit `#[Target(...)]` or `#[Autowire(...)]` attribute when multiple implementations exist or parameter naming could influence resolution.
- Before injecting an interface with multiple implementations, inspect available targets with `php bin/console debug:autowiring <Type> --all` inside Docker.
- Prefer a named `#[Target(...)]` shown by `debug:autowiring` over a manually constructed service reference. This especially applies to Flysystem storages and other named services.
- Prefer event listeners declared with `#[AsEventListener]` over event subscribers when a listener clearly expresses the behavior.
- Keep public REST contracts under `/api/v1`, use UUIDv7 public identifiers, and keep OpenAPI synchronized with implementation changes.
- Return machine-readable API errors; localize user-facing messages in the frontend.
- Do not persist coordinates for ordinary tree cards; derive their deterministic layout from genealogical relationships and the tree reference. Keep saved force-map positions as a separate representation.
- Model media as media objects and typed attachments, not as an unextendable single file path on a person or event.
- Keep the domain model independent of React/Vue component state and presentation-specific JSON.
- Use semantic design tokens or CSS variables for colors, borders, shadows, placeholders, and graph lines. Do not hard-code a light-only color model.
- Do not create unused tables, endpoints, interfaces, or services solely for deferred features.
- Do not create helper functions for trivial actions that add no meaningful domain or application logic.

## Code Formatting

- Keep PHP lines at most 120 characters. Allow longer lines only when they cannot be wrapped without violating another formatting rule or changing an indivisible literal, for example an exception message in a `throw` statement.
- Format each array either entirely on one line or with every element on its own line and the opening and closing delimiters on separate lines. Apply this rule recursively to nested arrays; do not mix these layouts within one array.
- Format each method-call chain either entirely on one line or with every call on its own line. Once a chain spans multiple lines, do not place multiple chained calls on the same line.
- Format method declarations and calls either with the method name and the entire parameter or argument list on one line, or with every parameter or argument starting on its own line after the opening parenthesis and the closing parenthesis on a separate line. Do not mix inline and multiline parameters or arguments within one declaration or call.

## Frontend Rules

- Keep TypeScript strict mode enabled; do not weaken compiler options to accommodate a local implementation shortcut.
- Generate API types from OpenAPI or verify them against OpenAPI in automated checks.
- Keep server state, transient form state, viewport state, and saved force-map positions as separate concepts. Ordinary tree positions are transient derived data.
- Never treat hidden controls or client-side validation as enforcement of permissions or domain invariants.
- Escape user-provided plain text. Do not render names or event descriptions as HTML.
- Use the same structured date and unknown-value semantics as the API; do not replace them with ambiguous empty strings.
- Keep all visible system text localized in English and Russian. Do not translate user-provided content.
- Validate loading, empty, error, conflict, and unauthorized states for every server-backed screen.

## Dependency Policy

- Agents may independently add popular, actively maintained libraries, packages, Symfony bundles, and frontend dependencies when they are useful for the task. Separate user approval is not required.
- Before adding a dependency, verify that it supports the project's PHP, Symfony, Node.js, and frontend-framework versions.
- Prefer established dependencies with clear maintenance status, compatible licensing, and focused scope.
- Do not add a dependency for functionality that is smaller and clearer to implement directly.
- Commit dependency manifests and lock files together with the code that uses the dependency.
- Configure, test, and document a new dependency in the same feature commit; do not leave an installed but unused package.

## Quality Requirements

- Before committing each completed feature, run the `phpstorm-inspections` skill for all affected PHP files at `WARNING` severity. Apply every available quick fix, then manually correct every remaining `WARNING`-or-higher finding that can be fixed; re-inspect each manually changed file until it is clean.
- Cover production behavior with automated tests at the appropriate unit, integration, or API level.
- Keep every production file at `75%` coverage or higher where the coverage tooling can measure it meaningfully.
- Keep total automated-test code coverage at `80%` or higher.
- Use integration and API tests for request-level behavior and infrastructure boundaries.
- Do not create automated E2E tests unless the requirements are changed to include them.
- The absence of automated E2E tests does not remove the obligation to test the real application in a browser.
- During development, periodically open the local HTTPS application in a real browser and imitate relevant user activity.
- Before completing a user-visible feature or a substantial user-flow change, manually exercise its main happy path and important error or conflict path in the browser.
- Browser validation should include realistic actions such as navigation, form entry, saving, refreshing, authentication, pan/zoom, image upload, or concurrent-session conflict whenever they are relevant to the change.
- Record the manually checked browser scenarios in the final handoff for the task.
- Run backend tests, frontend tests, OpenAPI contract checks, static analysis, linters, migration checks, and production builds required by `spec/quality.md`.
- Do not consider work complete until all applicable automated tests and quality checks pass.

## Development Workflow

- Work in complete, reviewable feature slices and preserve unrelated user changes.
- A finished feature must be committed to Git before starting the next independent feature.
- Before every feature commit, run the complete automated test suite, not only tests related to the changed files.
- Also run all required static-analysis, lint, OpenAPI, migration, coverage, and production-build checks before the commit.
- Do not commit when any required test or quality check fails.
- Keep each feature commit focused on one finished feature and include its tests, migrations, dependency lock-file changes, and documentation updates.
- Use a concise commit message that describes the completed behavior.
- Do not combine unrelated cleanup with a feature commit.
- If the repository has no Git metadata or committing is otherwise impossible, report that explicitly instead of pretending a commit was created.
