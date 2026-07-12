import { useEffect, useRef, useState } from "react";
import {
  Camera,
  CaretDown,
  Check,
  Copy,
  GearSix,
  PencilSimple,
  ShareNetwork,
  ShieldCheck,
  SignOut,
  UserCircle,
  UserPlus,
  UsersThree,
  X,
} from "@phosphor-icons/react";
import { apiRequest, apiUpload, SessionExpiredError } from "../lib/api.js";
import { countries } from "../data/countries.js";

const privacyDefaults = {
  publicProfileEnabled: false,
  showAvatar: false,
  profileVisibility: "private",
  showStatistics: false,
  showFavoriteMovies: false,
  showFavoriteShows: false,
  showPublicLists: false,
  showRecentActivity: false,
  allowFriendRequests: false,
  allowProfileSharing: false,
  allowSearchDiscovery: false,
};

const accountItems = [
  ["view-profile", "View Profile", UserCircle],
  ["edit-profile", "Edit Profile", PencilSimple],
  ["friends", "Friends", UsersThree],
  ["invite-friends", "Invite Friends", UserPlus],
  ["privacy", "Privacy", ShieldCheck],
  ["settings", "Settings", GearSix],
];

const publicStatLabels = {
  episodesWatched: "Episodes watched",
  moviesWatched: "Movies watched",
  hoursWatched: "Hours watched",
  showsFollowed: "Shows followed",
};

function Avatar({ profile, size = "large" }) {
  if (profile?.avatar) {
    return <img className={`social-avatar ${size}`} alt="" src={profile.avatar} />;
  }

  return <span className={`social-avatar fallback ${size}`}><UserCircle weight="duotone" /></span>;
}

function loadFailure(error, fallback) {
  return error instanceof SessionExpiredError ? "Sign in to continue." : (error.message || fallback);
}

export function AccountMenu({ onLogout, onNavigate, profile = {} }) {
  const [open, setOpen] = useState(false);
  const [loggingOut, setLoggingOut] = useState(false);
  const rootRef = useRef(null);
  const triggerRef = useRef(null);
  const menuRef = useRef(null);

  useEffect(() => {
    function closeOutside(event) {
      if (open && rootRef.current && !rootRef.current.contains(event.target)) setOpen(false);
    }
    function closeEscape(event) {
      if (open && event.key === "Escape") {
        setOpen(false);
        triggerRef.current?.focus();
      }
    }
    document.addEventListener("mousedown", closeOutside);
    document.addEventListener("keydown", closeEscape);
    return () => {
      document.removeEventListener("mousedown", closeOutside);
      document.removeEventListener("keydown", closeEscape);
    };
  }, [open]);

  function navigate(action) {
    setOpen(false);
    onNavigate?.(action);
  }

  function keyboard(event) {
    if (!["ArrowDown", "ArrowUp", "Home", "End"].includes(event.key)) return;
    event.preventDefault();
    const items = Array.from(menuRef.current?.querySelectorAll('[role="menuitem"]') || []);
    if (!items.length) return;
    const current = items.indexOf(document.activeElement);
    let next = 0;
    if (event.key === "End") next = items.length - 1;
    else if (event.key === "ArrowUp") next = current <= 0 ? items.length - 1 : current - 1;
    else if (event.key === "ArrowDown") next = current < 0 || current === items.length - 1 ? 0 : current + 1;
    items[next].focus();
  }

  function triggerKeyboard(event) {
    if (!["ArrowDown", "ArrowUp"].includes(event.key)) return;
    event.preventDefault();
    setOpen(true);
    window.requestAnimationFrame(() => {
      const items = Array.from(menuRef.current?.querySelectorAll('[role="menuitem"]') || []);
      items[event.key === "ArrowUp" ? items.length - 1 : 0]?.focus();
    });
  }

  async function logout() {
    setLoggingOut(true);
    try {
      await onLogout?.();
    } finally {
      setLoggingOut(false);
      setOpen(false);
    }
  }

  return <div className="account-menu" ref={rootRef}>
    <button
      aria-controls="account-dropdown"
      aria-expanded={open}
      aria-haspopup="menu"
      aria-label="Open account menu"
      className="profile-menu"
      disabled={loggingOut}
      onClick={() => setOpen((value) => !value)}
      onKeyDown={triggerKeyboard}
      ref={triggerRef}
      type="button"
    >
      <Avatar profile={profile} size="compact" />
      <span>{profile.displayName || profile.name || profile.username || "Account"}</span>
      <CaretDown className={open ? "rotated" : ""} size={16} />
    </button>
    {open ? <div aria-label="Account" className="account-dropdown" id="account-dropdown" onKeyDown={keyboard} ref={menuRef} role="menu">
      <header><strong>{profile.displayName || profile.name || "MediaHub member"}</strong><span>@{profile.username || "member"}</span></header>
      {accountItems.map(([action, label, Icon]) => <button key={action} onClick={() => navigate(action)} role="menuitem" type="button"><Icon /><span>{label}</span></button>)}
      <div className="account-menu-divider" />
      <button className="danger" disabled={loggingOut} onClick={logout} role="menuitem" type="button"><SignOut /><span>{loggingOut ? "Signing out..." : "Logout"}</span></button>
    </div> : null}
  </div>;
}

