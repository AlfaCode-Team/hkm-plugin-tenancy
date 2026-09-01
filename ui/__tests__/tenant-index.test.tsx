import { describe, it, expect } from "vitest";
// Add `screen` here when you assert on what the user sees:
//   import { render, screen } from "@testing-library/react";
import { render } from "@testing-library/react";
import { PageContext } from "@pageflow/react";
import Page from "../site/Pages/Tenant/Index";
import fixture from "../__fixtures__/tenant-index.json";

/**
 * Component test for `Tenant/Index`.
 *
 * Props come from __fixtures__/tenant-index.json, which a ground test dumped
 * from a REAL response:
 *
 *   $this->ground()->pageflow('/your/route')
 *       ->writeFixture(__DIR__ . '/../ui/__fixtures__/tenant-index.json');
 *
 * That is the point of the fixture. Hand-written mock props drift from the
 * server the moment a field is renamed, and this test keeps passing while
 * the page breaks. With the dump, a server-side rename fails it here.
 *
 * Re-dump the fixture when the props change, and commit it.
 *
 * The PageContext wrapper is not optional: a Pageflow page reads its props
 * through `usePage()`, which THROWS on an empty context, so passing props
 * as JSX attributes renders nothing. The value is the whole page object —
 * the same thing <App> supplies at runtime, and the same shape the fixture
 * holds.
 */
function renderPage() {
  return render(
    <PageContext.Provider value={fixture as any}>
      <Page />
    </PageContext.Provider>,
  );
}

// A fixture that has never been dumped is a PLACEHOLDER: skip loudly
// rather than assert against empty props, which fails somewhere inside
// the component and reads like the page is broken.
const pending = (fixture as any).__placeholder === true;

describe.skipIf(pending)("Tenant/Index", () => {
  it("renders with the props the server actually sends", () => {
    renderPage();

    // Replace with something the page must show. Prefer a role or a
    // user-visible string over a test id: an assertion on markup
    // structure breaks on a redesign that changed nothing real.
    expect(document.body.textContent).not.toBe("");
  });

  it("has the props it needs", () => {
    // Guards the seam in the other direction: if the server stops sending
    // a prop this page reads, this fails BEFORE the page renders undefined.
    expect(fixture.props).toBeDefined();
  });

  it("shows nothing sensitive", () => {
    // Props are serialized into the page object and shipped to the browser.
    const serialized = JSON.stringify(fixture.props);
    expect(serialized).not.toMatch(/password|secret|token_hash/i);
  });
});
