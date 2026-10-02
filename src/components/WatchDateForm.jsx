import { useState } from "react";

function localDateTimeValue(date = new Date()) {
  const pad = (value) => String(value).padStart(2, "0");
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

export function WatchDateForm({ detail, pending, onSave }) {
  const [expanded, setExpanded] = useState(false);
  const [watchedAt, setWatchedAt] = useState(localDateTimeValue);
  const [error, setError] = useState("");

  async function submit(event) {
    event.preventDefault();
    const date = new Date(watchedAt);
    if (!watchedAt || !Number.isFinite(date.getTime()) || date > new Date()) {
      setError("Choose a date and time in the past.");
      return;
    }
    setError("");
    const saved = await onSave(detail, date.toISOString());
    if (saved) setExpanded(false);
  }

  return (
    <div className="watch-date-control">
      <button className="text-action" type="button" aria-expanded={expanded} disabled={pending} onClick={() => setExpanded(!expanded)}>
        {expanded ? "Cancel date selection" : "Log a past watch"}
      </button>
      {expanded ? (
        <form className="watch-date-form" onSubmit={submit}>
          <label>Watched at
            <input type="datetime-local" value={watchedAt} max={localDateTimeValue()} required disabled={pending} onChange={(event) => { setWatchedAt(event.target.value); setError(""); }} />
          </label>
          <span className="watch-date-hint">Your local date and time. Adds a separate watch.</span>
          <button className="secondary-action" type="submit" disabled={pending}>{pending ? "Saving…" : "Save watch"}</button>
          {error ? <span role="alert">{error}</span> : null}
        </form>
      ) : null}
    </div>
  );
}
