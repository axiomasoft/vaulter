<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Configuration\WebhooksConfig;
use Vaulter\Contracts\Sharing\ShareTokenGenerator;
use Vaulter\Data\Drive\UploadOptions;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Profiles\DefaultProfile;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\Panels\TeamProfile;
use Vaulter\Tests\Support\ConfigurationArchitectureGuard;
use Vaulter\Tests\TestCase;

final class PublishedV02TokenGenerator implements ShareTokenGenerator
{
    public function generate(): string
    {
        return str_repeat('a', 64);
    }
}

final class PublishedConfigV02Test extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $deprecations = [];

    private bool $deprecationHandlerInstalled = false;

    protected function setUp(): void
    {
        try {
            parent::setUp();
        } finally {
            $this->restoreDeprecationHandler();
        }
    }

    private function restoreDeprecationHandler(): void
    {
        if ($this->deprecationHandlerInstalled) {
            restore_error_handler();
            $this->deprecationHandlerInstalled = false;
        }
    }

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $fixture = __DIR__.'/../Fixtures/config/vaulter-0.2.php';
        ConfigurationArchitectureGuard::assertPublishedV02Fixture($fixture);
        $legacy = require $fixture;
        $legacy['middleware'] = [];
        $legacy['panels'] = [TeamProfile::class];
        $legacy['default_panel'] = DefaultProfile::class;
        $legacy['share_token_generator'] = PublishedV02TokenGenerator::class;
        $legacy['enabled_document_types'] = ['markdown'];
        // 1.0 refuses the pre-1.0 delivery keys at boot (ConfigValidatorTest pins that); this
        // test is about the rest of a published 0.2 file, so the host is taken to have dropped them.
        foreach (array_keys(WebhooksConfig::REMOVED_KEYS) as $removed) {
            unset($legacy['webhooks'][$removed]);
        }
        $app['config']->set('vaulter', $legacy);

        // Testbench installs its exception handler before defineEnvironment, then boots.
        set_error_handler(function (int $severity, string $message): bool {
            if ($severity === E_USER_DEPRECATED) {
                $this->deprecations[] = $message;

                return true;
            }

            return false;
        });
        $this->deprecationHandlerInstalled = true;
        $app->booted(function (): void {
            $this->restoreDeprecationHandler();
        });
    }

    public function test_published_v02_configuration_boots_and_supports_drive_operations(): void
    {
        $config = app(VaulterConfig::class);
        self::assertSame([TeamProfile::class], $config->profileProviders());
        self::assertSame(PublishedV02TokenGenerator::class, $config->sharing()->tokenGenerator());
        // Without vaulter-documents installed the legacy key is left for that package.
        self::assertSame(['markdown'], config('vaulter.enabled_document_types'));
        self::assertSame('ulid', $config->ids()->strategy());
        self::assertSame(60, $config->routes()->publicRateLimit());
        self::assertNotEmpty($this->deprecations);
        self::assertCount(count(array_unique($this->deprecations)), $this->deprecations);
        self::assertContains('[Vaulter] Config key "vaulter.middleware" is deprecated; use "vaulter.routes.middleware.authenticated".', $this->deprecations);

        Storage::fake('local');
        $handle = DriveTestHelpers::drive();
        $folder = $handle->folders()->create('published-config');
        $uploadedFile = UploadedFile::fake()->createWithContent('fixture.txt', 'published v0.2 works');
        $file = $handle->files()->upload(UploadSource::fromUploadedFile($uploadedFile), new UploadOptions(parent: $folder));
        self::assertSame($folder->id, $file->parentId);
        self::assertSame('fixture.txt', $file->name);
    }
}
