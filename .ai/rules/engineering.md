---
paths:
  - 'app/**'
  - 'tests/**'
  - 'database/**'
  - 'docs/openapi/**'
  - 'specs/**'
---

## Read CONTRIBUTING.md before opening a pull request
The definition of done lives in CONTRIBUTING.md at the repository root: every item a feature ships, and whether the pipeline or a reviewer checks it. Treat it as the checklist for your own work, not as reading for later. Engineering detail is in docs/engineering-conventions.md.

## Code goes in the module layout
New code lives in app/Modules/<Context>/{Domain,Application,Infrastructure,Http}, one of nine contexts plus the persistence-free app/Modules/Shared. Domain never touches Illuminate\Database; a module never imports another module's Infrastructure or Http. tests/Arch enforces this and names constitution P-02 when it fails.

## Four test suites, one per layer
tests/Unit is domain rules with no framework or database. tests/Feature is application services against PostgreSQL 16. tests/Contract is HTTP endpoints validated against docs/openapi through Spectator, asserting assertValidRequest() and assertValidResponse($status). tests/Arch holds the architecture gates. Put each test in the layer it exercises.

## Run everything in the container
Host PHP has no pdo_pgsql. `dev <artisan command>` runs artisan (the prefix is added for you); `dev exec -- <cmd>` runs anything else as written. Run `dev exec -- composer test` for the whole gate, or `composer test:arch`, `test:suite`, `test:api` for one stage. `composer test` must pass before a pull request is opened.

## An empty envelope meta is an object
PHP serialises an empty array to JSON [], but the API contract requires meta to be {}. Emit (object) [] for an empty meta, or the contract tests fail.
