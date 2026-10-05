import { useState } from "react";
import { apiRequest } from "../../lib/api.js";
import { ResourceState, useResource } from "./shared.jsx";

export function ViewingStory({ range, onRange, data, apiClient = apiRequest, onSessionExpired }) {
  const [year, setYear] = useState(new Date().getFullYear());
  const [compareYear, setCompareYear] = useState(new Date().getFullYear() - 1);
  const comparison = useResource(`/api/v1/stats?from=${compareYear}-01-01&to=${compareYear}-12-31`, apiClient, onSessionExpired);
  const [draft, setDraft] = useState(range);
  const days = data.dailyActivity || [];
  const maximum = Math.max(1, ...days.map(day => day.watches));
  function selectYear(value) {
    setYear(value);
    const next = { from: `${value}-01-01`, to: `${value}-12-31` };
    setDraft(next); onRange(next);
  }
  function downloadRecap() {
    const canvas = document.createElement("canvas"); canvas.width = 1200; canvas.height = 800;
    const ctx = canvas.getContext("2d");
    ctx.fillStyle = "#11161c"; ctx.fillRect(0, 0, 1200, 800);
    ctx.fillStyle = "#75adff"; ctx.font = "bold 30px sans-serif"; ctx.fillText("MEDIAHUB · MY VIEWING STORY", 70, 90);
    ctx.fillStyle = "#ffffff"; ctx.font = "bold 55px sans-serif";
    ctx.fillText(range.from && range.to ? `${range.from} — ${range.to}` : "My all-time viewing story", 70, 200);
    ctx.font = "36px sans-serif";
    const summary = data.summary || {};
    [`${summary.moviesWatched || 0} movie watches`, `${summary.episodesWatched || 0} episodes`, `${Math.round(summary.totalWatchHours || 0)} hours of stories`, `Favorite genres: ${(data.genres || []).slice(0, 3).map(g => g.genre).join(", ") || "Still discovering"}`].forEach((line, index) => ctx.fillText(line, 70, 320 + index * 90, 1060));
    const link = document.createElement("a"); link.download = "mediahub-viewing-story.png"; link.href = canvas.toDataURL("image/png"); link.click();
  }
  return <section className="v2-panel"><h3>Your viewing story</h3><form className="v2-inline" onSubmit={e => { e.preventDefault(); onRange(draft); }}><label>From<input type="date" required value={draft.from} max={draft.to || undefined} onChange={e => setDraft({ ...draft, from: e.target.value })} /></label><label>To<input type="date" required value={draft.to} min={draft.from || undefined} onChange={e => setDraft({ ...draft, to: e.target.value })} /></label><button className="secondary-action">Apply dates</button><button className="text-action" type="button" onClick={() => { setDraft({ from: "", to: "" }); onRange({ from: "", to: "" }); }}>All time</button></form>
    <div className="v2-inline"><label>Year in review<select value={year} onChange={e => selectYear(Number(e.target.value))}>{Array.from({ length: 40 }, (_, i) => new Date().getFullYear() - i).map(value => <option key={value}>{value}</option>)}</select></label><button className="secondary-action" type="button" onClick={() => selectYear(year)}>Show year</button><button className="secondary-action" type="button" onClick={downloadRecap}>Download recap card</button></div>
    <h4>Activity by day</h4><p>{data.range?.timezone || "Your timezone"} · brighter tiles mean more watches. Only days with activity are shown.</p><div className="v2-heatmap">{days.map(day => <button type="button" key={day.period} aria-label={`${day.period}: ${day.watches} watches, ${day.minutes} minutes`} title={`${day.period}: ${day.watches} watches`} style={{ opacity: 0.25 + 0.75 * day.watches / maximum }} onClick={() => { setDraft({ from: day.period, to: day.period }); onRange({ from: day.period, to: day.period }); }}>{day.period.slice(5)}</button>)}</div>{!days.length && <p>No watches in this period.</p>}
    <div className="v2-inline"><label>Compare with year<select value={compareYear} onChange={e => setCompareYear(Number(e.target.value))}>{Array.from({ length: 40 }, (_, i) => new Date().getFullYear() - i).map(value => <option key={value}>{value}</option>)}</select></label></div><ResourceState resource={comparison} />{comparison.data && <table className="v2-table"><thead><tr><th>Measure</th><th>Selected period</th><th>{compareYear}</th></tr></thead><tbody>{[["Movies", "moviesWatched"], ["Episodes", "episodesWatched"], ["Hours", "totalWatchHours"]].map(([label, key]) => <tr key={key}><th>{label}</th><td>{data.summary?.[key] || 0}</td><td>{comparison.data.summary?.[key] || 0}</td></tr>)}</tbody></table>}
    <details><summary>Genre and rating trends</summary><p>Genres reflect movie watches by month. Ratings reflect the month you last rated each title.</p><div className="v2-form-grid"><div>{(data.genreTrends || []).map(row => <p key={row.period}><strong>{row.period}</strong> · {row.genres.slice(0, 3).map(g => `${g.genre} (${g.count})`).join(", ")}</p>)}</div><div>{(data.ratingTrends || []).map(row => <p key={row.period}><strong>{row.period}</strong> · {row.average}/10 across {row.count} ratings</p>)}</div></div></details>
  </section>;
}
