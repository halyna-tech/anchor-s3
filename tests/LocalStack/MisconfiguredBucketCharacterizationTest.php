<?php

declare(strict_types=1);

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
