<?php

declare(strict_types=1);

/**
 * English copy for the Tenancy plugin's admin screens.
 *
 * Reached as 'tenancy::tenancy.*'.
 *
 * NOT translated, deliberately: the `placeholder` attributes in the forms
 * ("acme", "3306", "app.example.com"). Those are example VALUES showing the
 * expected shape of an input, not instructions — translating "acme" into
 * another language would make the example less useful, not more.
 */
return [
    // Shared labels, so the same word does not drift between six screens.
    'common' => [
        'loading'     => 'Loading…',
        'reload'      => 'Reload',
        'name'        => 'Name',
        'slug'        => 'Slug',
        'status'      => 'Status',
        'database'    => 'Database',
        'cancel'      => 'Cancel',
        'delete'      => 'Delete',
        'edit'        => 'Edit',
        'back'        => 'Back',
        'loaded_from' => 'Loaded from',
    ],

    'nav' => [
        'brand'   => 'Tenancy',
        'tenants' => 'Your tenants',
        'manage'  => 'Manage',
        'hosts'   => 'Hosts',
    ],

    // The tenant a user picks here decides which database every later request
    // is routed to, so the wording has to make the choice explicit.
    'index' => [
        'title'  => 'Your tenants',
        'role'   => 'Role',
        'scoped' => 'Now scoped to :name as :role.',
        'select' => 'Select',
        'empty'  => 'You are not a member of any tenant yet.',
    ],

    'manage' => [
        'title' => 'Tenants',
        'empty' => 'No tenants yet. Create the first one.',
    ],

    'create' => [
        'title'        => 'Provision a new tenant',
        'display_name' => 'Display name',
        'physical_db'  => 'Physical database name',
        'db_driver'    => 'Database driver',
        'db_host'      => 'Database host',
        'db_port'      => 'Database port',
        'db_user'      => 'Database username',
        'db_password'  => 'Database password',
        'provisioned'  => 'Tenant ":name" provisioned.',
        'submit'       => 'Provision tenant',
    ],

    'edit' => [
        'title'  => 'Edit tenant',
        'submit' => 'Save changes',
    ],

    'hosts' => [
        'title'        => 'Hosts',
        'add_title'    => 'Add a custom domain',
        'hostname'     => 'Hostname',
        'expected_a'   => 'Expected A record (optional)',
        'register'     => 'Register host',
        'empty'        => 'No hosts registered yet.',
        'primary'      => 'Primary',
        'make_primary' => 'Make primary',
        'verified'     => 'Verified',
        'verify'       => 'Verify',
        'publish_dns'  => 'Publish this DNS record',
    ],
];
