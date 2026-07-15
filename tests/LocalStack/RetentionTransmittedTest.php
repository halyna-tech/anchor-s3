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