export function OwnProfileSection({ apiClient = apiRequest, editInitially = false, onOpenPrivacy, onSessionExpired, uploadClient = apiUpload }) {
  const [state, setState] = useState({ loading: true, error: "", profile: null, privacy: null, options: null });
  const [editing, setEditing] = useState(editInitially);
  const [form, setForm] = useState({ username: "", display_name: "", full_name: "", profile_slug: "", bio: "", country: "", public_profile_enabled: false, profile_visibility: "private", show_avatar: false, favorite_genres: "", favorite_movie_ids: [], favorite_show_ids: [], featured_list_ids: [] });
  const [status, setStatus] = useState("");
  const [avatarFile, setAvatarFile] = useState(null);
  const [avatarPreview, setAvatarPreview] = useState("");
  const [avatarProgress, setAvatarProgress] = useState(0);
  const [avatarBusy, setAvatarBusy] = useState(false);
  const [avatarError, setAvatarError] = useState("");

  async function load() {
    setState((current) => ({ ...current, loading: true, error: "" }));
    try {
      const [payload, options] = await Promise.all([
        apiClient("/api/v1/profile"),
        apiClient("/api/v1/profile/options"),
      ]);
      const profile = payload.profile || {};
      setState({ loading: false, error: "", profile, privacy: payload.privacy || privacyDefaults, options: options || {} });
      setForm({
        username: profile.username || "",
        display_name: profile.displayName || "",
        full_name: profile.fullName || "",
        profile_slug: profile.slug || "",
        bio: profile.bio || "",
        country: profile.country || "",
        public_profile_enabled: Boolean(payload.privacy?.publicProfileEnabled),
        profile_visibility: payload.privacy?.profileVisibility || "private",
        show_avatar: Boolean(payload.privacy?.showAvatar),
        favorite_genres: (profile.favoriteGenres || []).join(", "),
        favorite_movie_ids: profile.favoriteMovieIds || [],
        favorite_show_ids: profile.favoriteShowIds || [],
        featured_list_ids: profile.featuredListIds || [],
      });
    } catch (error) {
      if (error instanceof SessionExpiredError) onSessionExpired?.();
      else setState((current) => ({ ...current, loading: false, error: loadFailure(error, "Profile could not be loaded.") }));
    }
  }

  useEffect(() => { load(); }, []);
  useEffect(() => setEditing(editInitially), [editInitially]);
  useEffect(() => () => {
    if (avatarPreview.startsWith("blob:")) URL.revokeObjectURL(avatarPreview);
  }, [avatarPreview]);

  function toggleSelection(key, id) {
    setForm((current) => ({ ...current, [key]: current[key].includes(id) ? current[key].filter((value) => value !== id) : [...current[key], id] }));
  }

  async function save(event) {
    event.preventDefault();
    setStatus("Saving profile...");
    try {
      const { profile_visibility, public_profile_enabled, show_avatar, ...identity } = form;
      await apiClient("/api/v1/profile", {
        method: "PATCH",
        body: {
          ...identity,
          favorite_genres: form.favorite_genres.split(",").map((value) => value.trim()).filter(Boolean),
        },
      });
      await apiClient("/api/v1/profile/privacy", {
        method: "PATCH",
        body: { profile_visibility, public_profile_enabled, show_avatar },
      });
      setStatus("Profile saved.");
      setEditing(false);
      await load();
    } catch (error) {
      setStatus("");
      setState((current) => ({ ...current, error: loadFailure(error, "Profile could not be saved.") }));
    }
  }

  function chooseAvatar(file) {
    setAvatarError("");
    if (!file) return;
    if (!["image/jpeg", "image/png", "image/webp"].includes(file.type)) {
      setAvatarError("Choose a JPG, PNG, or WEBP image.");
      return;
    }
    if (file.size > 5 * 1024 * 1024) {
      setAvatarError("Avatar images must be 5 MB or smaller.");
      return;
    }
    if (avatarPreview.startsWith("blob:")) URL.revokeObjectURL(avatarPreview);
    setAvatarFile(file);
    setAvatarPreview(typeof URL.createObjectURL === "function" ? URL.createObjectURL(file) : "");
    setAvatarProgress(0);
  }

  async function uploadAvatar() {
    if (!avatarFile) return;
    setAvatarBusy(true);
    setAvatarError("");
    try {
      const body = new FormData();
      body.append("avatar", avatarFile);
      await uploadClient("/api/v1/profile/avatar", body, setAvatarProgress);
      setAvatarFile(null);
      setAvatarPreview("");
      setStatus("Avatar saved.");
      await load();
    } catch (error) {
      if (error instanceof SessionExpiredError) onSessionExpired?.();
      else setAvatarError(error.message || "Avatar could not be uploaded.");
    } finally {
      setAvatarBusy(false);
    }
  }

  async function removeAvatar() {
    setAvatarBusy(true);
    setAvatarError("");
    try {
      await apiClient("/api/v1/profile/avatar", { method: "DELETE" });
      setAvatarFile(null);
      setAvatarPreview("");
      setAvatarProgress(0);
      setStatus("Default avatar restored.");
      await load();
    } catch (error) {
      if (error instanceof SessionExpiredError) onSessionExpired?.();
      else setAvatarError(error.message || "Avatar could not be removed.");
    } finally {
      setAvatarBusy(false);
    }
  }

  async function copyProfileLink() {
    if (!state.profile?.shareUrl) return;
    try {
      await navigator.clipboard.writeText(state.profile.shareUrl);
      setStatus("Profile link copied.");
    } catch {
      setStatus("Profile link could not be copied.");
    }
  }

  if (state.loading) return <section className="social-screen"><div className="empty-strip compact">Loading profile...</div></section>;
  if (state.error && !state.profile) return <section className="social-screen"><div className="detail-error">{state.error}</div></section>;
  const profile = state.profile || {};

  return <section className="web-v1-screen social-screen profile-screen">
    <header className="screen-intro"><span className="eyebrow">Your identity</span><h2>Profile</h2><p>Choose the identity people can see. Your email and private media data are never public.</p></header>
    {state.error ? <div className="detail-error">{state.error}</div> : null}{status ? <div className="settings-status">{status}</div> : null}
    {!editing ? <div className="profile-overview"><Avatar profile={profile} /><div><h3>{profile.displayName}</h3><span>@{profile.username}</span>{profile.fullName ? <small>{profile.fullName}</small> : null}<p>{profile.bio || "Add a short bio when you are ready."}</p><div className="profile-actions"><button className="primary-action" onClick={() => setEditing(true)} type="button"><PencilSimple /> Edit profile</button><a className="secondary-action" href={`/u/${profile.slug}?preview=public`}>View profile as public</a>{state.privacy?.publicProfileEnabled && state.privacy?.allowProfileSharing ? <button className="text-action" onClick={copyProfileLink} type="button"><Copy /> Copy profile link</button> : null}<button className="text-action" onClick={onOpenPrivacy} type="button"><ShieldCheck /> Privacy</button></div></div></div> : <form className="profile-form" onSubmit={save}>
      <AvatarEditor busy={avatarBusy} current={profile.avatar} error={avatarError} file={avatarFile} onChoose={chooseAvatar} onRemove={removeAvatar} onUpload={uploadAvatar} preview={avatarPreview} progress={avatarProgress} />
      <div className="profile-form-grid"><label><span>Display name</span><input aria-label="Display name" maxLength="80" onChange={(event) => setForm((current) => ({ ...current, display_name: event.target.value }))} value={form.display_name} /></label><label><span>Username</span><input aria-label="Username" maxLength="40" onChange={(event) => setForm((current) => ({ ...current, username: event.target.value }))} value={form.username} /></label><label><span>Full name</span><input aria-label="Full name" maxLength="120" onChange={(event) => setForm((current) => ({ ...current, full_name: event.target.value }))} value={form.full_name} /></label><label><span>Email</span><input aria-label="Email" readOnly type="email" value={profile.email || ""} /></label><label><span>Profile address</span><input aria-label="Profile address" maxLength="60" onChange={(event) => setForm((current) => ({ ...current, profile_slug: event.target.value.toLowerCase() }))} value={form.profile_slug} /></label><CountryPicker onChange={(country) => setForm((current) => ({ ...current, country }))} value={form.country} /><label><span>Profile visibility</span><select aria-label="Profile visibility" onChange={(event) => setForm((current) => ({ ...current, profile_visibility: event.target.value }))} value={form.profile_visibility}><option value="private">Private</option><option value="friends">Friends only</option><option value="public">Public</option></select></label><label className="profile-checkbox"><span>Public profile</span><input checked={form.public_profile_enabled} onChange={(event) => setForm((current) => ({ ...current, public_profile_enabled: event.target.checked }))} type="checkbox" /></label><label className="profile-checkbox"><span>Allow avatar on visible profile</span><input checked={form.show_avatar} onChange={(event) => setForm((current) => ({ ...current, show_avatar: event.target.checked }))} type="checkbox" /></label></div>
      <label><span>Bio</span><textarea aria-label="Bio" maxLength="500" onChange={(event) => setForm((current) => ({ ...current, bio: event.target.value }))} value={form.bio} /></label>
      <label><span>Favorite genres</span><input aria-label="Favorite genres" onChange={(event) => setForm((current) => ({ ...current, favorite_genres: event.target.value }))} placeholder="Drama, Comedy, Science Fiction" value={form.favorite_genres} /></label>
      <FavoritePicker label="Favorite movies" items={state.options?.movies || []} selected={form.favorite_movie_ids} onToggle={(id) => toggleSelection("favorite_movie_ids", id)} />
      <FavoritePicker label="Favorite shows" items={state.options?.shows || []} selected={form.favorite_show_ids} onToggle={(id) => toggleSelection("favorite_show_ids", id)} />
      <FavoritePicker label="Featured public lists" items={state.options?.publicLists || []} selected={form.featured_list_ids} onToggle={(id) => toggleSelection("featured_list_ids", id)} />
      <div className="modal-actions"><button className="primary-action" type="submit">Save profile</button><button className="text-action" onClick={() => setEditing(false)} type="button">Cancel</button></div>
    </form>}
  </section>;
}

