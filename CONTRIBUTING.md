# Contributing to anchor-s3

This package anchors Chronicle checkpoints to S3 Object Lock, so that proof of a ledger's history lives somewhere your application cannot reach - and therefore cannot quietly rewrite.

Everything in the [organization-wide contributing guide](https://github.com/laravel-chronicle/.github/blob/main/CONTRIBUTING.md) applies here. This page covers what's specific to this package, plus the parts of the org guide that matter enough to repeat.

## The invariant, restated

**Nothing in this ecosystem mutates a written ledger entry**, and nothing here may weaken the anchoring guarantee.

For `anchor-s3` specifically, that has a second edge. A bug that makes anchoring *fail* is a normal bug. A bug that makes anchoring *appear to succeed without actually providing immutability* is a security issue - it produces a false assurance, which is worse than no assurance, because someone will show it to an auditor. If you find one, do not open a public issue: see [SECURITY.md](https://github.com/laravel-chronicle/.github/blob/main/SECURITY.md).

Treat "the happy path passed" as insufficient evidence throughout this package.

## Setup

```bash
git clone https://github.com/laravel-chronicle/anchor-s3.git
cd anchor-s3
composer install
composer test
```

**That should pass with no AWS account, no credentials, and no configuration.** If it doesn't, that's a bug in the test suite and worth an issue on its own - contributors should never need a credit card to get a green build.

The default suite runs against a mocked S3 client. It covers the adapter's logic: what it sends, how it handles responses, what it does with errors, and how it behaves when the bucket is misconfigured.

## The three tiers of tests

| Tier          | Needs          | When it runs                                         |
|---------------|----------------|------------------------------------------------------|
| Unit / mocked | Nothing        | Always, including `composer test` and CI on every PR |
| LocalStack    | Docker         | Opt-in locally; CI on every PR                       |
| Real AWS      | An AWS account | Opt-in locally; CI on `main` only                    |

Most contributions never need to leave the first tier. Reach for the others when you're changing how the package talks to S3 rather than what it decides to say.

### LocalStack

```bash
docker compose up -d localstack
composer test:integration
```

This exercises the real S3 API surface without touching AWS. It is the right tier for anything touching bucket configuration, versioning, or the shape of the requests.

**Caveat worth internalizing:** LocalStack emulates the Object Lock API, but emulation is not enforcement. A test can put an object with a retention header, get a 200 back, and prove nothing about whether the object is actually immutable. LocalStack tells you the *conversation* was right. It cannot tell you the *guarantee* holds. For that, you need the third tier.

### Real AWS

Only necessary when changing retention behavior, and honestly, ask in the issue before you go here - a maintainer may be able to run it for you rather than have you provision infrastructure.

If you do:

```bash
cp .env.example .env
# fill in AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_REGION, ANCHOR_S3_BUCKET
composer test:aws
```

**Read this before you create the bucket.**

Object Lock requires versioning to be enabled, and the path of least resistance is to enable Object Lock when you create the bucket rather than retrofitting it.

Object Lock has two retention modes, and the difference is not academic:

- **Governance mode** - a user with the right permission can shorten or remove retention. Use this. Always, in development.
- **Compliance mode** - retention cannot be shortened or removed by *anyone*, including the account root user, until it expires. This is the mode real deployments want, and it is exactly why you must not use it in a test bucket. Set a 7-year retention on a test object in Compliance mode, and you have created an object you will be paying to store until 2033, with no way to delete it. There is no support ticket that undoes this.

Use Governance mode and short retention periods (minutes, not years) in every test bucket. Delete the bucket when you're done.

## Standards

Same as the rest of the org, and all three run in CI:

```bash
composer test:unit  # Pest
composer test:types # PHPStan level 10, no new baseline entries
composer lint       # style, automated - do not hand-tune
```

## What good tests look like here

New behavior needs a test; bug fixes need a test that fails before the change and passes after.

For this package in particular, we want the **negative** cases at least as much as the positive ones:

- The bucket without versioning enabled - anchoring must refuse, loudly, at configuration time.
- The bucket without Object Lock - same: refuse, do not proceed with a warning.
- The credentials that lack `PutObjectRetention` - must fail, not silently write an unprotected object.
- The retention period that fails to apply - must surface as an error, never as a successful anchor.
- The checkpoint that doesn't match what was anchored - verification must fail.

A test proving that anchoring works is worth less than a test proving that a broken anchor cannot pass for a working one. Write both; prioritize the second.

## Pull requests

Branch from `main`, one concern per branch, and describe *why* - the diff already shows the what. Note breaking changes explicitly.

If your change affects how anchoring is configured or what guarantees it provides, the docs at [laravel-chronicle.dev](https://laravel-chronicle.dev) need updating too. For this package the docs are load-bearing: someone reading them is deciding what to tell an auditor.

## Questions

[Discussions](https://github.com/orgs/laravel-chronicle/discussions). Especially the compliance-shaped ones - "my auditor wants X, does anchoring satisfy it?" is a great thread and consistently turns into better documentation.
