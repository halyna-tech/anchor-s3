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
