// @vitest-environment jsdom
import React from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import "@testing-library/jest-dom/vitest";
import { WatchHistoryEditor } from "./WatchHistoryEditor.jsx";
import { MovieNights } from "./MovieNights.jsx";
import { CollectionTools } from "./Collections.jsx";
import { cacheOfflinePage, clearOffline, queueOfflineWatch, readOffline, syncOffline } from "../../lib/offline.js";

afterEach(() => { cleanup(); vi.restoreAllMocks(); clearOffline(); });

it("edits a single watch and undoes it without logging another watch", async () => {
  const watch = { id: 9, watchedAt: '2024-01-01T20:00:00Z', source: 'manual', count: 1 };
  const api = vi.fn(async (path, options) => options?.method === 'PATCH' ? { undoId: 11 } : options?.method === 'POST' ? null : { items: [watch], total: 1, page: 1, pages: 1 });
  render(<WatchHistoryEditor detail={{ id: 2, kind: 'movie' }} apiClient={api} />);
  fireEvent.click(await screen.findByRole('button', { name: 'Edit date' }));
  fireEvent.change(screen.getByLabelText('Correct watch date'), { target: { value: '2024-02-01T20:00' } });
  fireEvent.click(screen.getByRole('button', { name: 'Save date' }));
  await screen.findByText('Watch date updated.');
  expect(api).toHaveBeenCalledWith('/api/v1/history/movie/9', expect.objectContaining({ method: 'PATCH' }));
  fireEvent.click(screen.getByRole('button', { name: 'Undo last change' }));
  await screen.findByText('Change undone.');
  expect(api).toHaveBeenCalledWith('/api/v1/history/undo/11', { method: 'POST' });
});

it("keeps a successful mutation out of a failed-refresh retry", async () => {
  let reads = 0;
  const api = vi.fn(async (path, options) => {
    if (options?.method === 'PATCH') return { undoId: 1 };
    reads++;
    if (reads === 2) throw new Error('Temporary read failure');
    return { items: [{ id: 3, watchedAt: '2024-01-01T20:00:00Z', source: 'manual', count: 1 }], total: 1, pages: 1 };
  });
  render(<WatchHistoryEditor detail={{ id: 2, kind: 'movie' }} apiClient={api} />);
  fireEvent.click(await screen.findByRole('button', { name: 'Edit date' }));
  fireEvent.click(screen.getByRole('button', { name: 'Save date' }));
  await screen.findByText(/Saved, but the view could not refresh/);
  fireEvent.click(screen.getByRole('button', { name: 'Retry', exact: true }));
  await screen.findByText('Watch date updated.');
  expect(api.mock.calls.filter(([, options]) => options?.method === 'PATCH')).toHaveLength(1);
});

it("creates a rule-based collection and requires an explicit sharing action", async () => {
  const api = vi.fn().mockResolvedValue({ list: { id: 8 } });
  render(<CollectionTools apiClient={api} />);
  fireEvent.click(screen.getByText('Create a smart collection'));
  fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Short thrillers' } });
  fireEvent.click(screen.getByLabelText('Update automatically using rules'));
  fireEvent.change(screen.getByLabelText('Genre'), { target: { value: 'Thriller' } });
  fireEvent.change(screen.getByLabelText('Maximum runtime'), { target: { value: '100' } });
  fireEvent.click(screen.getByRole('button', { name: 'Create collection' }));
  await screen.findByText('Collection created.');
  expect(api).toHaveBeenCalledWith('/api/v1/lists', expect.objectContaining({ body: expect.objectContaining({ rules: { type: 'movie', unwatched: true, genre: 'Thriller', max_runtime: '100' } }) }));
  expect(api.mock.calls.some(([path]) => path.endsWith('/share'))).toBe(false);
});

it("hides spoiler notes until a friend chooses to reveal them", async () => {
  const api = vi.fn(async path => path.includes('experience-settings') ? { settings: { share_activity: false } } : { rooms: [], friends: [], invites: [], activity: [], recommendations: [{ id: 1, title: 'A mystery', from: 'Alex', spoiler: true, message: 'The ending is a dream.' }] });
  render(<MovieNights apiClient={api} />);
  const note = await screen.findByText('The ending is a dream.');
  expect(note).not.toBeVisible();
  fireEvent.click(screen.getByText('Reveal spoiler note'));
  expect(note).toBeVisible();
});

describe('offline outbox', () => {
  beforeEach(() => {
    clearOffline();
    Object.defineProperty(navigator, 'serviceWorker', { configurable: true, value: { register: vi.fn().mockResolvedValue({}), ready: Promise.resolve({}) } });
  });
  it('keeps watches queued while another saved page waits for its shell', async () => {
    const api = vi.fn().mockResolvedValue({ user: { id: 7 } });
    const item = { id: 3, kind: 'movie', title: 'Mine' };
    await cacheOfflinePage('Movies', [item], api);
    let installed;
    navigator.serviceWorker.ready = new Promise(resolve => { installed = resolve; });
    const pendingSave = cacheOfflinePage('Favorites', [item], api);
    await Promise.resolve();
    const watch = queueOfflineWatch(item, '2024-01-01T20:00:00Z');
    installed({});
    await pendingSave;
    expect(readOffline().entries[0].id).toBe(watch.id);
    expect(readOffline().pages).toHaveLength(2);
  });
  it('reuses the same entry ID after a lost response and preserves the pending entry', async () => {
    const api = vi.fn(async (path, options) => {
      if (path === '/api/v1/me') return { user: { id: 7 } };
      if (api.mock.calls.filter(([p]) => p.includes('offline/watches')).length === 1) throw new Error('Network lost');
      return { accepted: options.body.entries.map(entry => entry.id) };
    });
    const item = { id: 3, movieId: 3, kind: 'movie', title: 'Offline movie' };
    await cacheOfflinePage('Movies', [item], api);
    const entry = queueOfflineWatch(item, '2024-01-01T20:00:00Z');
    await expect(syncOffline(api)).rejects.toThrow('Network lost');
    expect(readOffline().entries[0].id).toBe(entry.id);
    await expect(syncOffline(api)).resolves.toBe(1);
    expect(readOffline().entries).toEqual([]);
    const writes = api.mock.calls.filter(([path]) => path.includes('offline/watches'));
    expect(writes[0][1].body).toEqual(writes[1][1].body);
  });
  it('refuses synchronization to a different signed-in account', async () => {
    const item = { id: 3, kind: 'movie', title: 'Mine' };
    await cacheOfflinePage('Movies', [item], vi.fn().mockResolvedValue({ user: { id: 7 } }));
    queueOfflineWatch(item, '2024-01-01T20:00:00Z');
    const api = vi.fn().mockResolvedValue({ user: { id: 8 } });
    await expect(syncOffline(api)).rejects.toThrow('account that saved');
    expect(api).toHaveBeenCalledTimes(1);
    expect(readOffline().entries).toHaveLength(1);
  });
});
