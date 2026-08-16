import { useEffect, useState, type ReactNode } from "react";
import { Link } from "@pageflow/react";
import { AdminLayout } from "@pageflow/admin";
import { Button } from "@ui/button";
import { Input } from "@ui/input";
import { Alert, AlertDescription } from "@ui/alert";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@ui/select";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@ui/table";
import { StatusBadge, useTenancy, type TenantDetail } from "@tenancy";

const PAGE_SIZE = 50;
const STATUSES = ["active", "provisioning", "suspended", "deleted"] as const;

// ADMIN page contributed by the Tenancy PLUGIN → component "Tenant/Manage".
// Server: TenantPageController@manage. Platform-admin control plane for the whole
// fleet, backed by GET/DELETE /ajx/admin/tenants (requires platform-admin access).
//
// The endpoint is paginated (50/page server-side cap) rather than returning the
// whole registry, so a fleet numbering in the thousands doesn't load every row
// into the browser on every visit — search/status narrow it, Prev/Next page it.
export default function TenantManage() {
  const api = useTenancy();
  const [tenants, setTenants] = useState<TenantDetail[]>([]);
  const [total, setTotal] = useState(0);
  const [offset, setOffset] = useState(0);
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState<string>("");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  async function load() {
    setLoading(true);
    setError(null);
    try {
      const page = await api.adminTenants({ limit: PAGE_SIZE, offset, search, status });
      setTenants(page.data ?? []);
      setTotal(page.total);
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [offset, search, status]);

  function onSearchChange(value: string) {
    setOffset(0); // a new filter always starts back at page one
    setSearch(value);
  }

  function onStatusChange(value: string) {
    setOffset(0);
    setStatus(value === "all" ? "" : value);
  }

  async function remove(t: TenantDetail) {
    if (!window.confirm(`Delete tenant "${t.name}"? This drops its database user and registry row.`)) {
      return;
    }
    const dropDatabase = window.confirm(
      `Also DROP the tenant database "${t.dbName}"? All its data is lost. OK = drop, Cancel = keep.`,
    );
    try {
      await api.adminDeleteTenant(t.tenantId, dropDatabase);
      await load();
    } catch (e) {
      setError((e as Error).message);
    }
  }

  async function toggleSuspend(t: TenantDetail) {
    try {
      if (t.status === "suspended") {
        await api.adminReactivateTenant(t.tenantId);
      } else {
        await api.adminSuspendTenant(t.tenantId);
      }
      await load();
    } catch (e) {
      setError((e as Error).message);
    }
  }

  return (
    <main className="mx-auto max-w-4xl p-8">
      <header className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Tenants</h1>
          <p className="text-sm text-muted-foreground">
            Control plane for the whole fleet. Requires platform-admin access.
          </p>
        </div>
        <Button asChild>
          <Link href="/tenants/create">+ New tenant</Link>
        </Button>
      </header>

      {error && (
        <Alert variant="destructive" className="mb-4">
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}

      <div className="mb-4 flex items-center gap-2">
        <Input
          value={search}
          onChange={(e) => onSearchChange(e.target.value)}
          placeholder="Search by name or slug…"
          className="max-w-xs"
        />
        <Select value={status || "all"} onValueChange={onStatusChange}>
          <SelectTrigger className="w-40">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All statuses</SelectItem>
            {STATUSES.map((s) => (
              <SelectItem key={s} value={s}>
                {s[0].toUpperCase() + s.slice(1)}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      {loading ? (
        <p className="text-sm text-muted-foreground">Loading…</p>
      ) : tenants.length === 0 ? (
        <p className="text-sm text-muted-foreground">
          {search || status ? "No tenants match this filter." : "No tenants yet. Create the first one."}
        </p>
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Name</TableHead>
              <TableHead>Slug</TableHead>
              <TableHead>Database</TableHead>
              <TableHead>Status</TableHead>
              <TableHead className="text-right">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {tenants.map((t) => (
              <TableRow key={t.tenantId}>
                <TableCell className="font-medium">{t.name}</TableCell>
                <TableCell className="text-muted-foreground">{t.slug}</TableCell>
                <TableCell className="text-muted-foreground">
                  {t.dbDriver} · {t.dbName} @ {t.dbHost}:{t.dbPort}
                </TableCell>
                <TableCell>
                  <StatusBadge status={t.status} />
                </TableCell>
                <TableCell className="space-x-1 text-right">
                  <Button variant="ghost" size="sm" asChild>
                    <Link href={`/tenants/${t.tenantId}/edit`}>Edit</Link>
                  </Button>
                  {(t.status === "active" || t.status === "suspended") && (
                    <Button variant="ghost" size="sm" onClick={() => toggleSuspend(t)}>
                      {t.status === "suspended" ? "Reactivate" : "Suspend"}
                    </Button>
                  )}
                  <Button variant="ghost" size="sm" onClick={() => remove(t)}>
                    Delete
                  </Button>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}

      {!loading && total > 0 && (
        <div className="mt-4 flex items-center justify-between text-sm text-muted-foreground">
          <span>
            Showing {offset + 1}–{Math.min(offset + PAGE_SIZE, total)} of {total}
          </span>
          <div className="space-x-2">
            <Button
              variant="outline"
              size="sm"
              disabled={offset === 0}
              onClick={() => setOffset((o) => Math.max(0, o - PAGE_SIZE))}
            >
              Previous
            </Button>
            <Button
              variant="outline"
              size="sm"
              disabled={offset + PAGE_SIZE >= total}
              onClick={() => setOffset((o) => o + PAGE_SIZE)}
            >
              Next
            </Button>
          </div>
        </div>
      )}
    </main>
  );
}

TenantManage.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
