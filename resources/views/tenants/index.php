<?php
/**
 * Tenant picker (tenancy::tenants/index). Hydrates over AJAX from
 * GET /ajx/me/tenants; selecting a tenant re-mints a tenant-scoped token via
 * POST /ajx/tenants/{id}/select.
 */
?>
<div class="card">
    <h2><?= htmlspecialchars(trans('tenancy::tenancy.nav.tenants'), ENT_QUOTES, 'UTF-8') ?></h2>
    <p class="muted"><?= htmlspecialchars(trans('tenancy::tenancy.common.loaded_from'), ENT_QUOTES, 'UTF-8') ?><code>GET /ajx/me/tenants</code> (requires the <code>auth</code> filter).
       Select one to scope your session to that tenant.</p>

    <table>
        <thead>
            <tr><th><?= htmlspecialchars(trans('tenancy::tenancy.common.name'), ENT_QUOTES, 'UTF-8') ?></th><th><?= htmlspecialchars(trans('tenancy::tenancy.common.slug'), ENT_QUOTES, 'UTF-8') ?></th><th><?= htmlspecialchars(trans('tenancy::tenancy.index.role'), ENT_QUOTES, 'UTF-8') ?></th><th><?= htmlspecialchars(trans('tenancy::tenancy.common.status'), ENT_QUOTES, 'UTF-8') ?></th><th></th></tr>
        </thead>
        <tbody id="rows">
            <tr><td colspan="5" class="muted"><?= htmlspecialchars(trans('tenancy::tenancy.common.loading'), ENT_QUOTES, 'UTF-8') ?></td></tr>
        </tbody>
    </table>

    <div class="actions">
        <button class="btn" type="button" id="reload"><?= htmlspecialchars(trans('tenancy::tenancy.common.reload'), ENT_QUOTES, 'UTF-8') ?></button>
    </div>
</div>

<template id="row-tpl">
    <tr>
        <td class="c-name"></td>
        <td class="c-slug muted"></td>
        <td class="c-role"></td>
        <td><span class="badge c-status"></span></td>
        <td><button class="btn btn-sm btn-primary c-select" type="button"><?= htmlspecialchars(trans('tenancy::tenancy.index.select'), ENT_QUOTES, 'UTF-8') ?></button></td>
    </tr>
</template>

<script>
(function () {
    const tbody = document.getElementById('rows');
    const tpl = document.getElementById('row-tpl');

    function render(tenants) {
        tbody.innerHTML = '';
        if (!tenants.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="muted"><?= htmlspecialchars(trans('tenancy::tenancy.index.empty'), ENT_QUOTES, 'UTF-8') ?></td></tr>';
            return;
        }
        for (const t of tenants) {
            const row = tpl.content.cloneNode(true);
            row.querySelector('.c-name').textContent = t.name;
            row.querySelector('.c-slug').textContent = t.slug;
            row.querySelector('.c-role').textContent = t.role;
            const status = row.querySelector('.c-status');
            status.textContent = t.status;
            if (t.status === 'active') status.classList.add('active');
            row.querySelector('.c-select').addEventListener('click', () => select(t));
            tbody.appendChild(row);
        }
    }

    async function load() {
        tbody.innerHTML = '<tr><td colspan="5" class="muted"><?= htmlspecialchars(trans('tenancy::tenancy.common.loading'), ENT_QUOTES, 'UTF-8') ?></td></tr>';
        try {
            const res = await window.TenancyApp.myTenants();
            render(res.data || []);
        } catch (e) {
            tbody.innerHTML = '<tr><td colspan="5"></td></tr>';
            window.TenancyApp.flash(e.message, 'error');
        }
    }

    async function select(t) {
        try {
            const res = await window.TenancyApp.selectTenant(t.tenantId);
            // The new tenant-scoped access token is returned once; persist it so
            // subsequent bearer-auth calls (if any) carry the tnt claim.
            if (res && res.token) {
                try { sessionStorage.setItem('tenancy.token', res.token); } catch (_) {}
            }
            window.TenancyApp.flash(<?= json_encode(trans('tenancy::tenancy.index.scoped'), JSON_THROW_ON_ERROR) ?>
                .replace(':name', t.name).replace(':role', res.role || t.role));
        } catch (e) {
            window.TenancyApp.flash(e.message, 'error');
        }
    }

    document.getElementById('reload').addEventListener('click', load);
    load();
})();
</script>
