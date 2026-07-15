<?php

/*
 * =============================================================================
 * CHARACTERIZATION TEST - real AWS. NO src/ changes accompany this file.
 * =============================================================================
 *
 * Documents what S3ObjectLockAnchor ACTUALLY does today against a bucket with
 * NO VERSIONING (and therefore no Object Lock - Object Lock requires
 * versioning). It documents behavior; it does not assert what it SHOULD be.
 *
 * THE PROPERTY UNDER TEST (a property, not a mechanism): after an anchor
 * attempt, there must be NO DELETABLE OBJECT at the target key. An anchor that
 * fails but still leaves a plain, deletable object behind is a leftover that
 * could later be mistaken for a real anchor.
 *
 * WHY THIS PASSES TODAY: the adapter sends Object Lock retention headers
 * (ObjectLockMode + ObjectLockRetainUntilDate) on the SAME PutObject that writes
 * the digest. S3 rejects that PutObject outright against a bucket without Object
 * Lock ("InvalidRequest: Bucket is missing ObjectLockConfiguration"), so nothing
 * is written at all. THE PROTECTION COMES FROM AWS, NOT FROM US. If a future
 * change ever decouples retention from the write - writing the object first and
 * applying retention as a separate step, or swallowing the retention error -
 * this test MUST fail, because a deletable object would then exist at the key.
 * Catching that regression is precisely this test's job.
 *
 * SAFETY: Object Lock is never enabled here, so nothing created can become
 * undeletable. The throwaway bucket is removed in `finally`.
 */

use Chronicle\AnchorS3\S3ObjectLockAnchor;

beforeEach(function () {
    if ($reason = awsLockBucketSkipReason()) {
        $this->markTestSkipped($reason);
    }
});

it('leaves no deletable object when anchoring to a bucket without versioning', function () {
    $s3 = makeAwsS3Client();

    // Default bucket: no versioning, no Object Lock.
    $bucket = createTestBucket($s3);

    try {
        $checkpoint = makeCheckpoint();
        $expectedKey = 'char/'.$checkpoint->id.'.digest';
        $anchor = new S3ObjectLockAnchor(
            ['bucket' => $bucket, 'prefix' => 'char', 'mode' => 'GOVERNANCE', 'retain_days' => 1],
            $s3,
        );

        $thrown = null;
        try {
            $anchor->anchor($checkpoint);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        recordFinding('[case2: versioning OFF] anchor() '.
            ($thrown !== null ? 'THREW: '.get_class($thrown).': '.$thrown->getMessage() : 'SUCCEEDED'));

        // PRIMARY: the property. No deletable object may exist at the target key,
        // whether anchor() threw or (hypothetically) returned.
        expect(deletableObjectExistsAt($s3, $bucket, $expectedKey))->toBeFalse(
            'UNPROTECTED LEFTOVER: a deletable object exists at '.$expectedKey.' after anchoring to a '.
            'bucket without versioning. No immutable version can exist on such a bucket, so this object '.
            'is not a real anchor. File as a security issue and fix it in its own PR - not here.'
        );

        // SECONDARY: today the property holds because AWS refuses the write. If
        // it refused, the refusal should carry a message rather than be silent.
        if ($thrown !== null) {
            expect($thrown->getMessage())->not->toBe('');
        }
    } finally {
        deleteBucketRecursive($s3, $bucket);
    }
});
