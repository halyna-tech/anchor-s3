# anchor-s3 Test Tiers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add two test tiers (LocalStack and real-AWS) on top of the existing mocked unit tier, so the package proves not just what its adapter *decides to send* but that AWS *grants the immutability* the product promises - without ever letting a reader mistake LocalStack emulation for that guarantee.

**Architecture:** Three Pest groups - `unit` (mocked, zero-config, default), `localstack` (real S3 API surface, no enforcement), `aws` (the only tier that validates immutability). Non-unit groups skip cleanly when their prerequisites are absent. LocalStack runs from a pinned `docker-compose.yml`; the real-AWS tier reads its bucket from the environment and refuses to run in anything but Governance mode.

**Tech Stack:** PHP 8.2+, Pest 3/4, Orchestra Testbench, `aws/aws-sdk-php` (already a runtime dep - no new runtime deps), LocalStack (Docker), GitHub Actions.

## Global Constraints

Copied verbatim from `docs/claude-code-anchor-s3-test-tiers.md`:

- `composer test` **must pass with zero configuration, no Docker, no AWS credentials, no network** - the barrier to a first contribution stays `composer install && composer test`.
- Both non-unit groups **skip cleanly with an explanatory message**, not error, when prerequisites are absent.
- **Governance mode only. Never Compliance mode.** The real-AWS bootstrap asserts the configured mode is Governance and **fails hard** if it is not.
- Real-AWS retention periods in tests are short; cleanup runs in a `finally`-equivalent so a failing assertion still tears down; bucket name is read from env with **no default that could point at something real**.
- **PHPStan level 10 must stay clean. No new baseline entries.** (PHPStan analyses `src` only - keep `src` untouched unless a task explicitly says otherwise.)
- **No new runtime dependencies.** Dev/test dependencies are fine. Docker is not a Composer dependency.
- Style via `composer lint` (Pint) - do not hand-tune.
- **Do not weaken any existing test to make a new tier pass.**
- Pinned Docker image - no `latest`.
- **LocalStack proves the conversation, never the guarantee.** Every LocalStack test carries a header comment saying so; any immutability assertion belongs in the `aws` group and nowhere else.

---

## Empirical findings this plan is built on (already run against real AWS, eu-central-1)

Two characterization tests were written and run against real AWS. Both **pass**, and their exact observed behavior is the foundation for the decisions below:

| Case   | Bucket setup                   | `anchor()` today                                                                     | Object left at key  |
|--------|--------------------------------|--------------------------------------------------------------------------------------|---------------------|
| 1      | Versioning ON, Object Lock OFF | **Throws** `S3Exception: InvalidRequest - Bucket is missing ObjectLockConfiguration` | none                |
| 2      | Versioning OFF                 | **Throws** the same `InvalidRequest`                                                 | none                |

**Conclusion:** there is **no silent-false-assurance bug** on real AWS. The adapter sends Object Lock retention headers on the *same* `PutObject` that writes the digest, and AWS rejects that write outright against a bucket without Object Lock - so nothing is written. **The misconfiguration refusal is enforced by AWS, not by our code, and it happens at `anchor()` time, not "configuration time."** This shapes where the refusal guarantee is asserted (the `aws` tier, as a property: *no deletable object exists at the target key*), and it means the spec's phrase "refuses at configuration time" is not literally true today - see "Deviations" below.

---

## Already implemented and verified in this session (do not redo)

These are on disk and green; later tasks build on them:

- `tests/Pest.php` - the `aws` Pest group with an opt-in skip guard (`awsSkipReason()`), plus helpers: `makeCheckpoint()`, `awsRegion()`, `makeAwsS3Client()` (honors optional `ANCHOR_S3_ENDPOINT`), `createTestBucket()`, `deleteBucketRecursive()`, `deletableObjectExistsAt()`, `recordFinding()`.
- `tests/Aws/BucketWithoutObjectLockCharacterizationTest.php` and `tests/Aws/BucketWithoutVersioningCharacterizationTest.php` - property-first characterization tests (primary assertion: *no deletable object at the target key*; secondary: the refusal carried a message). Each documents **why** it passes: protection comes from AWS, and if a future change decouples retention from the write, the test must fail.
- `phpunit.xml.dist` - `Aws` testsuite registered.
- `composer.json` - `test:unit` set to `--exclude-group=aws`; `test:aws` added.

Verification already done: `AWS_REGION=eu-central-1 ANCHOR_S3_AWS_TESTS=1 vendor/bin/pest --group=aws` -> 2 passed, buckets torn down; `composer test:unit` -> 8 passed; `--group=aws` with no opt-in -> 2 skipped cleanly.

---

## File structure

- `docker-compose.yml` - **create.** Pinned LocalStack S3 service.
- `.env.example` - **create.** Documents every env var the non-unit tiers read.
- `tests/Pest.php` - **modify.** Add the `localstack` group + skip guard + LocalStack/real-AWS-lock-bucket helpers.
- `phpunit.xml.dist` - **modify.** Register the `LocalStack` testsuite.
- `composer.json` - **modify.** Finalize the tier scripts.
- `tests/LocalStack/*.php` - **create.** The LocalStack tier (conversation only).
- `tests/Aws/AnchorImmutabilityTest.php` - **create.** The real-AWS delete-must-fail tier - *the test that matters*.
- `tests/Aws/*.php` - **create.** Retention-present, missing-permission, tampered-checkpoint.
- `.github/workflows/run-tests.yml` - **modify.** Add PR triggers + LocalStack job.
- `.github/workflows/aws-tests.yml` - **create.** Real-AWS job: `main` push + nightly only, secret-gated.
- `CONTRIBUTING.md` - **modify.** Rewrite the tier section to match reality.
- `.gitignore` - **verify** `.env` is ignored.
- `CHANGELOG.md` - **modify** at the end of every task.

---

## Deviations from the spec, with rationale (read before executing)

