<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Providers\CustomServiceProvider;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CustomFolderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/custom-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->dir.'/Providers');
        File::ensureDirectoryExists($this->dir.'/routes');
        config(['control_plane.custom_path' => $this->dir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_routes_and_providers_in_the_custom_folder_are_loaded(): void
    {
        file_put_contents($this->dir.'/routes/web.php', "<?php\nuse Illuminate\\Support\\Facades\\Route;\nRoute::get('/custom-hello', fn () => 'hello from custom');\n");
        file_put_contents($this->dir.'/Providers/CustomTestProvider.php', "<?php\nnamespace Custom\\Providers;\nclass CustomTestProvider extends \\Illuminate\\Support\\ServiceProvider\n{\n    public function register(): void\n    {\n        \$this->app->instance('custom.loaded', true);\n    }\n}\n");

        $provider = new CustomServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $this->assertTrue($this->app->make('custom.loaded'));
        $this->get('/custom-hello')->assertOk()->assertSee('hello from custom');
    }

    public function test_a_missing_custom_folder_is_not_an_error(): void
    {
        config(['control_plane.custom_path' => $this->dir.'/nowhere']);

        $provider = new CustomServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $this->get('/custom-hello')->assertNotFound();
    }
}
