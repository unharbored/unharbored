<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Lib\Audit\AuditLog;
use App\Lib\Cloud\CredentialStore;
use App\Lib\Cloud\ServerProvisioner;
use App\Lib\Cloud\Reconciler;
use App\Lib\Enrollment\EnrollmentService;
use App\Lib\Resources\ResourceManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * Regression tests for a class of bug that passed every other test.
 *
 * Laravel's container falls back to a parameter's default when it does not
 * resolve it, so a constructor written as `?AuditLog $audit = null` silently
 * resolves to null. That is what happened: the container-built
 * EnrollmentService had no audit log, so enrollment went unrecorded while the
 * whole suite stayed green, because every test constructed the service by
 * hand and supplied one.
 *
 * The lesson generalises. Unit tests that build their subject by hand never
 * exercise the wiring, and the wiring is where cross-cutting concerns like
 * auditing silently disappear. These tests resolve from the container the way
 * a real request does.
 */
final class ContainerWiringTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
    }

    /**
     * @return array<int, array{0: class-string, 1: string}>
     */
    public static function auditedServices(): array
    {
        return [
            'enrollment' => [EnrollmentService::class, 'audit'],
            'resources' => [ResourceManager::class, 'audit'],
            'credentials' => [CredentialStore::class, 'audit'],
            'reconciler' => [Reconciler::class, 'audit'],
        ];
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('auditedServices')]
    public function test_audited_services_resolve_with_an_audit_log(string $class, string $property): void
    {
        $service = $this->app->make($class);

        $reflection = new ReflectionClass($service);
        $value = $reflection->getProperty($property)->getValue($service);

        $this->assertInstanceOf(
            AuditLog::class,
            $value,
            sprintf(
                '%s resolved from the container without an audit log. Every action it takes '
                .'would go unrecorded while the tests stayed green.',
                $class
            )
        );
    }

    /**
     * Stronger than checking the property: an un-audited instance must be
     * impossible to construct, not merely unlikely.
     */
    public function test_audited_services_cannot_be_constructed_without_an_audit_log(): void
    {
        foreach (array_column(self::auditedServices(), 0) as $class) {
            $constructor = (new ReflectionClass($class))->getConstructor();
            $this->assertNotNull($constructor);

            foreach ($constructor->getParameters() as $parameter) {
                $type = $parameter->getType();

                if ($type instanceof \ReflectionNamedType && $type->getName() === AuditLog::class) {
                    $this->assertFalse(
                        $parameter->isOptional(),
                        sprintf(
                            '%s takes an optional AuditLog. Laravel will resolve it to null and '
                            .'nothing will complain.',
                            $class
                        )
                    );
                    $this->assertFalse($type->allowsNull(), $class.' allows a null AuditLog.');
                }
            }
        }
    }

    public function test_the_provisioner_resolves_with_its_whole_dependency_graph(): void
    {
        $provisioner = $this->app->make(ServerProvisioner::class);

        $reflection = new ReflectionClass($provisioner);

        foreach (['providers', 'resources', 'enrollment', 'cloudInit'] as $property) {
            $this->assertNotNull(
                $reflection->getProperty($property)->getValue($provisioner),
                sprintf('ServerProvisioner::$%s was not wired.', $property)
            );
        }
    }

    public function test_core_services_are_singletons(): void
    {
        // Two AuditLog instances is harmless, but two Vaults with different
        // key managers would not be, and the sharing is worth pinning.
        foreach ([AuditLog::class, Vault::class, ResourceManager::class, CredentialStore::class] as $class) {
            $this->assertSame(
                $this->app->make($class),
                $this->app->make($class),
                $class.' is not shared.'
            );
        }
    }
}
