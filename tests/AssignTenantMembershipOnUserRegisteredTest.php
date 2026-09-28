<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Tenancy;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Tenancy\Application\Listeners\AssignTenantMembershipOnUserRegistered;
use Plugins\Tenancy\Application\Ports\MembershipWriter;

final class AssignTenantMembershipOnUserRegisteredTest extends TestCase
{
    /** @var list<array{string, string, string}> */
    private array $seats = [];

    private function writer(): MembershipWriter
    {
        $seats = &$this->seats;

        return new class ($seats) implements MembershipWriter {
            /** @param list<array{string, string, string}> $seats */
            public function __construct(private array &$seats) {}

            public function upsertActive(string $userId, string $tenantId, string $role): void
            {
                $this->seats[] = [$userId, $tenantId, $role];
            }
        };
    }

    /** @param array<string, mixed> $payload */
    private function registered(array $payload): IntegrationEventContract
    {
        return new class ($payload) implements IntegrationEventContract {
            /** @param array<string, mixed> $payload */
            public function __construct(private array $payload) {}

            public function name(): string { return 'user.registered'; }

            public function version(): string { return '1.0'; }

            public function payload(): array { return $this->payload; }
        };
    }

    public function test_a_signup_is_seated_as_member_by_default(): void
    {
        (new AssignTenantMembershipOnUserRegistered($this->writer()))
            ->handle($this->registered(['userId' => 'u1', 'tenantId' => 't1']));

        self::assertSame([['u1', 't1', 'member']], $this->seats);
    }

    public function test_a_signup_is_seated_with_the_configured_role(): void
    {
        (new AssignTenantMembershipOnUserRegistered($this->writer(), 'publisher'))
            ->handle($this->registered(['userId' => 'u1', 'tenantId' => 't1']));

        self::assertSame([['u1', 't1', 'publisher']], $this->seats);
    }

    public function test_a_signup_with_no_tenant_is_not_seated(): void
    {
        (new AssignTenantMembershipOnUserRegistered($this->writer(), 'publisher'))
            ->handle($this->registered(['userId' => 'u1', 'tenantId' => '']));

        self::assertSame([], $this->seats);
    }

    /** @return iterable<string, array{?string, string}> */
    public static function configuredRoles(): iterable
    {
        yield 'unset'                     => [null, 'member'];
        yield 'empty'                     => ['', 'member'];
        yield 'a site role'               => ['publisher', 'publisher'];
        yield 'normalised'                => ['  Publisher ', 'publisher'];
        yield 'viewer'                    => ['viewer', 'viewer'];
        yield 'owner is never self-given' => ['owner', 'member'];
        yield 'admin is never self-given' => ['ADMIN', 'member'];
        yield 'two roles'                 => ['publisher,admin', 'member'];
        yield 'too long for the column'   => [str_repeat('a', 33), 'member'];
    }

    #[DataProvider('configuredRoles')]
    public function test_the_signup_role_never_grants_more_than_the_setting_can_justify(?string $configured, string $expected): void
    {
        self::assertSame($expected, AssignTenantMembershipOnUserRegistered::signupRole($configured));
    }
}
