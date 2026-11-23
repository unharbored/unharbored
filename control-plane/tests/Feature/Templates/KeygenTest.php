<?php

namespace Tests\Feature\Templates;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class KeygenTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/cm-keygen-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_writes_a_0600_secret_and_prints_only_the_public_key(): void
    {
        $file = $this->dir.'/key';
        $this->assertSame(0, Artisan::call('templates:keygen', ['secret-file' => $file]));
        $out = Artisan::output();

        $this->assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
        $secret = trim(file_get_contents($file));
        $this->assertSame(SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, strlen(base64_decode($secret, true)));
        $this->assertStringNotContainsString($secret, $out);

        $public = trim(explode("\n", $out)[0]);
        $this->assertSame(sodium_crypto_sign_publickey_from_secretkey(base64_decode($secret)), base64_decode($public));
    }

    public function test_refuses_to_overwrite_without_force(): void
    {
        $file = $this->dir.'/key';
        file_put_contents($file, 'precious');
        $this->assertSame(1, Artisan::call('templates:keygen', ['secret-file' => $file]));
        $this->assertSame('precious', file_get_contents($file));

        $this->assertSame(0, Artisan::call('templates:keygen', ['secret-file' => $file, '--force' => true]));
        $this->assertNotSame('precious', file_get_contents($file));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
    }
}