function CountryPicker({ onChange, value }) {
  const selected = countries.find((country) => country.code === value);
  const [query, setQuery] = useState(selected ? `${selected.name} (${selected.code})` : "");

  useEffect(() => {
    const current = countries.find((country) => country.code === value);
    setQuery(current ? `${current.name} (${current.code})` : "");
  }, [value]);

  function change(event) {
    const next = event.target.value;
    const match = countries.find((country) => next === `${country.name} (${country.code})` || next.toUpperCase() === country.code);
    setQuery(next);
    if (match) onChange(match.code);
    else if (next === "") onChange("");
  }

  return <label><span>Country</span><input aria-label="Country" autoComplete="country-name" list="mediahub-countries" onBlur={() => { const current = countries.find((country) => country.code === value); setQuery(current ? `${current.name} (${current.code})` : ""); }} onChange={change} placeholder="Search countries" value={query} /><datalist id="mediahub-countries">{countries.map((country) => <option key={country.code} value={`${country.name} (${country.code})`} />)}</datalist></label>;
}

function AvatarEditor({ busy, current, error, file, onChoose, onRemove, onUpload, preview, progress }) {
  function receive(files) { onChoose(files?.[0] || null); }

  return <section className="avatar-editor">
    <div className="avatar-preview"><Avatar profile={{ avatar: preview || current }} /></div>
    <div className="avatar-editor-copy"><span className="eyebrow">Profile avatar</span><h3>Choose a square portrait</h3><p>JPG, PNG, or WEBP up to 5 MB. MediaHub crops and removes embedded metadata automatically.</p><label className="avatar-dropzone" onDragOver={(event) => event.preventDefault()} onDrop={(event) => { event.preventDefault(); receive(event.dataTransfer.files); }}><Camera size={22} /><span>{file ? file.name : "Drop an image here or click to upload"}</span><input accept="image/jpeg,image/png,image/webp" aria-label="Choose avatar" disabled={busy} onChange={(event) => receive(event.target.files)} type="file" /></label>{error ? <div className="detail-error">{error}</div> : null}{busy || progress > 0 ? <div className="avatar-progress"><progress max="100" value={progress} /><span>{busy ? `${progress}%` : "Ready"}</span></div> : null}<div className="modal-actions">{file ? <button className="secondary-action" disabled={busy} onClick={onUpload} type="button">Upload avatar</button> : null}{current ? <button className="text-action danger" disabled={busy} onClick={onRemove} type="button"><X /> Remove avatar</button> : <span className="avatar-default-note">Default avatar is active</span>}</div></div>
  </section>;
}

