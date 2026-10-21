<?php
use YAPR\ApiClient;

return array(
	'matching versions are compatible' => function () {
		Assert::same( 'ok', ApiClient::compatibility( array( 'api_version' => 'v1', 'service_version' => '1.1.0' ) )['status'] );
		Assert::same( 'ok', ApiClient::compatibility( array( 'api_version' => 'v1', 'service_version' => '1.0.3' ) )['status'] );
	},
	'older service is flagged but usable' => function () {
		Assert::same( 'outdated', ApiClient::compatibility( array( 'api_version' => 'v1', 'service_version' => '1.0.2' ) )['status'] );
	},
	'different API version is refused' => function () {
		Assert::same( 'incompatible', ApiClient::compatibility( array( 'api_version' => 'v2', 'service_version' => '2.0.0' ) )['status'] );
	},
	'unknown until tested' => function () {
		Assert::same( 'unknown', ApiClient::compatibility( array() )['status'] );
	},
);
