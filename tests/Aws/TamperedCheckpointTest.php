<?php

declare(strict_types=1);

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

        expect($anchor->verify($tampered, $receipt))->toBeFalse()
            // The genuine checkpoint still verifies, proving the object is intact.
            ->and($anchor->verify($original, $receipt))->toBeTrue();
    } finally {
        bypassDeleteObjectVersion($s3, $bucket, $key, $versionId);
    }
});
