<?php

use Aws\MockHandler;
use Aws\S3\S3Client;
use Chronicle\AnchorS3\Tests\TestCase;
use Chronicle\Checkpoints\Checkpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * Unit tier (mocked, zero-config). Runs on `composer test`. No I/O.
 */
pest()->extends(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Unit', 'Feature');

/**
 * Real-AWS tier. Opt-in and network-touching. These tests talk to a real AWS
 * account, so they SKIP cleanly unless explicitly opted in (see awsSkipReason()).
 * This is the ONLY tier that can prove immutability; nothing mocked and nothing
 * emulated (LocalStack) can.
 */
pest()->extends(TestCase::class)
    ->use(RefreshDatabase::class)
    ->group('aws')
    ->beforeEach(function () {
        if ($reason = awsSkipReason()) {
            $this->markTestSkipped($reason);
        }
    })
    ->in('Aws');

/**
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

/**
 * Plain object reference for use as an actor / subject (core's
 * DefaultReferenceResolver resolves any object with a public $id).
 */
function ref(string $id): object
{
    $obj = new stdClass;
    $obj->id = $id;

    return $obj;
}

/**
 * An unsaved Checkpoint for anchor()/verify() exercises across every tier.
 * created_at is set directly (no persistence); booting the testbench app via
 * TestCase is what makes the model's attribute handling work.
 */
function makeCheckpoint(string $id = '01J0S3ANCHORTESTCHECKPOINT0'): Checkpoint
{
    $cp = new Checkpoint;
    $cp->id = $id;
    $cp->chain_hash = str_repeat('a', 64);
    $cp->created_at = Carbon::createFromTimestamp(1_700_000_000);

    return $cp;
}

/**
 * S3Client backed by a MockHandler. Each queue item is an Aws\Result, an
 * exception, or a callable(CommandInterface $cmd, RequestInterface $req): Result
 * (the callable form lets a test assert on the outgoing command).
 *
 * @param  list<mixed>  $queue
 */
function makeMockS3Client(array $queue = []): S3Client
{
    $mock = new MockHandler;
    foreach ($queue as $item) {
        $mock->append($item);
    }

    return new S3Client([
        'region' => 'us-east-1',
        'version' => 'latest',
        'credentials' => ['key' => 'AKIAIOSFODNN7EXAMPLE', 'secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY'],
        'handler' => $mock,
    ]);
}

/*
 * ---------------------------------------------------------------------------
 * Real-AWS tier helpers.
 * ---------------------------------------------------------------------------
 */

/**
 * Append a one-line observation to the file named by ANCHOR_S3_FINDINGS_FILE.
 * A no-op when that variable is unset, so committed characterization tests emit
 * nothing by default - it only records when a run explicitly asks it to.
 */
function recordFinding(string $line): void
{
    $path = getenv('ANCHOR_S3_FINDINGS_FILE');
    if (is_string($path) && $path !== '') {
        file_put_contents($path, $line.PHP_EOL, FILE_APPEND);
    }
}

/**
 * The AWS region for real-AWS tests, from the environment. Empty string when
 * unset (the tier then skips - see awsSkipReason()).
 */
function awsRegion(): string
{
    foreach (['AWS_REGION', 'AWS_DEFAULT_REGION'] as $key) {
        $value = getenv($key);
        if (is_string($value) && $value !== '') {
            return $value;
        }
    }

    return '';
}

/**
 * Why the real-AWS tier should skip, or null when it may run. The tier is
 * opt-in: it never runs by accident just because credentials happen to exist.
 */
function awsSkipReason(): ?string
{
    if (getenv('ANCHOR_S3_AWS_TESTS') !== '1') {
        return 'skipped: real-AWS tier is opt-in. Set ANCHOR_S3_AWS_TESTS=1 (plus AWS credentials and AWS_REGION) to run it.';
    }

    if (awsRegion() === '') {
        return 'skipped: AWS_REGION (or AWS_DEFAULT_REGION) is not set.';
    }

    return null;
}

/**
 * A real S3Client. Credentials resolve through the SDK's default provider chain
 * (environment, then ~/.aws). Region comes from the environment.
 */
function makeAwsS3Client(): S3Client
{
    return new S3Client([
        'region' => awsRegion(),
        'version' => 'latest',
    ]);
}

/**
 * Create a uniquely-named throwaway bucket and return its name. Applies the
 * region's LocationConstraint outside us-east-1. Extra CreateBucket params
 * (e.g. ObjectLockEnabledForBucket) may be supplied by the caller.
 *
 * @param  array<string, mixed>  $params
 *
 * @throws Exception
 */
function createTestBucket(S3Client $s3, array $params = []): string
{
    $name = 'anchor-s3-char-'.bin2hex(random_bytes(8));

    $create = array_merge(['Bucket' => $name], $params);

    $region = awsRegion();
    if ($region !== '' && $region !== 'us-east-1') {
        $create['CreateBucketConfiguration'] = ['LocationConstraint' => $region];
    }

    $s3->createBucket($create);
    $s3->waitUntil('BucketExists', ['Bucket' => $name]);

    return $name;
}

/**
 * The property the anchoring guarantee forbids: a DELETABLE object sitting at
 * the target key after an anchor attempt. Returns true only when an object is
 * present there AND can be removed (i.e. it is not protected). No object, or a
 * present-but-protected object, both return false.
 *
 * Note: when it finds a deletable object it deletes it - a deliberate side
 * effect, both to prove deletability and to leave nothing behind.
 */
function deletableObjectExistsAt(S3Client $s3, string $bucket, string $key): bool
{
    try {
        $s3->headObject(['Bucket' => $bucket, 'Key' => $key]);
    } catch (Throwable) {
        return false; // nothing at the key
    }

    try {
        $s3->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
    } catch (Throwable) {
        return false; // present, but the delete was refused - it is protected
    }

    return true; // present and deletable
}

/**
 * Best-effort teardown: delete every object version and delete marker, then the
 * bucket. Safe to call from a finally-equivalent; swallows its own errors so a
 * failing assertion still gets a cleanup attempt.
 */
function deleteBucketRecursive(S3Client $s3, string $bucket): void
{
    try {
        $versions = $s3->listObjectVersions(['Bucket' => $bucket]);

        foreach (['Versions', 'DeleteMarkers'] as $set) {
            /** @var array<int, array{Key: string, VersionId: string}> $entries */
            $entries = $versions[$set] ?? [];
            foreach ($entries as $entry) {
                $s3->deleteObject([
                    'Bucket' => $bucket,
                    'Key' => $entry['Key'],
                    'VersionId' => $entry['VersionId'],
                ]);
            }
        }

        $s3->deleteBucket(['Bucket' => $bucket]);
    } catch (Throwable) {
        // Best-effort: nothing here can create an undeletable object, so a
        // failed sweep leaves only ordinary, manually-removable objects.
    }
}

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