function FavoritePicker({ items, label, onToggle, selected }) {
  return <fieldset className="favorite-picker"><legend>{label}</legend>{items.length ? <div>{items.map((item) => <label key={item.id}><input checked={selected.includes(item.id)} onChange={() => onToggle(item.id)} type="checkbox" /><span>{item.title}</span></label>)}</div> : <p>No eligible items yet.</p>}</fieldset>;
}

export function PrivacyControls({ apiClient = apiRequest, onSessionExpired }) {
  const [privacy, setPrivacy] = useState(privacyDefaults);
  const [profile, setProfile] = useState(null);
  const [state, setState] = useState({ loading: true, error: "", status: "" });

  useEffect(() => {
    let cancelled = false;
    apiClient("/api/v1/profile").then((payload) => {
      if (!cancelled) {
        setPrivacy({ ...privacyDefaults, ...(payload.privacy || {}) });
        setProfile(payload.profile || null);
        setState({ loading: false, error: "", status: "" });
      }
    }).catch((error) => {
      if (error instanceof SessionExpiredError) onSessionExpired?.();
      else if (!cancelled) setState({ loading: false, error: loadFailure(error, "Privacy settings could not be loaded."), status: "" });
    });
    return () => { cancelled = true; };
  }, [apiClient, onSessionExpired]);

  function toggle(key) { setPrivacy((current) => ({ ...current, [key]: !current[key] })); }
  async function save() {
    setState((current) => ({ ...current, error: "", status: "Saving privacy..." }));
    try {
      await apiClient("/api/v1/profile/privacy", { method: "PATCH", body: {
        public_profile_enabled: privacy.publicProfileEnabled,
        show_avatar: privacy.showAvatar,
        profile_visibility: privacy.profileVisibility,
        show_statistics: privacy.showStatistics,
        show_favorite_movies: privacy.showFavoriteMovies,
        show_favorite_shows: privacy.showFavoriteShows,
        show_public_lists: privacy.showPublicLists,
        show_recent_activity: privacy.showRecentActivity,
        allow_friend_requests: privacy.allowFriendRequests,
        allow_profile_sharing: privacy.allowProfileSharing,
        allow_search_discovery: privacy.allowSearchDiscovery,
      } });
      setState((current) => ({ ...current, status: "Privacy saved." }));
    } catch (error) { setState((current) => ({ ...current, error: loadFailure(error, "Privacy could not be saved."), status: "" })); }
  }

  if (state.loading) return <div className="empty-strip compact">Loading privacy...</div>;
  const toggles = [
    ["publicProfileEnabled", "Enable public profile"],
    ["showAvatar", "Show avatar when profile visibility allows"],
    ["showStatistics", "Show statistics"],
    ["showFavoriteMovies", "Show favorite movies"],
    ["showFavoriteShows", "Show favorite shows"],
    ["showPublicLists", "Show public lists"],
    ["showRecentActivity", "Show recent activity"],
    ["allowFriendRequests", "Allow friend requests"],
    ["allowProfileSharing", "Allow profile sharing"],
    ["allowSearchDiscovery", "Allow search discovery"],
  ];

  return <div className="privacy-controls"><h3>Public Profile</h3><p>Everything is private until you explicitly enable it. Notes, providers, exports, email, and raw history are never included.</p>{state.error ? <div className="detail-error">{state.error}</div> : null}{state.status ? <div className="settings-status">{state.status}</div> : null}<label className="privacy-select"><span>Profile visibility</span><select aria-label="Profile visibility" onChange={(event) => setPrivacy((current) => ({ ...current, profileVisibility: event.target.value }))} value={privacy.profileVisibility}><option value="private">Private</option><option value="friends">Friends only</option><option value="public">Public</option></select></label><div className="preference-list">{toggles.map(([key, label]) => <label className="toggle-row" key={key}><span>{label}</span><input aria-label={label} checked={Boolean(privacy[key])} onChange={() => toggle(key)} type="checkbox" /></label>)}</div><div className="modal-actions"><button className="primary-action" onClick={save} type="button">Save privacy</button>{profile?.slug ? <a className="secondary-action" href={`/u/${profile.slug}?preview=public`}>View profile as public</a> : null}</div><small>Recent activity remains unpublished in V1 even when reserved for future opt-in use.</small></div>;
}

