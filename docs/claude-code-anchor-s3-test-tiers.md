# Claude Code Session - anchor-s3: LocalStack and Real-AWS Test Tiers

## Context

`laravel-chronicle/anchor-s3` anchors Chronicle ledger checkpoints to S3 Object Lock, so that proof of a ledger's history is stored somewhere the application cannot rewrite.

Today the package has one test tier: an in-process suite running against a mocked S3 client. It verifies what the adapter *decides to send*. It cannot verify that AWS *granted the guarantee*, because there is no AWS involved.

This session adds two further tiers.

## The distinction that governs every decision here

The package's promise is **"this anchored object cannot be deleted."** That is a property of AWS's enforcement, not of our code. Our code's job is to correctly *request* that guarantee.

This gives three genuinely different things to test, and they must not be conflated:

1. **Unit (mocked)** - does the adapter form the right request and handle responses/errors correctly? Pure logic, no I/O.
2. **LocalStack** - does the adapter talk to a real S3 API surface correctly? Catches request-shape regressions, malformed parameters, SDK version drift. **LocalStack emulates the Object Lock API but does not enforce it** - it will happily accept a retention header and return 200 for an object that is fully deletable. This tier proves the *conversation* is correct. It proves nothing about immutability.
3. **Real AWS** - is the object actually immutable? This is the only tier that can validate the product's core claim.

**Design constraint:** it must be impossible for a future contributor to read the test suite and believe LocalStack validates the guarantee. Naming, grouping, and comments must all reinforce the distinction. If a test asserts immutability, it belongs in tier 3 and nowhere else.

## Deliverables

### 1. Test tier infrastructure

- Pest groups: `unit`, `localstack`, `aws`.
- Composer scripts:
  - `composer test` -> `unit` only. **Must pass with zero configuration, no Docker, no AWS credentials, no network.** This is the contributor's default and the barrier to a first contribution; keep it at `composer install && composer test`.
  - `composer test:localstack` -> `localstack` group.
  - `composer test:aws` -> `aws` group.
  - `composer test:all` -> everything.
- Both non-unit groups must **skip cleanly with an explanatory message**, not error, when their prerequisites are absent. A contributor running `composer test:all` with no Docker should see "skipped: LocalStack not reachable at $ENDPOINT", not a stack trace.

### 2. LocalStack tier

- `docker-compose.yml` with a LocalStack service exposing S3. Pin the image version; do not use `latest`.
- Test bootstrap that creates a bucket with versioning and Object Lock enabled, points the S3 client at the LocalStack endpoint (path-style addressing), and tears the bucket down afterward.
- Tests covering:
  - Anchoring a checkpoint produces an object at the expected key with the expected content.
  - Retention parameters are transmitted (assert on what LocalStack received - **not** that the object is protected).
  - A bucket without versioning -> the adapter refuses at configuration time, loudly.
  - A bucket without Object Lock -> same: refuse, do not proceed with a warning.
  - Error paths: bucket missing, access denied, network failure.
- **Every test in this group gets a header comment** stating that LocalStack does not enforce Object Lock, and that immutability assertions belong in the `aws` group.

### 3. Real-AWS tier

This tier is small on purpose. It exists to validate the one thing nothing else can.

The essential test:

1. Anchor a checkpoint, Governance-mode retention, expiry measured in **minutes**.
2. Attempt to delete the object.
3. **Assert the delete fails.**
4. Clean up (remove retention via governance bypass, delete object and version).

Plus:

- Retention is actually present on the object when read back via `GetObjectRetention`.
- Credentials lacking `s3:PutObjectRetention` -> anchoring fails loudly rather than writing an unprotected object.
- A tampered checkpoint does not verify against what was anchored.

**Safety requirements - these are not negotiable:**

- **Governance mode only. Never Compliance mode.** Compliance-mode retention cannot be removed by anyone, including the account root user, until it expires. A test that sets a long Compliance retention creates an object that is billable and undeletable for the duration, with no recourse. Assert in the test bootstrap that the configured mode is Governance and **fail hard** if it is not.
- Retention periods in tests measured in minutes.
- Cleanup runs in a `finally`-equivalent so a failing assertion still tears down.
- Bucket name read from env; no default that could point at something real.

### 4. Configuration

- `.env.example` documenting `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_REGION`, `ANCHOR_S3_TEST_BUCKET`, `ANCHOR_S3_TEST_RETENTION_MODE` (must be `GOVERNANCE`), and the LocalStack endpoint.
- `.env` in `.gitignore`. Verify it already is.

### 5. CI

Extend the existing GitHub Actions workflow:

- **Unit** - every push and every PR, including forks. No secrets.
- **LocalStack** - every push and every PR, including forks. LocalStack as a service container; no secrets required, so fork PRs run it fine.
- **AWS** - **push to `main` and a nightly schedule only. Never on `pull_request`.** Fork PRs cannot access secrets; running this on PRs would produce failing red checks for exactly the external contributors we want to encourage. Gate the job on the secret being present, so it skips cleanly rather than failing when absent.

### 6. Documentation

- Update `CONTRIBUTING.md` to describe the three tiers, when a contributor needs each, and the Governance-mode warning in full.
- Include the AWS setup: bucket with versioning + Object Lock (simplest to enable at bucket creation), a scoped IAM policy granting only what the tests need against only that bucket, and a lifecycle rule to sweep anything cleanup missed.

## Constraints

- PHPStan level 10 must stay clean. No new baseline entries.
- No new runtime dependencies. Dev dependencies for testing are fine.
- Style via the existing `composer format`; do not hand-tune.
- Do not weaken any existing test to make a new tier pass.

## Approach

Work in this order and stop for review after each:

1. **Propose the structure first** - Pest groups, composer scripts, skip logic, file layout. Show me this before writing tests.
2. LocalStack: compose file, bootstrap, tests.
3. Real AWS: bootstrap with the Governance-mode assertion, then the delete-must-fail test, then the rest.
4. CI workflow.
5. Docs.

Read the existing test suite and `composer.json` before proposing anything - the scripts and structure should match what is already there, not impose a new convention.

## The test that matters

If you build only one thing in this session, build the real-AWS test that anchors an object, tries to delete it, and asserts the delete fails.

Everything else in this repo is machinery for making that guarantee. That test is the only thing that checks the guarantee is real.