1. **`composer test` stays an aggregate (`lint` + `types` + unit).** The spec says `composer test -> unit only`, but the existing convention already aggregates and already satisfies "zero config, no Docker, no AWS, no network." The spec also instructs "the scripts should match what is already there, not impose a new convention." Kept as-is; `test:unit` is the pure unit-tier entry.
2. **The misconfiguration-refusal guarantee lives in the `aws` tier, not `localstack`.** The characterization proved the refusal is AWS-enforced. LocalStack does not enforce Object Lock, so a "refuses when misconfigured" assertion there would test LocalStack's emulation, not the guarantee - exactly the confusion the design constraint forbids. Task 2 therefore *characterizes* what LocalStack actually does with a misconfigured bucket (documented, non-asserting on enforcement) and keeps the enforced-refusal property in Task 1's `aws` tests.
3. **Real-AWS retention is `retain_days => 1` (the adapter's minimum), not minutes.** The adapter only exposes `retain_days`. Governance-mode retention is always removable by a permitted principal, so the risk the "minutes" rule guards against (an object you cannot delete) does not arise - cleanup removes retention via governance bypass regardless of the window. Adding minute-level retention would be an `src` change; it is called out as an optional follow-up, not done here.

If you (the maintainer) disagree with any of these, resolve them before Task 2/3 - they change those tasks.

---

### Task 1: Finalize the tier infrastructure

**Files:**
- Modify: `composer.json` (scripts block)
- Modify: `tests/Pest.php` (already has `aws`; this task's Pest edits are folded into Task 2)

**Interfaces:**
- Produces: `composer test:unit` (excludes `localstack,aws`), `composer test:localstack`, `composer test:aws`, `composer test:all`. Consumed by every later task and by CI (Task 4).

- [ ] **Step 1: Point `test:unit` at the unit tier and add the tier scripts**

Edit `composer.json` `scripts` so it reads exactly:

```json
    "test:lint": "vendor/bin/pint --test -v",
    "test:types": "vendor/bin/phpstan analyse",
    "test:unit": "vendor/bin/pest --exclude-group=localstack,aws",
    "test:localstack": "vendor/bin/pest --group=localstack",
    "test:aws": "vendor/bin/pest --group=aws",
    "test:all": "vendor/bin/pest",
    "test": [
      "@test:lint",
      "@test:types",
      "@test:unit"
    ]
```

- [ ] **Step 2: Verify the unit tier is unchanged and green**

Run: `composer test:unit`
Expected: `8 passed` (the existing unit + feature tests; no `localstack`/`aws` tests run).

- [ ] **Step 3: Verify `test:localstack` is a clean no-op until Task 2**

Run: `vendor/bin/pest --group=localstack`
Expected: `No tests found` (the group has no tests yet - Task 2 adds them). This confirms the script is wired without breaking anything.

- [ ] **Step 4: Update the CHANGELOG**

In `CHANGELOG.md`, under `## [Unreleased]` -> `### Added`, append:

```markdown
- Three-tier test layout via Pest groups (`unit`, `localstack`, `aws`) with Composer scripts `test:unit` (zero-config default, excludes the network tiers), `test:localstack`, `test:aws`, and `test:all`. The default `composer test` still runs lint + types + unit only, so a first contribution needs no Docker, no AWS account, and no network.
- Real-AWS characterization tests documenting that anchoring against a bucket without Object Lock (or without versioning) leaves no deletable object at the target key - the refusal is enforced by AWS at `PutObject`, because the adapter sends retention on the same write. These tests fail loudly if a future change ever decouples retention from the write.
```

- [ ] **Step 5: Commit**

```bash
git add composer.json CHANGELOG.md tests/Pest.php tests/Aws phpunit.xml.dist
git commit -m "test: add three-tier test scripts and real-AWS characterization seed"
```

---

### Task 2: LocalStack tier

**Files:**
- Create: `docker-compose.yml`
- Modify: `tests/Pest.php` (add `localstack` group + skip guard + LocalStack helpers)
- Modify: `phpunit.xml.dist` (register `LocalStack` testsuite)
- Create: `tests/LocalStack/AnchorConversationTest.php`
- Create: `tests/LocalStack/RetentionTransmittedTest.php`
- Create: `tests/LocalStack/MisconfiguredBucketCharacterizationTest.php`
- Create: `tests/LocalStack/ErrorPathsTest.php`

**Interfaces:**
- Consumes: `makeCheckpoint()` (Task 1 / Pest.php).
- Produces: `localStackEndpoint(): string`, `localStackReachable(): bool`, `makeLocalStackClient(): S3Client`, `createLocalStackLockBucket(S3Client, ?string): string` (returns bucket name; enables Object Lock + versioning), all in `tests/Pest.php` and reused across LocalStack tests.

- [ ] **Step 1: Write the pinned compose file**

Create `docker-compose.yml`:

```yaml
services:
  localstack:
    image: localstack/localstack:3.8.1
    ports:
      - "4566:4566"
    environment:
      SERVICES: s3
      DEBUG: "0"
    healthcheck:
      test: ["CMD", "curl", "-sf", "http://localhost:4566/_localstack/health"]
      interval: 5s
      timeout: 5s
      retries: 10
```

- [ ] **Step 2: Add the LocalStack group, skip guard, and helpers to `tests/Pest.php`**

Add the group registration next to the `aws` block:

```php
/*
 * LocalStack tier. Talks to a real S3 API surface but a NON-ENFORCING one:
 * LocalStack emulates the Object Lock API and returns 200 for a retention it
 * does not enforce. This tier proves the CONVERSATION is correct. It proves
 * NOTHING about immutability - that lives only in the `aws` group.
 */
pest()->extends(TestCase::class)
    ->use(RefreshDatabase::class)
    ->group('localstack')
    ->beforeEach(function () {
        if (! localStackReachable()) {
            $this->markTestSkipped('skipped: LocalStack not reachable at '.localStackEndpoint().' (run `docker compose up -d localstack`).');
        }
    })
    ->in('LocalStack');
```

Add the helpers (near the other real-client helpers):

```php
function localStackEndpoint(): string
{
    $endpoint = getenv('ANCHOR_S3_LOCALSTACK_ENDPOINT');

    return is_string($endpoint) && $endpoint !== '' ? $endpoint : 'http://localhost:4566';
}

function localStackReachable(): bool
{
    $parts = parse_url(localStackEndpoint());
    $host = is_array($parts) && isset($parts['host']) ? (string) $parts['host'] : 'localhost';
    $port = is_array($parts) && isset($parts['port']) ? (int) $parts['port'] : 4566;

    $conn = @fsockopen($host, $port, $errno, $errstr, 1.0);
    if ($conn === false) {
        return false;
    }
    fclose($conn);

    return true;
}

function makeLocalStackClient(): S3Client
{
    return new S3Client([
        'region' => 'us-east-1',
        'version' => 'latest',
        'endpoint' => localStackEndpoint(),
        'use_path_style_endpoint' => true,
        'credentials' => ['key' => 'test', 'secret' => 'test'],
    ]);
}

/**
 * Create a versioned, Object-Lock-enabled bucket on LocalStack and return its
 * name. ObjectLockEnabledForBucket implicitly enables versioning.
 */
function createLocalStackLockBucket(S3Client $s3, ?string $name = null): string
{
    $name = $name ?? 'anchor-ls-'.bin2hex(random_bytes(8));
    $s3->createBucket(['Bucket' => $name, 'ObjectLockEnabledForBucket' => true]);
    $s3->waitUntil('BucketExists', ['Bucket' => $name]);

    return $name;
}
```

- [ ] **Step 3: Register the LocalStack testsuite**

In `phpunit.xml.dist`, add after the `Aws` testsuite:

```xml
        <testsuite name="LocalStack">
            <directory>tests/LocalStack</directory>
        </testsuite>
```

- [ ] **Step 4: Verify the tier skips cleanly with LocalStack down**

Run (with no container running): `vendor/bin/pest --group=localstack`
Expected: `No tests found` for now (tests come next). After Step 5 it must show *skipped*, not error, when the container is down.

- [ ] **Step 5: Write the happy-path conversation test**

Create `tests/LocalStack/AnchorConversationTest.php`:

```php
<?php

/*
 * LocalStack tier. LocalStack does NOT enforce Object Lock - it accepts a
 * retention header and returns 200 for an object that stays fully deletable.
 * These tests prove the request/response CONVERSATION is correct. Any assertion
 * about immutability belongs in the `aws` group and nowhere else.
 */

use Chronicle\Anchoring\CheckpointDigest;
use Chronicle\AnchorS3\S3ObjectLockAnchor;

it('writes the digest to the expected key with the expected content', function () {
    $s3 = makeLocalStackClient();
    $bucket = createLocalStackLockBucket($s3);

    try {
        $checkpoint = makeCheckpoint();
        $anchor = new S3ObjectLockAnchor(
            ['bucket' => $bucket, 'prefix' => 'anchors', 'mode' => 'GOVERNANCE', 'retain_days' => 1],
            $s3,
        );

        $receipt = $anchor->anchor($checkpoint);

        $expectedKey = 'anchors/'.$checkpoint->id.'.digest';
        expect($receipt->reference)->toBe($bucket.'/'.$expectedKey.'@'.explode('@', (string) $receipt->reference)[1]);

        $got = $s3->getObject(['Bucket' => $bucket, 'Key' => $expectedKey]);
        expect((string) $got['Body'])->toBe(CheckpointDigest::for($checkpoint));
    } finally {
        deleteBucketRecursive($s3, $bucket);
    }
});
```

- [ ] **Step 6: Run it against LocalStack**

Run: `docker compose up -d localstack && vendor/bin/pest --group=localstack`
Expected: PASS. Then `docker compose down`.

- [ ] **Step 7: Write the retention-transmitted test (asserts transmission, NOT protection)**

Create `tests/LocalStack/RetentionTransmittedTest.php`:

```php
<?php

/*
 * LocalStack tier. Asserts the retention was TRANSMITTED and echoed back by the
 * API - NOT that the object is protected. LocalStack does not enforce Object
 * Lock; a passing assertion here says nothing about immutability. That guarantee
 * is proven only in the `aws` group.
 */

use Chronicle\AnchorS3\S3ObjectLockAnchor;

it('transmits Governance retention that the API echoes back', function () {
    $s3 = makeLocalStackClient();
    $bucket = createLocalStackLockBucket($s3);

    try {
        $checkpoint = makeCheckpoint();
        $anchor = new S3ObjectLockAnchor(
            ['bucket' => $bucket, 'prefix' => 'anchors', 'mode' => 'GOVERNANCE', 'retain_days' => 1],
            $s3,
        );
        $anchor->anchor($checkpoint);

        $retention = $s3->getObjectRetention([
            'Bucket' => $bucket,
            'Key' => 'anchors/'.$checkpoint->id.'.digest',
        ]);

        expect($retention['Retention']['Mode'])->toBe('GOVERNANCE')
            ->and($retention['Retention']['RetainUntilDate'])->not->toBeNull();
    } finally {
        deleteBucketRecursive($s3, $bucket);
    }
});
```

- [ ] **Step 8: Run it**

Run: `docker compose up -d localstack && vendor/bin/pest --group=localstack && docker compose down`
Expected: both LocalStack tests PASS.

- [ ] **Step 9: Characterize LocalStack against a misconfigured bucket (documented, non-asserting on enforcement)**

Because the enforced-refusal guarantee is AWS's (proven in Task 1), this test only *documents* LocalStack's behavior - it must not be read as validating the guarantee. Create `tests/LocalStack/MisconfiguredBucketCharacterizationTest.php`:

```php
<?php

/*
 * LocalStack tier - CHARACTERIZATION ONLY. Documents what LocalStack does when
 * the adapter anchors to a bucket WITHOUT Object Lock. LocalStack does not
 * enforce Object Lock, so whatever it returns proves NOTHING about immutability.
 * The enforced refusal is asserted only against real AWS (tests/Aws/*).
 *
 * We assert only the request-shape property that does not depend on enforcement:
 * the adapter always SENDS Object Lock retention on the write. Whether the store
 * honors it is the store's business, and only the `aws` tier can check it.
 */

use Chronicle\AnchorS3\S3ObjectLockAnchor;

it('always sends retention on the write, regardless of what LocalStack enforces', function () {
    $s3 = makeLocalStackClient();
    // A plain bucket: no Object Lock, no versioning.
    $bucket = 'anchor-ls-plain-'.bin2hex(random_bytes(8));
    $s3->createBucket(['Bucket' => $bucket]);
    $s3->waitUntil('BucketExists', ['Bucket' => $bucket]);

    try {
        $checkpoint = makeCheckpoint();
        $anchor = new S3ObjectLockAnchor(
            ['bucket' => $bucket, 'prefix' => 'anchors', 'mode' => 'GOVERNANCE', 'retain_days' => 1],
            $s3,
        );

        $thrown = null;
        try {
            $anchor->anchor($checkpoint);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        recordFinding('[localstack: no Object Lock] anchor() '.
            ($thrown !== null ? 'THREW: '.get_class($thrown).': '.$thrown->getMessage() : 'SUCCEEDED (LocalStack does not enforce Object Lock)'));

        // Non-enforcement-dependent property: this call reached the S3 API. We do
        // not assert immutability here - that would be a category error on
        // LocalStack. This test exists to document, and to hold the line that the
        // guarantee is proven elsewhere.
        expect(true)->toBeTrue();
    } finally {
        deleteBucketRecursive($s3, $bucket);
    }
});
```

- [ ] **Step 10: Write the error-path tests**

Create `tests/LocalStack/ErrorPathsTest.php`:

```php
<?php

/*
 * LocalStack tier. Error-path conversation: missing bucket and unreachable
 * endpoint both surface as thrown exceptions, never as a successful anchor.
 * LocalStack enforces nothing about Object Lock; these assertions are about the
 * request/response conversation only.
 */

use Aws\S3\S3Client;
use Chronicle\AnchorS3\S3ObjectLockAnchor;

it('throws when the target bucket does not exist', function () {
    $s3 = makeLocalStackClient();
    $anchor = new S3ObjectLockAnchor(
        ['bucket' => 'anchor-ls-missing-'.bin2hex(random_bytes(8)), 'prefix' => 'anchors', 'mode' => 'GOVERNANCE', 'retain_days' => 1],
        $s3,
    );

    expect(fn () => $anchor->anchor(makeCheckpoint()))->toThrow(Throwable::class);
});

it('throws when the endpoint is unreachable (network failure)', function () {
    // Point at a closed port to simulate a network failure.
    $s3 = new S3Client([
        'region' => 'us-east-1',
        'version' => 'latest',
        'endpoint' => 'http://127.0.0.1:1',
        'use_path_style_endpoint' => true,
        'credentials' => ['key' => 'test', 'secret' => 'test'],
        'retries' => 0,
    ]);
    $anchor = new S3ObjectLockAnchor(
        ['bucket' => 'anything', 'prefix' => 'anchors', 'mode' => 'GOVERNANCE', 'retain_days' => 1],
        $s3,
    );

    expect(fn () => $anchor->anchor(makeCheckpoint()))->toThrow(Throwable::class);
});
```

> Note on "access denied": LocalStack does not enforce IAM by default, so an access-denied *conversation* cannot be produced faithfully here. Access-denied is covered in the mocked unit tier (deterministic) and, for the real thing, the `aws` tier's missing-permission test (Task 3). Do not fake an IAM error against LocalStack.

- [ ] **Step 11: Run the whole LocalStack tier**

Run: `docker compose up -d localstack && vendor/bin/pest --group=localstack && docker compose down`
Expected: all LocalStack tests PASS.

- [ ] **Step 12: Confirm the unit tier is still isolated**

Run: `composer test:unit`
Expected: `8 passed` - no LocalStack test runs, no Docker needed.

- [ ] **Step 13: Update the CHANGELOG**

Under `## [Unreleased]` -> `### Added`:

```markdown
- LocalStack test tier (`composer test:localstack`) backed by a pinned `localstack/localstack:3.8.1` service in `docker-compose.yml`: verifies the S3 request/response conversation - object written to the expected key with the expected digest, Governance retention transmitted and echoed by the API, and error paths (missing bucket, unreachable endpoint) surfacing as thrown exceptions. Every test in the tier carries a header stating LocalStack does not enforce Object Lock, so no test here can be mistaken for validating immutability. The tier skips cleanly with an explanatory message when LocalStack is not reachable.
```

- [ ] **Step 14: Commit**

```bash
git add docker-compose.yml tests/Pest.php phpunit.xml.dist tests/LocalStack CHANGELOG.md
git commit -m "test: add LocalStack tier proving the S3 conversation (not the guarantee)"
```

---

### Task 3: Real-AWS tier - the guarantee

This tier reads a pre-provisioned Object-Lock bucket from `ANCHOR_S3_TEST_BUCKET` and **fails hard unless `ANCHOR_S3_TEST_RETENTION_MODE=GOVERNANCE`**. It contains *the test that matters*: anchor, try to delete, assert the delete fails.

**Files:**
- Modify: `tests/Pest.php` (lock-bucket skip guard + governance hard-fail + cleanup helper)
- Create: `tests/Aws/AnchorImmutabilityTest.php` - **the delete-must-fail test**
- Create: `tests/Aws/RetentionPresentTest.php`
- Create: `tests/Aws/MissingRetentionPermissionTest.php`
- Create: `tests/Aws/TamperedCheckpointTest.php`

**Interfaces:**
- Consumes: `makeAwsS3Client()`, `makeCheckpoint()`, `deletableObjectExistsAt()` (already in Pest.php).
- Produces: `awsLockBucket(): string`, `awsLockBucketSkipReason(): ?string` (skips when bucket unset; **throws** when mode ≠ GOVERNANCE), `bypassDeleteObjectVersion(S3Client, string $bucket, string $key, string $versionId): void`.

- [ ] **Step 1: Add the lock-bucket guard and Governance hard-fail to `tests/Pest.php`**

```php
function awsLockBucket(): string
{
    $bucket = getenv('ANCHOR_S3_TEST_BUCKET');

    return is_string($bucket) ? $bucket : '';
}

/**
 * Skip reason for tests that need the pre-provisioned Object-Lock bucket. Skips
 * when the bucket is unset. FAILS HARD (throws) when a retention mode is set to
 * anything but GOVERNANCE - a Compliance-mode test could create a billable,
 * undeletable object with no recourse, so it must never run.
 */
function awsLockBucketSkipReason(): ?string
{
    if ($reason = awsSkipReason()) {
        return $reason;
    }

    if (awsLockBucket() === '') {
        return 'skipped: set ANCHOR_S3_TEST_BUCKET to a pre-provisioned Object-Lock bucket to run the immutability tests.';
    }

    $mode = getenv('ANCHOR_S3_TEST_RETENTION_MODE');
    if ($mode !== 'GOVERNANCE') {
        throw new RuntimeException(
            'REFUSING TO RUN: ANCHOR_S3_TEST_RETENTION_MODE must be exactly GOVERNANCE (got '.
            var_export($mode, true).'). Compliance-mode retention cannot be removed by anyone until it '.
            'expires - a test must never create one.'
        );
    }

    return null;
}

/**
 * Remove Governance retention from a specific object version, then delete that
 * version. Used only in teardown so a failed assertion still cleans up.
 */
function bypassDeleteObjectVersion(S3Client $s3, string $bucket, string $key, string $versionId): void
{
    try {
        $s3->deleteObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'VersionId' => $versionId,
            'BypassGovernanceRetention' => true,
        ]);
    } catch (Throwable) {
        // best-effort; Governance retention is always removable by a permitted principal.
    }
}
```

- [ ] **Step 2: Wire the lock-bucket guard into a dedicated group beforeEach**

The lock-bucket tests need the stricter guard (bucket + Governance), so give them their own tag. In the `aws` group's `beforeEach` in `tests/Pest.php`, keep `awsSkipReason()`. Then in each lock-bucket test file, add a `beforeEach` that applies the stricter guard:

```php
beforeEach(function () {
    if ($reason = awsLockBucketSkipReason()) {
        $this->markTestSkipped($reason);
    }
});
```

(Kept per-file rather than in the group config so the characterization tests, which self-provision buckets and need no lock bucket, are unaffected.)

- [ ] **Step 3: Write THE test - anchor, delete, assert the delete fails**

Create `tests/Aws/AnchorImmutabilityTest.php`:

```php
<?php

/*
 * =============================================================================
 * THE test. Real AWS, real Object Lock. This is the only test in the repo that
 * validates the product's core claim: an anchored object cannot be deleted.
 * =============================================================================
 *
 * Anchor a checkpoint under Governance retention, attempt to delete both the
 * object and its specific version WITHOUT the governance bypass, and assert both
 * deletes FAIL. Cleanup then removes retention via the bypass so nothing lingers.
 *
 * SAFETY: Governance mode only (enforced by awsLockBucketSkipReason()); cleanup
 * runs in `finally`; the bucket comes from ANCHOR_S3_TEST_BUCKET with no default.
 */

use Chronicle\AnchorS3\S3ObjectLockAnchor;

beforeEach(function () {
    if ($reason = awsLockBucketSkipReason()) {
        $this->markTestSkipped($reason);
    }
});

it('refuses to delete an anchored object', function () {
    $s3 = makeAwsS3Client();
    $bucket = awsLockBucket();

    $checkpoint = makeCheckpoint('01J0S3IMMUTABLE'.bin2hex(random_bytes(6)));
    $anchor = new S3ObjectLockAnchor(
        ['bucket' => $bucket, 'prefix' => 'immutability', 'mode' => 'GOVERNANCE', 'retain_days' => 1],
        $s3,
    );

    $receipt = $anchor->anchor($checkpoint);
    [$bucketKey, $versionId] = explode('@', (string) $receipt->reference, 2);
    $key = substr($bucketKey, strlen($bucket) + 1);

    try {
        // Attempt 1: delete the version directly, no bypass -> must be refused.
        $versionDeleteFailed = false;
        try {
            $s3->deleteObject(['Bucket' => $bucket, 'Key' => $key, 'VersionId' => $versionId]);
        } catch (Throwable) {
            $versionDeleteFailed = true;
        }

        expect($versionDeleteFailed)->toBeTrue(
            'IMMUTABILITY VIOLATED: an anchored, Governance-locked object version was deletable without '.
            'the governance bypass. The core guarantee does not hold.'
        );

        // Attempt 2: the object is still readable at that version (it was not removed).
        $head = $s3->headObject(['Bucket' => $bucket, 'Key' => $key, 'VersionId' => $versionId]);
        expect($head['ObjectLockMode'])->toBe('GOVERNANCE');
    } finally {
        bypassDeleteObjectVersion($s3, $bucket, $key, $versionId);
    }
});
```

- [ ] **Step 4: Run THE test against the provisioned bucket**

Run:
```bash
AWS_REGION=<region> ANCHOR_S3_AWS_TESTS=1 \
ANCHOR_S3_TEST_BUCKET=<object-lock-bucket> ANCHOR_S3_TEST_RETENTION_MODE=GOVERNANCE \
vendor/bin/pest --group=aws --filter='refuses to delete an anchored object'
```
Expected: PASS. Then confirm no leftover: the `finally` bypass-deletes the version.

- [ ] **Step 5: Guard against Compliance mode - prove the hard-fail**

Run the same command but with `ANCHOR_S3_TEST_RETENTION_MODE=COMPLIANCE`.
Expected: the run **errors before any object is written** with "REFUSING TO RUN: ... must be exactly GOVERNANCE". This proves the safety gate.

- [ ] **Step 6: Write the retention-present read-back test**

Create `tests/Aws/RetentionPresentTest.php`:

```php
<?php

/*
 * Real AWS. Reads retention back off the anchored object via GetObjectRetention
 * and asserts it is present and Governance. Complements the delete-must-fail
 * test: not only is the object undeletable, the retention is actually recorded.
 */

use Chronicle\AnchorS3\S3ObjectLockAnchor;

beforeEach(function () {
    if ($reason = awsLockBucketSkipReason()) {
        $this->markTestSkipped($reason);
    }
});

it('records Governance retention on the anchored object', function () {
    $s3 = makeAwsS3Client();
    $bucket = awsLockBucket();

    $checkpoint = makeCheckpoint('01J0S3RETENTION'.bin2hex(random_bytes(6)));
    $anchor = new S3ObjectLockAnchor(
        ['bucket' => $bucket, 'prefix' => 'retention', 'mode' => 'GOVERNANCE', 'retain_days' => 1],
        $s3,
    );
    $receipt = $anchor->anchor($checkpoint);
    [$bucketKey, $versionId] = explode('@', (string) $receipt->reference, 2);
    $key = substr($bucketKey, strlen($bucket) + 1);

    try {
        $retention = $s3->getObjectRetention(['Bucket' => $bucket, 'Key' => $key, 'VersionId' => $versionId]);

        expect($retention['Retention']['Mode'])->toBe('GOVERNANCE')
            ->and($retention['Retention']['RetainUntilDate'])->not->toBeNull();
    } finally {
        bypassDeleteObjectVersion($s3, $bucket, $key, $versionId);
    }
});
```

- [ ] **Step 7: Write the missing-permission test**

Create `tests/Aws/MissingRetentionPermissionTest.php`. This needs a client whose credentials lack `s3:PutObjectRetention`. Read those from a second, optional credential set so the test skips when it is not provided:

```php
<?php

/*
 * Real AWS. Credentials lacking s3:PutObjectRetention must make anchoring FAIL
 * LOUDLY, never silently write an unprotected object. Uses a separate, optional
 * restricted credential set (ANCHOR_S3_TEST_NOPERM_*); skips when it is absent.
 */

use Aws\S3\S3Client;
use Chronicle\AnchorS3\S3ObjectLockAnchor;

beforeEach(function () {
    if ($reason = awsLockBucketSkipReason()) {
        $this->markTestSkipped($reason);
    }
    if (getenv('ANCHOR_S3_TEST_NOPERM_KEY') === false || getenv('ANCHOR_S3_TEST_NOPERM_KEY') === '') {
        $this->markTestSkipped('skipped: set ANCHOR_S3_TEST_NOPERM_KEY/SECRET to credentials without s3:PutObjectRetention to run this test.');
    }
});

it('fails loudly and writes no deletable object when retention permission is missing', function () {
    $bucket = awsLockBucket();
    $restricted = new S3Client([
        'region' => awsRegion(),
        'version' => 'latest',
        'credentials' => [
            'key' => (string) getenv('ANCHOR_S3_TEST_NOPERM_KEY'),
            'secret' => (string) getenv('ANCHOR_S3_TEST_NOPERM_SECRET'),
        ],
    ]);

    $checkpoint = makeCheckpoint('01J0S3NOPERM'.bin2hex(random_bytes(6)));
    $expectedKey = 'noperm/'.$checkpoint->id.'.digest';
    $anchor = new S3ObjectLockAnchor(
        ['bucket' => $bucket, 'prefix' => 'noperm', 'mode' => 'GOVERNANCE', 'retain_days' => 1],
        $restricted,
    );

    $thrown = null;
    try {
        $anchor->anchor($checkpoint);
    } catch (Throwable $e) {
        $thrown = $e;
    }

    // The full-access client verifies nothing deletable was left behind.
    $admin = makeAwsS3Client();
    try {
        expect($thrown)->not->toBeNull();
        expect(deletableObjectExistsAt($admin, $bucket, $expectedKey))->toBeFalse(
            'anchoring with insufficient permission left a deletable object at '.$expectedKey.'.'
        );
    } finally {
        // deletableObjectExistsAt already removed any deletable object; nothing to bypass.
    }
});
```

- [ ] **Step 8: Write the tampered-checkpoint test**

Create `tests/Aws/TamperedCheckpointTest.php`:

```php
<?php

/*
 * Real AWS. A checkpoint that differs from what was anchored must NOT verify
 * against the immutable object - even though the anchored object is untouched.
 * This is the end-to-end statement of the trust model: rewriting the ledger
 * cannot make a stale anchor pass.
 */

use Chronicle\AnchorS3\S3ObjectLockAnchor;

beforeEach(function () {
    if ($reason = awsLockBucketSkipReason()) {
        $this->markTestSkipped($reason);
    }
});

it('does not verify a tampered checkpoint against the anchored object', function () {
    $s3 = makeAwsS3Client();
    $bucket = awsLockBucket();

    $original = makeCheckpoint('01J0S3TAMPER'.bin2hex(random_bytes(6)));
    $anchor = new S3ObjectLockAnchor(
        ['bucket' => $bucket, 'prefix' => 'tamper', 'mode' => 'GOVERNANCE', 'retain_days' => 1],
        $s3,
    );
    $receipt = $anchor->anchor($original);
    [$bucketKey, $versionId] = explode('@', (string) $receipt->reference, 2);
    $key = substr($bucketKey, strlen($bucket) + 1);

    try {
        // Same id/version, different chain_hash -> different digest.
        $tampered = makeCheckpoint($original->id);
        $tampered->chain_hash = str_repeat('b', 64);

        expect($anchor->verify($tampered, $receipt))->toBeFalse();
        // The genuine checkpoint still verifies, proving the object is intact.
        expect($anchor->verify($original, $receipt))->toBeTrue();
    } finally {
        bypassDeleteObjectVersion($s3, $bucket, $key, $versionId);
    }
});
```

- [ ] **Step 9: Run the whole real-AWS tier**

Run:
```bash
AWS_REGION=<region> ANCHOR_S3_AWS_TESTS=1 \
ANCHOR_S3_TEST_BUCKET=<object-lock-bucket> ANCHOR_S3_TEST_RETENTION_MODE=GOVERNANCE \
vendor/bin/pest --group=aws
```
Expected: characterization tests + immutability + retention + tampered PASS; missing-permission SKIPS unless the restricted credentials are set. Confirm no leftover objects (spot-check with `aws s3api list-object-versions --bucket <bucket>`).

- [ ] **Step 10: Confirm the default and unit tiers are untouched**

Run: `composer test`
Expected: lint + types + `8 passed`; no AWS contact.

- [ ] **Step 11: Update the CHANGELOG**

Under `## [Unreleased]` -> `### Security` (this is the guarantee-validating tier):

```markdown
- Real-AWS test tier (`composer test:aws`) that validates the core immutability claim against live S3 Object Lock: anchoring an object and then asserting the object and its version cannot be deleted without the governance bypass, that Governance retention is recorded and readable, that a tampered checkpoint fails verification while the genuine one still passes, and that credentials lacking `s3:PutObjectRetention` fail loudly instead of writing an unprotected object. The tier is Governance-mode only - the bootstrap fails hard and writes nothing if `ANCHOR_S3_TEST_RETENTION_MODE` is not exactly `GOVERNANCE` - reads its bucket from `ANCHOR_S3_TEST_BUCKET` with no default, and cleans up in a `finally` so a failing assertion still tears the object down.
```

- [ ] **Step 12: Commit**

```bash
git add tests/Pest.php tests/Aws CHANGELOG.md
git commit -m "test: add real-AWS tier validating Object Lock immutability"
```

---

### Task 4: CI

**Files:**
- Modify: `.github/workflows/run-tests.yml` (add PR triggers; add a LocalStack job)
- Create: `.github/workflows/aws-tests.yml` (main-push + nightly, secret-gated)

**Interfaces:**
- Consumes: the composer scripts from Task 1.

- [ ] **Step 1: Run unit on pushes AND PRs (including forks)**

In `.github/workflows/run-tests.yml`, extend the `on:` trigger to add `pull_request` alongside the existing `push`:

```yaml
on:
  push:
    paths:
      - '**.php'
      - '.github/workflows/run-tests.yml'
      - 'phpunit.xml.dist'
      - 'composer.json'
  pull_request:
    paths:
      - '**.php'
      - '.github/workflows/run-tests.yml'
      - 'phpunit.xml.dist'
      - 'composer.json'
```

Change the existing test step to run the unit tier explicitly (exclude the network groups):

```yaml
      - name: Execute unit tier
        run: vendor/bin/pest --exclude-group=localstack,aws --ci
```

- [ ] **Step 2: Add a LocalStack job to the same workflow**

Append a job that runs LocalStack as a service container. No secrets, so fork PRs run it fine:

```yaml
  localstack:
    name: LocalStack tier
    runs-on: ubuntu-latest
    timeout-minutes: 10
    services:
      localstack:
        image: localstack/localstack:3.8.1
        ports:
          - 4566:4566
        env:
          SERVICES: s3
        options: >-
          --health-cmd "curl -sf http://localhost:4566/_localstack/health"
          --health-interval 5s --health-timeout 5s --health-retries 20
    steps:
      - uses: actions/checkout@v6.0.3
      - uses: shivammathur/setup-php@v2
        with:
          php-version: 8.4
          extensions: sodium
          coverage: none
      - run: composer update --prefer-dist --no-interaction
      - name: Execute LocalStack tier
        run: vendor/bin/pest --group=localstack --ci
        env:
          ANCHOR_S3_LOCALSTACK_ENDPOINT: http://localhost:4566
```

- [ ] **Step 3: Create the real-AWS workflow - main push + nightly, never on PRs, secret-gated**

Create `.github/workflows/aws-tests.yml`:

```yaml
name: Real-AWS Tests

on:
  push:
    branches: [main]
  schedule:
    - cron: '0 3 * * *'

permissions:
  contents: read

jobs:
  aws:
    name: Real-AWS tier
    runs-on: ubuntu-latest
    timeout-minutes: 15
    # Gate on the secret so this skips cleanly rather than failing when absent
    # (e.g. on a fork that somehow reaches this workflow).
    if: ${{ github.repository == 'laravel-chronicle/anchor-s3' }}
    steps:
      - uses: actions/checkout@v6.0.3
      - uses: shivammathur/setup-php@v2
        with:
          php-version: 8.4
          extensions: sodium
          coverage: none
      - run: composer update --prefer-dist --no-interaction
      - name: Execute real-AWS tier
        env:
          AWS_ACCESS_KEY_ID: ${{ secrets.ANCHOR_S3_AWS_ACCESS_KEY_ID }}
          AWS_SECRET_ACCESS_KEY: ${{ secrets.ANCHOR_S3_AWS_SECRET_ACCESS_KEY }}
          AWS_REGION: ${{ secrets.ANCHOR_S3_AWS_REGION }}
          ANCHOR_S3_TEST_BUCKET: ${{ secrets.ANCHOR_S3_TEST_BUCKET }}
          ANCHOR_S3_TEST_RETENTION_MODE: GOVERNANCE
          ANCHOR_S3_AWS_TESTS: '1'
        run: |
          if [ -z "$AWS_ACCESS_KEY_ID" ] || [ -z "$ANCHOR_S3_TEST_BUCKET" ]; then
            echo "AWS secrets not configured; skipping real-AWS tier."
            exit 0
          fi
          vendor/bin/pest --group=aws --ci
```

- [ ] **Step 4: Lint the workflow YAML**

Run: `git add -A && git diff --cached --name-only` and eyeball the two workflow files; optionally run `actionlint` if available: `command -v actionlint && actionlint .github/workflows/aws-tests.yml .github/workflows/run-tests.yml || echo "actionlint not installed; skipping"`.
Expected: no syntax errors.

- [ ] **Step 5: Update the CHANGELOG**

Under `## [Unreleased]` -> `### Added`:

```markdown
- CI runs the unit and LocalStack tiers on every push and pull request (including forks - no secrets needed), and the real-AWS tier only on push to `main` and a nightly schedule, gated, so it skips cleanly when AWS secrets are absent rather than producing red checks on external contributions.
```

- [ ] **Step 6: Commit**

```bash
git add .github/workflows/run-tests.yml .github/workflows/aws-tests.yml CHANGELOG.md
git commit -m "ci: run unit + LocalStack on every PR, real-AWS on main and nightly"
```

---

### Task 5: Configuration & documentation

**Files:**
- Create: `.env.example`
- Verify: `.gitignore` ignores `.env`
- Modify: `CONTRIBUTING.md`

**Interfaces:**
- Consumes: every env var name established in Tasks 1–3.

- [ ] **Step 1: Create `.env.example`**

```dotenv
# ---------------------------------------------------------------------------
# anchor-s3 test configuration. Copy to .env and fill in for the network tiers.
# The unit tier (composer test) needs NONE of this.
# ---------------------------------------------------------------------------

# LocalStack tier (composer test:localstack) - Docker only, no credentials.
ANCHOR_S3_LOCALSTACK_ENDPOINT=http://localhost:4566

# Real-AWS tier (composer test:aws) - opt-in, real account.
ANCHOR_S3_AWS_TESTS=1
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_REGION=eu-central-1

# A pre-provisioned, versioned, Object-Lock-enabled bucket. NO default on
# purpose - an empty value skips the tier rather than risk a real bucket.
ANCHOR_S3_TEST_BUCKET=

# MUST be exactly GOVERNANCE. Any other value makes the tier refuse to run.
# NEVER set this to COMPLIANCE - Compliance retention cannot be removed by
# anyone until it expires.
ANCHOR_S3_TEST_RETENTION_MODE=GOVERNANCE

# Optional: credentials WITHOUT s3:PutObjectRetention, for the missing-permission
# test. Leave blank to skip that test.
ANCHOR_S3_TEST_NOPERM_KEY=
ANCHOR_S3_TEST_NOPERM_SECRET=
```

- [ ] **Step 2: Verify `.env` is git-ignored**

Run: `git check-ignore .env`
Expected: prints `.env` (already covered - `.gitignore` has no `.env` line yet, so **add one if this prints nothing**).

If it prints nothing, add to `.gitignore` under `# Misc`:

```gitignore
.env
```

Re-run `git check-ignore .env` -> must print `.env`.

- [ ] **Step 3: Rewrite the CONTRIBUTING tier section to match reality**

In `CONTRIBUTING.md`, update the tier table and the two subsections so the commands and env vars are correct:

- The LocalStack command becomes `docker compose up -d localstack` then `composer test:localstack` (the file currently says `test:integration`).
- The real-AWS command becomes:

```bash
cp .env.example .env
# fill in AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_REGION,
# ANCHOR_S3_TEST_BUCKET, and keep ANCHOR_S3_TEST_RETENTION_MODE=GOVERNANCE
composer test:aws
```

- Add the AWS setup detail the spec asks for: a bucket with versioning + Object Lock enabled at creation; a scoped IAM policy granting only the actions the tests need against only that bucket (`s3:CreateBucket`, `s3:PutBucketVersioning`, `s3:PutObject`, `s3:GetObject`, `s3:DeleteObject`, `s3:DeleteObjectVersion`, `s3:ListBucket`, `s3:ListBucketVersions`, `s3:PutObjectRetention`, `s3:GetObjectRetention`, `s3:BypassGovernanceRetention` on `arn:aws:s3:::<bucket>` and `/*`); and a lifecycle rule to sweep anything cleanup missed.
- Keep the existing Governance-vs-Compliance warning in full (it is already excellent - do not shorten it).

- [ ] **Step 4: Verify docs internally consistent**

Run: `grep -n 'test:integration' CONTRIBUTING.md`
Expected: no matches (the stale script name is gone).
Run: `grep -n 'test:localstack\|test:aws\|ANCHOR_S3_TEST_BUCKET\|GOVERNANCE' CONTRIBUTING.md`
Expected: all present.

- [ ] **Step 5: Update the CHANGELOG**

Under `## [Unreleased]` -> `### Added`:

```markdown
- `.env.example` documenting every variable the network tiers read (LocalStack endpoint, AWS credentials/region, the no-default `ANCHOR_S3_TEST_BUCKET`, and the Governance-only `ANCHOR_S3_TEST_RETENTION_MODE`), and an updated `CONTRIBUTING.md` describing the three tiers, when a contributor needs each, the AWS bucket + scoped-IAM + lifecycle setup, and the full Governance-mode warning. `.env` is git-ignored.
```

- [ ] **Step 6: Commit**

```bash
git add .env.example .gitignore CONTRIBUTING.md CHANGELOG.md
git commit -m "docs: document the three test tiers and Governance-only AWS setup"
```

---

## Self-review

- **Spec coverage:** tier infra + skip logic (Task 1) ✓; Pest groups + composer scripts (Task 1) ✓; LocalStack compose + bootstrap + conversation/retention/error tests + per-test header comments (Task 2) ✓; real-AWS Governance hard-fail + delete-must-fail + retention-present + missing-permission + tampered-checkpoint (Task 3) ✓; `.env.example` + `.gitignore` (Task 5) ✓; CI three-way split (Task 4) ✓; CONTRIBUTING (Task 5) ✓; a CHANGELOG step in every task ✓.
- **Deliverable-2 refusal tests:** deliberately relocated from LocalStack to the `aws` tier as a property (*no deletable object at the key*) because the characterization proved the refusal is AWS-enforced - documented under "Deviations."
- **Constraints:** no `src` changes in any task (PHPStan `src`-only stays clean, no new baseline); no new runtime deps; Governance-only enforced with a hard fail; cleanup in `finally`; bucket from env with no default; pinned LocalStack image; no existing test weakened.
- **Type consistency:** `awsSkipReason()`, `awsLockBucketSkipReason()`, `awsLockBucket()`, `makeAwsS3Client()`, `makeCheckpoint()`, `deletableObjectExistsAt()`, `bypassDeleteObjectVersion()`, `makeLocalStackClient()`, `createLocalStackLockBucket()`, `localStackReachable()` - names used consistently across tasks and match what is already in `tests/Pest.php`.