export function FriendsSection({ apiClient = apiRequest, onSessionExpired }) {
  const [data, setData] = useState({ friends: [], incoming: [], outgoing: [] });
  const [state, setState] = useState({ loading: true, error: "", status: "" });
  const [query, setQuery] = useState("");
  const [results, setResults] = useState([]);

  async function load() {
    try {
      const [friends, requests] = await Promise.all([apiClient("/api/v1/friends"), apiClient("/api/v1/friends/requests")]);
      setData({ friends: friends.friends || [], incoming: requests.incoming || [], outgoing: requests.outgoing || [] });
      setState((current) => ({ ...current, loading: false, error: "" }));
    } catch (error) {
      if (error instanceof SessionExpiredError) onSessionExpired?.();
      else setState({ loading: false, error: loadFailure(error, "Friends could not be loaded."), status: "" });
    }
  }
  useEffect(() => { load(); }, []);

  async function action(path, method = "POST") {
    setState((current) => ({ ...current, status: "Saving...", error: "" }));
    try { await apiClient(path, { method }); setState((current) => ({ ...current, status: "Saved." })); await load(); }
    catch (error) { setState((current) => ({ ...current, status: "", error: loadFailure(error, "Friend action failed.") })); }
  }
  async function search(event) {
    event.preventDefault();
    if (query.trim().length < 2) return;
    try { const payload = await apiClient(`/api/v1/profiles/search?query=${encodeURIComponent(query.trim())}`); setResults(payload.profiles || []); }
    catch (error) { setState((current) => ({ ...current, error: loadFailure(error, "Profile search failed.") })); }
  }

  return <section className="web-v1-screen social-screen friends-screen"><header className="screen-intro"><span className="eyebrow">People you choose</span><h2>Friends</h2><p>Friendships are mutual. There is no messaging or public activity feed in V1.</p></header>{state.error ? <div className="detail-error">{state.error}</div> : null}{state.status ? <div className="settings-status">{state.status}</div> : null}<form className="people-search" onSubmit={search}><input aria-label="Search profiles" onChange={(event) => setQuery(event.target.value)} placeholder="Search public profiles" value={query} /><button className="secondary-action" type="submit">Search</button></form><div className="people-results">{results.map((entry) => <PersonRow key={entry.slug} profile={entry} actions={entry.relationship?.canRequest ? <button className="text-action" onClick={() => action(`/api/v1/friends/request/${entry.slug}`)} type="button">Add friend</button> : <span>{entry.relationship?.status || "Unavailable"}</span>} />)}</div>{state.loading ? <div className="empty-strip compact">Loading friends...</div> : <div className="friends-grid"><section><h3>Requests</h3>{data.incoming.map((entry) => <PersonRow key={entry.friendshipId} profile={entry.profile} actions={<><button aria-label={`Accept ${entry.profile.displayName}`} className="text-action" onClick={() => action(`/api/v1/friends/${entry.friendshipId}/accept`)} type="button">Accept</button><button aria-label={`Decline ${entry.profile.displayName}`} className="text-action danger" onClick={() => action(`/api/v1/friends/${entry.friendshipId}/decline`)} type="button">Decline</button></>} />)}{!data.incoming.length ? <div className="empty-strip compact">No incoming requests</div> : null}<h3>Sent</h3>{data.outgoing.map((entry) => <PersonRow key={entry.friendshipId} profile={entry.profile} actions={<span>Pending</span>} />)}{!data.outgoing.length ? <div className="empty-strip compact">No pending requests</div> : null}</section><section><h3>Your friends</h3>{data.friends.map((entry) => <PersonRow key={entry.friendshipId} profile={entry.profile} actions={<><a className="text-action" href={`/u/${entry.profile.slug}`}>View profile</a><button className="text-action" onClick={() => action(`/api/v1/friends/${entry.friendshipId}`, "DELETE")} type="button">Remove</button><button className="text-action danger" onClick={() => action(`/api/v1/friends/${entry.profile.slug}/block`)} type="button">Block</button></>} />)}{!data.friends.length ? <div className="empty-strip compact">No friends yet</div> : null}</section></div>}</section>;
}

