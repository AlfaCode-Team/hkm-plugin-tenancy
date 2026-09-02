import "@testing-library/jest-dom/vitest";
import { cleanup } from "@testing-library/react";
import { afterEach } from "vitest";

// Without this, a component left mounted by one test is still in the
// document for the next, and a passing assertion may be reading the
// previous test's render.
afterEach(() => cleanup());