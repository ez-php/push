<?php

declare(strict_types=1);

namespace Tests\Push;

use EzPhp\Testing\ApplicationTestCase as EzPhpApplicationTestCase;
use RuntimeException;

/**
 * Base class for push module tests that need a bootstrapped Application.
 *
 * Creates a temporary application root containing a config/push.php file
 * that reads from env vars at require-time. Because Config and all service
 * bindings are resolved lazily, env vars set in a test method before the
 * first make() call are picked up correctly — no special bootstrap ordering
 * needed.
 *
 * @package Tests\Push
 */
abstract class ApplicationTestCase extends EzPhpApplicationTestCase
{
    /**
     * @return string
     */
    protected function getBasePath(): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ez-push-test-' . uniqid('', true);
        $configDir = $path . DIRECTORY_SEPARATOR . 'config';

        mkdir($configDir, 0o777, true);

        $content = <<<'PHP'
            <?php

            declare(strict_types=1);

            return [
                'driver' => getenv('PUSH_DRIVER') ?: 'null',
                'apns' => [
                    'key_id' => getenv('PUSH_APNS_KEY_ID') ?: '',
                    'team_id' => getenv('PUSH_APNS_TEAM_ID') ?: '',
                    'bundle_id' => getenv('PUSH_APNS_BUNDLE_ID') ?: '',
                    'private_key' => getenv('PUSH_APNS_PRIVATE_KEY') ?: '',
                    'sandbox' => (bool) getenv('PUSH_APNS_SANDBOX'),
                ],
                'fcm' => [
                    'project_id' => getenv('PUSH_FCM_PROJECT_ID') ?: '',
                    'client_email' => getenv('PUSH_FCM_CLIENT_EMAIL') ?: '',
                    'private_key' => getenv('PUSH_FCM_PRIVATE_KEY') ?: '',
                ],
                'webpush' => [
                    'private_key' => getenv('PUSH_WEBPUSH_PRIVATE_KEY') ?: '',
                    'subject' => getenv('PUSH_WEBPUSH_SUBJECT') ?: '',
                    'ttl' => (int) (getenv('PUSH_WEBPUSH_TTL') ?: 86400),
                ],
            ];
            PHP;

        $result = file_put_contents($configDir . DIRECTORY_SEPARATOR . 'push.php', $content);

        if ($result === false) {
            throw new RuntimeException('Failed to write push.php for test at ' . $configDir);
        }

        return $path;
    }
}
