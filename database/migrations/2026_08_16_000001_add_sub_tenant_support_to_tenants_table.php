<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * Tenancy — sub-tenant support on the central `tenants` registry (CONTROL PLANE).
 *
 * Adds a self-referencing hierarchy: `parent_tenant_id` records which tenant
 * created a sub-tenant, so a child is always attributable to (and listable
 * under) its parent. `can_create_sub_tenants` is the super-admin GRANT — a
 * tenant may create its own sub-tenants only once a platform admin has set
 * this flag via TenantAdminService::grantSubTenantCreation(); it defaults to
 * 0 so the capability is never acquired by accident.
 *
 * The FK restricts deletion of a tenant that still has children — the
 * service layer checks this first (TenantWriteStore::hasChildren()) to raise
 * a clean ServiceException instead of a raw constraint violation, but the
 * constraint itself is the hard backstop.
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->table('tenants', static function ($t) {
            $t->char('parent_tenant_id', 31)->nullable()->after('tenant_id')
                ->comment('tenant_id of the creating tenant, or NULL for a top-level tenant');
            $t->boolean('can_create_sub_tenants')->default(0)
                ->comment('1 = a platform admin has granted this tenant permission to create sub-tenants');

            $t->index(['parent_tenant_id'], 'idx_parent_tenant_id');

            $t->foreign('parent_tenant_id')
                ->references('tenant_id')->on('tenants')
                ->restrictOnDelete()
                ->name('fk_tenants_parent_tenant_id');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        // TWO separate table() calls, not one — AbstractGrammar::compileAlter()
        // always emits every dropped COLUMN clause before every dropped FOREIGN
        // KEY clause, regardless of the order dropForeign()/dropColumn() are
        // called in a single closure. MySQL refuses to drop a column a
        // constraint still references, so the FK has to be gone (its own
        // executed statement) before the column-drop statement below even
        // compiles, let alone runs.
        $schema->table('tenants', static function ($t) {
            $t->dropForeign('fk_tenants_parent_tenant_id');
        });

        $schema->table('tenants', static function ($t) {
            // dropColumn('parent_tenant_id') auto-drops its single-column index
            // too (see add_platform_admin_to_users.php for why an explicit
            // dropIndex after that would fail).
            $t->dropColumn('parent_tenant_id');
            $t->dropColumn('can_create_sub_tenants');
        });
    }
};