function PersonRow({ actions, profile }) {
  return <article className="person-row"><Avatar profile={profile} size="compact" /><div><strong>{profile.displayName}</strong><span>@{profile.username}</span></div><div>{actions}</div></article>;
}

export function InviteFriendsSection({ apiClient = apiRequest, onSessionExpired }) {
  const [invites, setInvites] = useState([]);
  const [current, setCurrent] = useState(null);
  const [state, setState] = useState({ loading: true, error: "", status: "" });

  async function load() {
    try { const payload = await apiClient("/api/v1/friend-invites"); setInvites(payload.invites || []); setState((value) => ({ ...value, loading: false })); }
    catch (error) { if (error instanceof SessionExpiredError) onSessionExpired?.(); else setState({ loading: false, error: loadFailure(error, "Invites could not be loaded."), status: "" }); }
  }
  useEffect(() => { load(); }, []);
  async function create() {
    setState((value) => ({ ...value, error: "", status: "Creating invite..." }));
    try { const payload = await apiClient("/api/v1/friend-invites", { method: "POST" }); setCurrent(payload.invite); setState((value) => ({ ...value, status: "Invite ready." })); await load(); }
    catch (error) { setState((value) => ({ ...value, error: loadFailure(error, "Invite could not be created."), status: "" })); }
  }
  async function copy() { if (!current?.url) return; await navigator.clipboard.writeText(current.url); setState((value) => ({ ...value, status: "Copied" })); }
  async function share() { if (!current?.url || !navigator.share) return; await navigator.share({ title: "Join me on MediaHub", url: current.url }); }
  async function revoke(id) { await apiClient(`/api/v1/friend-invites/${id}`, { method: "DELETE" }); await load(); }

  return <section className="web-v1-screen social-screen invite-screen"><header className="screen-intro"><span className="eyebrow">Invite intentionally</span><h2>Invite Friends</h2><p>Create a short-lived, revocable link. It never contains your email and does not expose your library.</p></header>{state.error ? <div className="detail-error">{state.error}</div> : null}{state.status ? <div className="settings-status">{state.status}</div> : null}<div className="invite-composer"><UserPlus weight="duotone" /><div><h3>Private invitation link</h3><p>The recipient chooses whether to accept. No friendship is created before confirmation.</p></div><button className="primary-action" onClick={create} type="button">Create invite link</button></div>{current ? <div className="share-link"><span>{current.url}</span><button aria-label="Copy invite link" className="secondary-action" onClick={copy} type="button"><Copy /> Copy link</button>{navigator.share ? <button className="text-action" onClick={share} type="button"><ShareNetwork /> Share</button> : null}</div> : null}<section className="invite-history"><h3>Recent invites</h3>{state.loading ? <div className="empty-strip compact">Loading invites...</div> : invites.map((invite) => <article key={invite.id}><div><strong>{invite.status}</strong><span>Expires {new Date(invite.expiresAt).toLocaleDateString()}</span></div>{!["accepted", "revoked", "expired"].includes(invite.status) ? <button className="text-action danger" onClick={() => revoke(invite.id)} type="button">Revoke</button> : null}</article>)}{!state.loading && !invites.length ? <div className="empty-strip compact">No invitations yet</div> : null}</section></section>;
}

