<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Application\Application;
use EzPhp\HttpClient\HttpClientServiceProvider;
use EzPhp\Push\Driver\ApnsDriver;
use EzPhp\Push\Driver\ArrayDriver;
use EzPhp\Push\Driver\FcmDriver;
use EzPhp\Push\Driver\NullDriver;
use EzPhp\Push\Push;
use EzPhp\Push\PushDriverInterface;
use EzPhp\Push\Pusher;
use EzPhp\Push\PushMessage;
use EzPhp\Push\PushServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\Push\ApplicationTestCase;

/**
 * Class PushServiceProviderTest
 *
 * @package Tests
 */
#[CoversClass(PushServiceProvider::class)]
#[UsesClass(Push::class)]
#[UsesClass(Pusher::class)]
#[UsesClass(NullDriver::class)]
#[UsesClass(ArrayDriver::class)]
#[UsesClass(ApnsDriver::class)]
#[UsesClass(FcmDriver::class)]
#[UsesClass(PushMessage::class)]
final class PushServiceProviderTest extends ApplicationTestCase
{
    protected function setUp(): void
    {
        foreach (['PUSH_DRIVER', 'PUSH_APNS_KEY_ID', 'PUSH_APNS_TEAM_ID', 'PUSH_APNS_BUNDLE_ID', 'PUSH_APNS_PRIVATE_KEY', 'PUSH_APNS_SANDBOX', 'PUSH_FCM_PROJECT_ID', 'PUSH_FCM_CLIENT_EMAIL', 'PUSH_FCM_PRIVATE_KEY'] as $var) {
            putenv($var . '=');
        }

        Push::resetPusher();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        foreach (['PUSH_DRIVER', 'PUSH_APNS_KEY_ID', 'PUSH_APNS_TEAM_ID', 'PUSH_APNS_BUNDLE_ID', 'PUSH_APNS_PRIVATE_KEY', 'PUSH_APNS_SANDBOX', 'PUSH_FCM_PROJECT_ID', 'PUSH_FCM_CLIENT_EMAIL', 'PUSH_FCM_PRIVATE_KEY'] as $var) {
            putenv($var . '=');
        }

        Push::resetPusher();

        parent::tearDown();
    }

    /**
     * @param Application $app
     *
     * @return void
     */
    protected function configureApplication(Application $app): void
    {
        $app->register(HttpClientServiceProvider::class);
        $app->register(PushServiceProvider::class);
    }

    /**
     * @return void
     * @throws \ReflectionException
     */
    public function testDefaultDriverIsNull(): void
    {
        $this->assertInstanceOf(NullDriver::class, $this->app()->make(PushDriverInterface::class));
    }

    /**
     * @return void
     * @throws \ReflectionException
     */
    public function testArrayDriverIsCreatedWhenConfigured(): void
    {
        putenv('PUSH_DRIVER=array');

        $this->assertInstanceOf(ArrayDriver::class, $this->app()->make(PushDriverInterface::class));
    }

    /**
     * @return void
     * @throws \ReflectionException
     */
    public function testApnsDriverIsCreatedWhenConfigured(): void
    {
        putenv('PUSH_DRIVER=apns');
        putenv('PUSH_APNS_KEY_ID=KEY123');
        putenv('PUSH_APNS_TEAM_ID=TEAM123');
        putenv('PUSH_APNS_BUNDLE_ID=com.example.app');
        putenv('PUSH_APNS_PRIVATE_KEY=dummy-pem');

        $this->assertInstanceOf(ApnsDriver::class, $this->app()->make(PushDriverInterface::class));
    }

    /**
     * @return void
     * @throws \ReflectionException
     */
    public function testFcmDriverIsCreatedWhenConfigured(): void
    {
        putenv('PUSH_DRIVER=fcm');
        putenv('PUSH_FCM_PROJECT_ID=my-project');
        putenv('PUSH_FCM_CLIENT_EMAIL=service@my-project.iam.gserviceaccount.com');
        putenv('PUSH_FCM_PRIVATE_KEY=dummy-pem');

        $this->assertInstanceOf(FcmDriver::class, $this->app()->make(PushDriverInterface::class));
    }

    /**
     * @return void
     * @throws \ReflectionException
     */
    public function testPusherIsBoundInContainer(): void
    {
        $this->assertInstanceOf(Pusher::class, $this->app()->make(Pusher::class));
    }

    /**
     * @return void
     */
    public function testPushFacadeIsWiredAfterBoot(): void
    {
        // If the facade is wired, this must not throw
        Push::send('token', new PushMessage('Title', 'Body'));
        $this->addToAssertionCount(1);
    }

    /**
     * boot() must wire the Push facade via a deferred resolver, not by
     * eagerly resolving PushDriverInterface — otherwise the container would
     * cache a singleton built from whatever config existed at bootstrap
     * time, before test code (or application code) has a chance to
     * configure the driver afterward.
     *
     * @return void
     */
    public function testBootWiresPushFacadeWithoutEagerResolution(): void
    {
        // If this test's own setUp()/bootstrap had already eagerly resolved
        // PushDriverInterface (e.g. a regression reintroducing $app->make()
        // in boot()), setting PUSH_DRIVER here afterward would have no
        // effect and the facade would still deliver through a NullDriver.
        putenv('PUSH_DRIVER=array');

        $driver = $this->app()->make(PushDriverInterface::class);
        $this->assertInstanceOf(ArrayDriver::class, $driver);

        Push::send('token-1', new PushMessage('Title', 'Body'));

        $this->assertCount(1, $driver->sentTo('token-1'));
    }
}
