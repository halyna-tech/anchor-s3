# Chronicle S3 Object Lock Anchor

[![Tests](https://github.com/laravel-chronicle/anchor-s3/actions/workflows/run-tests.yml/badge.svg)](https://github.com/laravel-chronicle/anchor-s3/actions/workflows/run-tests.yml)
[![PHPStan](https://github.com/laravel-chronicle/anchor-s3/actions/workflows/phpstan.yml/badge.svg)](https://github.com/laravel-chronicle/anchor-s3/actions/workflows/phpstan.yml)
[![Latest Version](https://img.shields.io/packagist/v/laravel-chronicle/anchor-s3.svg)](https://packagist.org/packages/laravel-chronicle/anchor-s3)
[![Total Downloads](https://img.shields.io/packagist/dt/laravel-chronicle/anchor-s3.svg)](https://packagist.org/packages/laravel-chronicle/anchor-s3)
[![License](https://img.shields.io/packagist/l/laravel-chronicle/anchor-s3.svg)](LICENSE)

An [S3 Object Lock](https://docs.aws.amazon.com/AmazonS3/latest/userguide/object-lock.html) (WORM) anchoring adapter for [`laravel-chronicle/core`](https://github.com/laravel-chronicle/core).

It writes each checkpoint's digest - `sha256(id . chain_hash . created_at)` - to a **locked, versioned** S3 object in an independent trust domain. Even an attacker who rewrites the ledger and re-signs every checkpoint with a valid key still cannot alter the locked object, so `chronicle:verify --anchors` fails on the tampered checkpoint. The proof lives somewhere your application cannot reach, and therefore cannot quietly rewrite.

## Installation

```bash
composer require laravel-chronicle/anchor-s3
```

The package auto-registers `AnchorS3ServiceProvider`, which binds a default `Aws\S3\S3Client` from `AWS_DEFAULT_REGION` and the standard AWS credential chain.

## Choosing a retention mode

Object Lock has two retention modes, and the choice has consequences that are easy to underestimate. Read this before you create a bucket.

- **GOVERNANCE** - a principal holding `s3:BypassGovernanceRetention` can shorten or remove retention. This is the mode to start with, and the only mode you should use while you are evaluating the package or running anything you might want to clean up.
- **COMPLIANCE** - retention cannot be shortened or removed by *anyone*, including the AWS account root user, until it expires. This is what a regulated, production deployment wants: it is the mode that makes the WORM guarantee absolute. It is also, for exactly that reason, a mode you must not point at a test or throwaway bucket. An object written with a ten-year COMPLIANCE retention is billable and undeletable for ten years, with no support path that reverses it.

**Start with GOVERNANCE and short retention. Move to COMPLIANCE deliberately, once you know the bucket is the one you mean.** Note that the adapter defaults to `GOVERNANCE` when `mode` is unset or unrecognized.

## Bucket setup (one-time)

Object Lock requires a **versioned** bucket created with Object Lock enabled. The example below uses GOVERNANCE, which is the right choice for getting started:

```bash
aws s3api create-bucket \
  --bucket my-chronicle-anchors \
  --object-lock-enabled-for-bucket \
  --region eu-west-1 \
  --create-bucket-configuration LocationConstraint=eu-west-1
```

You can set a default retention rule on the bucket, but the adapter applies per-object retention on every write, so this is optional. If you do set one for a production bucket in COMPLIANCE mode, be certain the bucket is the one you intend:

```bash
# Production only. COMPLIANCE retention on these objects cannot be removed by anyone
# until it expires. Do not run this against a bucket you might want to delete.
aws s3api put-object-lock-configuration \
  --bucket my-chronicle-anchors \
  --object-lock-configuration '{"ObjectLockEnabled":"Enabled","Rule":{"DefaultRetention":{"Mode":"COMPLIANCE","Days":3650}}}'
```

## Registration

Enable anchoring and register the provider in your published `config/chronicle.php`:

```php
'anchoring' => [
    'enabled' => true,
    'providers' => [
        's3-object-lock' => [
            'provider' => \Chronicle\AnchorS3\S3ObjectLockAnchor::class,
            'bucket' => env('CHRONICLE_S3_ANCHOR_BUCKET'),
            'prefix' => 'chronicle/anchors',   // optional (default 'chronicle/anchors')
            'mode' => 'GOVERNANCE',            // 'GOVERNANCE' or 'COMPLIANCE'; unset defaults to GOVERNANCE
            'retain_days' => 3650,             // optional; defaults to 3650
        ],
    ],
],
```

New checkpoints are then anchored automatically. You can also anchor on demand and attest stored anchors with the Chronicle Artisan commands - see the [documentation](https://laravel-chronicle.dev/docs/artisan-commands) for the current command set, since those commands live in the core package.

## Required IAM actions

On the anchor bucket (both the bucket ARN and `arn:aws:s3:::my-chronicle-anchors/*`):

| Action                  | Used by    | Why                                    |
|-------------------------|------------|----------------------------------------|
| `s3:PutObject`          | `anchor()` | Write the digest object                |
| `s3:PutObjectRetention` | `anchor()` | Apply per-object Object Lock retention |
| `s3:GetObject`          | `verify()` | Re-read the exact object version       |
| `s3:GetObjectVersion`   | `verify()` | Read by `VersionId`                    |
| `s3:GetObjectRetention` | `verify()` | Confirm lock metadata is present       |

Grant **no** `s3:DeleteObject*`. Anchors are write-once by design, and withholding delete permission is a second layer of defense behind the lock itself.

## How it works

- `anchor()` issues a single `PutObject` carrying the digest plus `ObjectLockMode` and `ObjectLockRetainUntilDate`. The receipt records `reference = "bucket/key@versionId"` and `proof = ETag`.
- `verify()` reads that exact version and passes only if the stored bytes equal the recomputed digest, lock metadata is present, and the ETag matches the receipt.

Because the retention headers travel on the same `PutObject` that writes the object, S3 rejects the write outright against a bucket without Object Lock - so a misconfigured bucket fails loudly and leaves nothing behind, rather than silently storing a deletable object that could be mistaken for a real anchor.

`verify()` performs one S3 read; it is deliberately **not** offline, unlike the core RFC 3161 anchor. That single network read is the price of an independent, account-isolated trust domain, and it is the point of this adapter.

## Testing

The suite has three tiers. Only the first runs by default, and it needs nothing installed beyond the package's own dev dependencies.

```bash
composer test:unit       # unit tier: mocked S3, no Docker, no AWS, no network
composer test:localstack # LocalStack tier: needs Docker
composer test:aws        # real-AWS tier: opt-in, needs an AWS account
composer test:types      # PHPStan level 10
```

- **Unit** - verifies the adapter forms the right requests and handles responses and errors. This is what a first-time contributor runs; `composer install && composer test:unit` should be green with no further setup.
- **LocalStack** - runs the adapter against a real S3 API surface to catch request-shape and SDK regressions. Bring it up with `docker compose up -d localstack`. Note that LocalStack emulates the Object Lock *API* but does not *enforce* immutability, so this tier proves the conversation is correct, not that objects are actually locked.
- **Real AWS** - the only tier that proves the core guarantee: it anchors an object and confirms the object cannot be deleted. It is **GOVERNANCE-only by hard rule** - the suite refuses to run if the configured retention mode is anything else, precisely so a test can never create a COMPLIANCE object. See [CONTRIBUTING.md](CONTRIBUTING.md) for the bucket and IAM setup it expects.

## License

MIT. See [LICENSE](LICENSE)