export function PublicProfilePage({ apiClient = apiRequest, preview = false, slug }) {
  const [data, setData] = useState(null);
  const [state, setState] = useState({ loading: true, error: "", status: "" });
  useEffect(() => {
    let cancelled = false;
    const path = preview ? "/api/v1/profile/public-preview" : `/api/v1/profiles/${encodeURIComponent(slug)}`;
    apiClient(path).then((payload) => { if (!cancelled) { setData(payload); setState({ loading: false, error: "", status: "" }); } }).catch((error) => { if (!cancelled) setState({ loading: false, error: loadFailure(error, "Profile could not be loaded."), status: "" }); });
    return () => { cancelled = true; };
  }, [apiClient, preview, slug]);
  async function requestFriend() {
    try { await apiClient(`/api/v1/friends/request/${encodeURIComponent(slug)}`, { method: "POST" }); setState((value) => ({ ...value, status: "Request sent", error: "" })); setData((value) => ({ ...value, relationship: { status: "pending_outgoing", canRequest: false } })); }
    catch (error) { setState((value) => ({ ...value, error: loadFailure(error, "Friend request failed.") })); }
  }
  async function copy() { if (data?.shareUrl) { await navigator.clipboard.writeText(data.shareUrl); setState((value) => ({ ...value, status: "Profile link copied" })); } }
  async function share() { if (data?.shareUrl && navigator.share) await navigator.share({ title: `${profileName(data)} on MediaHub`, url: data.shareUrl }); }

  if (state.loading) return <PublicShell><div className="empty-strip compact">Loading profile...</div></PublicShell>;
  if (state.error && !data) return <PublicShell><div className="detail-error">{state.error}</div></PublicShell>;
  const profile = data?.profile || {};
  const content = data?.content || {};
  return <PublicShell><main className="public-profile"><section className="public-profile-identity"><Avatar profile={profile} /><div><span className="eyebrow">MediaHub profile</span><h1>{profile.displayName}</h1><strong>@{profile.username}</strong>{!profile.isPrivate ? <><p>{profile.bio || "No public bio yet."}</p>{profile.memberSince ? <small>Member since {new Date(profile.memberSince).toLocaleDateString()}</small> : null}</> : null}</div><div className="profile-actions">{data.relationship?.canRequest ? <button className="primary-action" onClick={requestFriend} type="button">Add friend</button> : null}{data.relationship?.status === "pending_outgoing" || state.status === "Request sent" ? <span className="status-chip"><Check /> Request sent</span> : null}{profile.canShare && data.shareUrl ? <button className="secondary-action" onClick={copy} type="button"><Copy /> Copy link</button> : null}{profile.canShare && data.shareUrl && navigator.share ? <button className="text-action" onClick={share} type="button"><ShareNetwork /> Share</button> : null}</div></section>{state.error ? <div className="detail-error">{state.error}</div> : null}{state.status && state.status !== "Request sent" ? <div className="settings-status">{state.status}</div> : null}{profile.isPrivate ? <section className="private-profile-state"><ShieldCheck weight="duotone" /><h2>This profile is private</h2><p>Only the member can choose what becomes visible.</p></section> : <PublicContent content={content} profile={profile} />}</main></PublicShell>;
}

