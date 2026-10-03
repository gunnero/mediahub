import { useCallback, useEffect, useRef, useState } from "react";
import { apiRequest, SessionExpiredError } from "../../lib/api.js";
export { useAsyncAction } from "../../lib/useAsyncAction.js";

export function useResource(path, apiClient = apiRequest, onSessionExpired) {
  const [state, setState] = useState({ data: null, loading: true, error: "" });
  const sequence = useRef(0);
  const expired = useRef(onSessionExpired);
  expired.current = onSessionExpired;
  const refresh = useCallback(async () => {
    const token = ++sequence.current;
    setState(value => ({ ...value, loading: true, error: "" }));
    try {
      const data = await apiClient(path);
      if (token === sequence.current) setState({ data, loading: false, error: "" });
    } catch (error) {
      if (token === sequence.current) {
        if (error instanceof SessionExpiredError) expired.current?.();
        setState(value => ({ ...value, loading: false, error: error.message || "Could not load. Try again." }));
      }
      throw error;
    }
  }, [apiClient, path]);
  useEffect(() => { refresh().catch(() => {}); return () => { sequence.current++; }; }, [refresh]);
  return { ...state, refresh };
}
export function Feedback({ action }) {
  return <>{action.status && <p role="status" className="settings-status">{action.status}</p>}{action.error && <p role="alert" className="detail-error">{action.error} <button className="text-action" onClick={action.retry} disabled={action.pending} type="button">Retry</button></p>}</>;
}
export function ResourceState({ resource }) {
  return <>{resource.loading && <p role="status">Loading…</p>}{resource.error && <p role="alert" className="detail-error">{resource.error} <button type="button" className="text-action" onClick={() => resource.refresh().catch(() => {})}>Retry loading</button></p>}</>;
}
export function localDateTime(value) {
  const date = new Date(value);
  return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
}
