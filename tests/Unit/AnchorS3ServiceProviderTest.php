<?php

declare(strict_types=1);

use Aws\S3\S3Client;

it('binds a default S3Client resolved from the configured region', function () {
    // Drop any previously resolved singleton so the provider's factory closure
    // actually runs against the config we set here.
    app()->forgetInstance(S3Client::class);
    config()->set('chronicle-anchor-s3.region', 'eu-west-1');

    $client = app(S3Client::class);

    expect($client)->toBeInstanceOf(S3Client::class)
        ->and($client->getRegion())->toBe('eu-west-1');
});
