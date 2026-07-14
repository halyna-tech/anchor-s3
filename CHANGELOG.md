# Changelog

All notable changes to `laravel-chronicle/anchor-s3` are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Package scaffolding: Composer metadata, PHPStan (level 10) + Pest/testbench tooling (`TestCase` booting core + the adapter, with shared `ref()` / `makeMockS3Client()` helpers), and an `AnchorS3ServiceProvider` that binds a default `Aws\S3\S3Client` singleton from environment configuration. Depends on `laravel-chronicle/core` and `aws/aws-sdk-php` only.
- `S3ObjectLockAnchor` implementing `Chronicle\Contracts\AnchorProvider` (`name()` = `s3-object-lock`), with `__construct(array $config, Aws\S3\S3Client $client)` validating a required `bucket` and accepting `prefix` / `mode` (COMPLIANCE default) / `retain_days` (3650 default).
- `S3ObjectLockAnchor::anchor()` writes `CheckpointDigest::for($checkpoint)` to `{prefix}/{checkpoint-id}.digest` via `PutObject` with `ObjectLockMode` + `ObjectLockRetainUntilDate`, returning a receipt whose `reference` is `bucket/key@versionId` and whose `proof` is the object ETag.
- `S3ObjectLockAnchor::verify()` re-reads the exact object version named in the receipt (`GetObject`), confirms the stored bytes equal the recomputed `CheckpointDigest`, confirms Object Lock metadata is present, and confirms the ETag matches the receipt proof. Network errors / missing objects verify as `false`.
- README documenting Object-Lock bucket setup, the minimal IAM action set (no delete), provider registration in `chronicle.anchoring.providers`, and the anchor/verify trust model.
- Three-tier test layout via Pest groups (`unit`, `localstack`, `aws`) with Composer scripts `test:unit` (zero-config default, excludes the network tiers), `test:localstack`, `test:aws`, and `test:all`. The default `composer test` still runs lint + types + unit only, so a first contribution needs no Docker, no AWS account, and no network.
- Real-AWS characterization tests documenting that anchoring against a bucket without Object Lock (or without versioning) leaves no deletable object at the target key - the refusal is enforced by AWS at `PutObject`, because the adapter sends retention on the same write. These tests fail loudly if a future change ever decouples retention from the write.

---

### Security

- End-to-end coverage proves an S3-Object-Lock-anchored checkpoint passes `chronicle:verify --anchors` against a mocked S3 client, and (via the unit suite) that a rewritten checkpoint whose recomputed digest no longer matches the immutable object FAILS verification — external WORM storage defeats a full in-database compromise.
