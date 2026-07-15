<?php

/*
 * LocalStack tier. Error-path conversation: missing bucket and unreachable
 * endpoint both surface as thrown exceptions, never as a successful anchor.
 * LocalStack enforces nothing about Object Lock; these assertions are about the
 * request/response conversation only.
 */

use Aws\S3\S3Client;
use Chronicle\AnchorS3\S3ObjectLockAnchor;

it('throws when the target bucket does not exist', function () {
    $s3 = makeLocalStackClient();
    $anchor = new S3ObjectLockAnchor(
        ['bucket' => 'anchor-ls-missing-'.bin2hex(random_bytes(8)), 'prefix' => 'anchors', 'mode' => 'GOVERNANCE', 'retain_days' => 1],
        $s3,
    );

    expect(fn () => $anchor->anchor(makeCheckpoint()))->toThrow(Exception::class);
});

it('throws when the endpoint is unreachable (network failure)', function () {
    // Point at a closed port to simulate a network failure.
    $s3 = new S3Client([
        'region' => 'us-east-1',
        'version' => 'latest',
        'endpoint' => 'http://127.0.0.1:1',
        'use_path_style_endpoint' => true,
        'credentials' => ['key' => 'test', 'secret' => 'test'],
        'retries' => 0,
    ]);
    $anchor = new S3ObjectLockAnchor(
        ['bucket' => 'anything', 'prefix' => 'anchors', 'mode' => 'GOVERNANCE', 'retain_days' => 1],
        $s3,
    );

    expect(fn () => $anchor->anchor(makeCheckpoint()))->toThrow(Exception::class);
});
