<?php

/*
 * =============================================================================
 * CHARACTERIZATION TEST - real AWS. NO src/ changes accompany this file.
 * =============================================================================
 *
 * Documents what S3ObjectLockAnchor ACTUALLY does today against a bucket with
 * VERSIONING ENABLED but OBJECT LOCK DISABLED. It documents behavior; it does
 * not assert what the behavior SHOULD be.
 *
 * THE PROPERTY UNDER TEST (a property, not a mechanism): after an anchor
 * attempt, there must be NO DELETABLE OBJECT at the target key. Either nothing
 * was written, or whatever was written cannot be deleted. A deletable object
 * sitting where an anchor was requested is a silent false assurance - anchoring
 * would appear to succeed while providing no immutability.
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
 * undeletable. The throwaway bucket and all versions are removed in `finally`.
 */

use Chronicle\AnchorS3\S3ObjectLockAnchor;

it('leaves no deletable object when anchoring to a versioned bucket with Object Lock OFF', function () {
    $s3 = makeAwsS3Client();
    $bucket = createTestBucket($s3);

    try {
        // Versioning ON, Object Lock never enabled.
        $s3->putBucketVersioning([
            'Bucket' => $bucket,
            'VersioningConfiguration' => ['Status' => 'Enabled'],
        ]);

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

        recordFinding('[case1: versioning ON, Object Lock OFF] anchor() '.
            ($thrown !== null ? 'THREW: '.get_class($thrown).': '.$thrown->getMessage() : 'SUCCEEDED'));

        // PRIMARY: the property. No deletable object may exist at the target key,
        // whether anchor() threw or (hypothetically) returned.
        expect(deletableObjectExistsAt($s3, $bucket, $expectedKey))->toBeFalse(
            'SILENT FALSE ASSURANCE: a deletable object exists at '.$expectedKey.' after anchoring '.
            'to a bucket without Object Lock. anchoring provided no immutability. File as a security '.
            'issue and fix it in its own PR - do NOT fold the fix into the test-tier work.'
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
