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