function profileName(data) {
  return data?.profile?.displayName || "A member";
}

function PublicContent({ content, profile }) {
  return <div className="public-profile-content">{profile.favoriteGenres?.length ? <section><h2>Favorite genres</h2><div className="metadata-strip">{profile.favoriteGenres.map((genre) => <span key={genre}>{genre}</span>)}</div></section> : null}{content.statistics ? <section><h2>Selected stats</h2><div className="public-stats">{Object.entries(content.statistics).map(([key, value]) => <article key={key}><strong>{value}</strong><span>{publicStatLabels[key] || key}</span></article>)}</div></section> : null}{content.favoriteMovies?.length ? <PublicFavorites items={content.favoriteMovies} title="Favorite movies" /> : null}{content.favoriteShows?.length ? <PublicFavorites items={content.favoriteShows} title="Favorite shows" /> : null}{content.publicLists?.length ? <section><h2>Public lists</h2><div className="public-lists">{content.publicLists.map((list) => <article key={list.name}><strong>{list.name}</strong><p>{list.description}</p><span>{list.itemCount} items</span></article>)}</div></section> : null}</div>;
}

function PublicFavorites({ items, title }) {
  return <section><h2>{title}</h2><div className="public-favorites">{items.map((item) => <article key={item.title}>{item.poster ? <img alt="" src={item.poster} /> : <span className="neutral-poster"><UserCircle /></span>}<strong>{item.title}</strong><span>{item.year || ""}</span></article>)}</div></section>;
}

function PublicShell({ children }) {
  return <div className="public-social-shell"><header><a href="/">MediaHub</a><span>Entertainment Memory</span></header>{children}</div>;
}

export function FriendInviteLandingPage({ apiClient = apiRequest, token }) {
  const [invite, setInvite] = useState(null);
  const [authenticated, setAuthenticated] = useState(false);
  const [state, setState] = useState({ loading: true, error: "", status: "" });
  useEffect(() => {
    let cancelled = false;
    Promise.all([
      apiClient(`/api/v1/friend-invites/${encodeURIComponent(token)}`),
      apiClient("/api/v1/me").then(() => true).catch(() => false),
    ]).then(([payload, signedIn]) => { if (!cancelled) { setInvite(payload.invite); setAuthenticated(signedIn); setState({ loading: false, error: "", status: "" }); } }).catch((error) => { if (!cancelled) setState({ loading: false, error: loadFailure(error, "Invitation is unavailable."), status: "" }); });
    return () => { cancelled = true; };
  }, [apiClient, token]);
  async function accept() { try { await apiClient(`/api/v1/friend-invites/${encodeURIComponent(token)}/accept`, { method: "POST" }); setState((value) => ({ ...value, status: "Invitation accepted" })); } catch (error) { setState((value) => ({ ...value, error: loadFailure(error, "Invitation could not be accepted.") })); } }

  return <PublicShell><main className="invite-landing">{state.loading ? <div className="empty-strip compact">Loading invitation...</div> : null}{state.error ? <div className="detail-error">{state.error}</div> : null}{invite ? <><UserPlus weight="duotone" /><span className="eyebrow">MediaHub invitation</span><h1>{invite.inviter.displayName} invited you</h1><p>Accepting creates a mutual friendship. It never shares private watch history, notes, or provider data.</p>{state.status ? <div className="settings-status">{state.status}</div> : authenticated ? <button className="primary-action" onClick={accept} type="button">Accept invitation</button> : <a className="primary-action" href={`/?friend-invite=${encodeURIComponent(token)}`}>Sign in to accept</a>}</> : null}</main></PublicShell>;
}
