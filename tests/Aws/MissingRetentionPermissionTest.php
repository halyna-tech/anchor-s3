<?php

declare(strict_types=1);

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
        expect($thrown)->not->toBeNull()
            ->and(deletableObjectExistsAt($admin, $bucket, $expectedKey))->toBeFalse(
                'anchoring with insufficient permission left a deletable object at '.$expectedKey.'.'
            );
    } finally {
        // deletableObjectExistsAt already removed any deletable object; nothing to bypass.
    }
});
