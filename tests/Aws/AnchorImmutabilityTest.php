<?php

declare(strict_types=1);

/*
 * =============================================================================
 * THE test. Real AWS, real Object Lock. This is the only test in the repo that
 * validates the product's core claim: an anchored object cannot be deleted.
 * =============================================================================
 *
 * Anchor a checkpoint under Governance retention, then:
 *   1. delete the version WITHOUT the governance bypass -> must be REFUSED;
 *   2. delete the SAME version WITH the governance bypass -> must SUCCEED.
 *
 * Step 2 is not just cleanup - it is what makes step 1 trustworthy. It proves
 * the principal actually holds s3:DeleteObject, so step 1's refusal can only be
 * the Object Lock and not a missing permission masquerading as immutability. A
 * test that only checked "the delete threw" would pass just as green for a
 * principal with no delete rights at all - proving nothing about the guarantee.
 *
 * (Deliberately no HeadObject/GetObject read-back here: retention read-back lives
 * in RetentionPresentTest via GetObjectRetention. This test needs only delete +
 * bypass rights, so it stays focused on the one property that matters.)
 *
 * SAFETY: Governance mode only (enforced by awsLockBucketSkipReason()); the
 * bypass delete removes the object, and a `finally` sweeps it if step 1 already
 * failed; the bucket comes from ANCHOR_S3_TEST_BUCKET with no default.
 */

use Chronicle\AnchorS3\S3ObjectLockAnchor;

beforeEach(function () {
    if ($reason = awsLockBucketSkipReason()) {
        $this->markTestSkipped($reason);
    }
});

it('refuses to delete an anchored object, and the refusal is the lock not a permission', function () {
    $s3 = makeAwsS3Client();
    $bucket = awsLockBucket();

    $checkpoint = makeCheckpoint('01J0S3IMMUTABLE'.bin2hex(random_bytes(6)));
    $anchor = new S3ObjectLockAnchor(
        ['bucket' => $bucket, 'prefix' => 'immutability', 'mode' => 'GOVERNANCE', 'retain_days' => 1],
        $s3,
    );

    $receipt = $anchor->anchor($checkpoint);
    [$bucketKey, $versionId] = explode('@', (string) $receipt->reference, 2);
    $key = substr($bucketKey, strlen($bucket) + 1);

    $bypassRemoved = false;
    try {
        // 1) Plain delete of the locked version must be refused.
        $plainDeleteFailed = false;
        try {
            $s3->deleteObject(['Bucket' => $bucket, 'Key' => $key, 'VersionId' => $versionId]);
        } catch (Throwable) {
            $plainDeleteFailed = true;
        }

        expect($plainDeleteFailed)->toBeTrue(
            'IMMUTABILITY VIOLATED: an anchored, Governance-locked object version was deletable without '.
            'the governance bypass. The core guarantee does not hold.'
        );

        // 2) The SAME delete WITH bypass must succeed - proving delete permission
        //    exists, so step 1's refusal was the Object Lock and nothing else.
        $s3->deleteObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'VersionId' => $versionId,
            'BypassGovernanceRetention' => true,
        ]);
        $bypassRemoved = true;

        expect($bypassRemoved)->toBeTrue(
            'The governance bypass could not delete the object, so the plain-delete refusal above cannot '.
            'be attributed to Object Lock rather than to a missing s3:DeleteObject permission.'
        );
    } finally {
        if (! $bypassRemoved) {
            bypassDeleteObjectVersion($s3, $bucket, $key, $versionId);
        }
    }
});
