import { useEffect, useRef, useState } from "react";
import { SessionExpiredError } from "./api.js";

// A refresh failure retries only the read, never a write that already succeeded.
export function useAsyncAction(onSessionExpired) {
  const [state, setState] = useState({ pending: false, error: "", status: "", refreshOnly: false });
  const busy = useRef(false);
  const mounted = useRef(true);
  const retry = useRef(null);
  const sessionExpired = useRef(onSessionExpired);
  sessionExpired.current = onSessionExpired;
  useEffect(() => { mounted.current = true; return () => { mounted.current = false; }; }, []);

  async function run(operation, refreshOnly = false) {
    if (busy.current || !mounted.current) return false;
    busy.current = true;
    retry.current = null;
    setState({ pending: true, error: "", status: refreshOnly ? "Refreshing…" : operation.pending || "Saving…", refreshOnly });
    let saved = refreshOnly;
    try {
      if (!refreshOnly) {
        const result = await operation.save();
        saved = true;
        if (!mounted.current) return true;
        operation.onSaved?.(result);
      }
      if (!mounted.current) return saved;
      await operation.refresh?.();
      if (mounted.current) setState({ pending: false, error: "", status: operation.success || "Saved.", refreshOnly: false });
      return true;
    } catch (error) {
      if (!mounted.current) return saved;
      if (error instanceof SessionExpiredError) {
        setState({ pending: false, error: "", status: "", refreshOnly: false });
        sessionExpired.current?.();
      } else {
        retry.current = () => run(operation, saved);
        setState({ pending: false, status: "", refreshOnly: saved, error: saved ? "Saved, but the view could not refresh. Retry the refresh to see the latest changes." : error.message || operation.failure || "Could not save this change. Try again." });
      }
      return saved;
    } finally {
      busy.current = false;
    }
  }

  function clear() {
    if (busy.current) return;
    retry.current = null;
    setState({ pending: false, error: "", status: "", refreshOnly: false });
  }

  return { ...state, run, clear, retry: () => retry.current?.() };
}
