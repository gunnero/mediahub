// @vitest-environment jsdom
import { afterEach, expect, it, vi } from "vitest";
import { registerServiceWorker } from "./serviceWorker.js";

afterEach(() => {
  document.querySelector('meta[name="mediahub-build"]')?.remove();
  vi.unstubAllGlobals();
});

it("registers the current release's worker without reusing a cached previous URL", async () => {
  const meta = document.createElement("meta");
  meta.name = "mediahub-build";
  meta.content = "0123456789abcdef";
  document.head.append(meta);
  const registration = { scope: "https://example.test/" };
  const register = vi.fn().mockResolvedValue(registration);
  vi.stubGlobal("navigator", { serviceWorker: { register } });
  expect(await registerServiceWorker()).toBe(registration);
  expect(register).toHaveBeenCalledWith("/sw.js?build=0123456789abcdef", { updateViaCache: "none" });
  meta.content = "abcdef0123456789";
  await registerServiceWorker();
  expect(register).toHaveBeenLastCalledWith("/sw.js?build=abcdef0123456789", { updateViaCache: "none" });
});

it("keeps the development worker URL when build metadata is absent", async () => {
  const register = vi.fn().mockResolvedValue({});
  vi.stubGlobal("navigator", { serviceWorker: { register } });
  await registerServiceWorker();
  expect(register).toHaveBeenCalledWith("/sw.js", { updateViaCache: "none" });
});
