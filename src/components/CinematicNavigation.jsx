import { useEffect, useRef, useState } from "react";
import { Bell, CalendarDots, CaretDown, ChartBar, Clock, Compass, DotsThree, FilmSlate, GearSix, House, ListBullets, MagnifyingGlass, Play, TelevisionSimple, X } from "@phosphor-icons/react";
import { AccountMenu } from "./ProfileSurfaces.jsx";

const destinations = [
  { id: "home", label: "Home", icon: House },
  { id: "discover", label: "Discover", icon: Compass },
  { id: "library", label: "Library", icon: FilmSlate },
  { id: "calendar", label: "Calendar", icon: CalendarDots },
  { id: "more", label: "More", icon: DotsThree },
];
const libraryItems = [["shows", "Shows", TelevisionSimple], ["movies", "Movies", FilmSlate]];
const moreItems = [["history", "History", Clock], ["stats", "Stats", ChartBar], ["lists", "Lists", ListBullets], ["settings", "Settings", GearSix]];

export function CinematicNavigation({ activeSection, alertsCount = 0, features = {}, onSelect, onAccountAction, profile, query = "", onQueryChange, onLogout, searchInputRef, searchToggleRef }) {
  const [menu, setMenu] = useState(null);
  const [searchOpen, setSearchOpen] = useState(false);
  const rootRef = useRef(null);
  const triggerRef = useRef(null);
  const menuRef = useRef(null);
  const searchButtonRef = useRef(null);
  const localSearchRef = useRef(null);
  const inputRef = searchInputRef || localSearchRef;
  const secondaryItems = [...moreItems, ...(features.webPlayerEnabled ? [["player", "Player", Play]] : [])];
  const activeGroup = libraryItems.some(([id]) => id === activeSection) ? "library" : secondaryItems.some(([id]) => id === activeSection) ? "more" : activeSection;

  useEffect(() => { setMenu(null); }, [activeSection]);
  useEffect(() => {
    function dismiss(event) {
      if (!rootRef.current?.contains(event.target)) setMenu(null);
    }
    function escape(event) {
      if (event.key !== "Escape") return;
      if (menu) { setMenu(null); triggerRef.current?.focus(); }
      else if (searchOpen) { setSearchOpen(false); onQueryChange?.(""); searchButtonRef.current?.focus(); }
    }
    document.addEventListener("pointerdown", dismiss);
    document.addEventListener("keydown", escape);
    return () => { document.removeEventListener("pointerdown", dismiss); document.removeEventListener("keydown", escape); };
  }, [menu, searchOpen, onQueryChange]);

  function choose(id) { setMenu(null); onSelect(id); }
  function toggleMenu(id, event) {
    triggerRef.current = event.currentTarget;
    setMenu(current => current === id ? null : id);
  }
  function menuKeyboard(event) {
    if (!["ArrowDown", "ArrowUp", "Home", "End"].includes(event.key)) return;
    event.preventDefault();
    const items = Array.from(menuRef.current?.querySelectorAll("[role=menuitem]") || []);
    const current = items.indexOf(document.activeElement);
    const next = event.key === "Home" ? 0 : event.key === "End" ? items.length - 1 : event.key === "ArrowUp" ? (current <= 0 ? items.length - 1 : current - 1) : (current + 1) % items.length;
    items[next]?.focus();
  }
  function triggerKeyboard(id, event) {
    if (!["ArrowDown", "ArrowUp"].includes(event.key)) return;
    event.preventDefault();
    triggerRef.current = event.currentTarget;
    setMenu(id);
    requestAnimationFrame(() => {
      const items = menuRef.current?.querySelectorAll("[role=menuitem]");
      items?.[event.key === "ArrowUp" ? items.length - 1 : 0]?.focus();
    });
  }
  function toggleSearch() {
    setSearchOpen(current => !current);
    if (!searchOpen) requestAnimationFrame(() => inputRef.current?.focus());
    else onQueryChange?.("");
  }

  return <header className="cinema-header" ref={rootRef}>
    <a className="cinema-brand" href="/" aria-label="MediaHub home" onClick={event => { event.preventDefault(); choose("home"); }}>
      <span className="brand-mark"><FilmSlate size={23} weight="fill" /></span><strong>MEDIAHUB</strong>
    </a>
    <nav aria-label="Main navigation" className="cinema-nav">
      {destinations.map(({ id, label, icon: Icon }) => {
        const expandable = id === "library" || id === "more";
        return <div className="cinema-nav-group" key={id}>
          <button className={`nav-item cinema-nav-item${activeGroup === id ? " active" : ""}`} aria-label={label} aria-current={activeGroup === id ? "page" : undefined} aria-expanded={expandable ? menu === id : undefined} aria-haspopup={expandable ? "menu" : undefined} aria-controls={expandable && menu === id ? `cinema-${id}-menu` : undefined} onClick={event => expandable ? toggleMenu(id, event) : choose(id)} onKeyDown={event => expandable && triggerKeyboard(id, event)} type="button">
            <Icon size={23} /><span>{label}</span>{expandable ? <CaretDown className="cinema-nav-caret" size={12} /> : null}
          </button>
          {menu === id ? <div className="cinema-nav-menu" id={`cinema-${id}-menu`} role="menu" aria-label={label} ref={menuRef} onKeyDown={menuKeyboard}>
            {(id === "library" ? libraryItems : secondaryItems).map(([section, title, ItemIcon]) => <button role="menuitem" className={activeSection === section ? "active" : ""} key={section} onClick={() => choose(section)} type="button"><ItemIcon size={20} /><span>{title}</span></button>)}
          </div> : null}
        </div>;
      })}
    </nav>
    <div className="cinema-header-actions" onPointerDown={() => setMenu(null)}>
      <button className="cinema-icon-button" aria-label={searchOpen ? "Close search" : "Search your library"} aria-expanded={searchOpen} aria-controls="cinema-search" onClick={toggleSearch} ref={node => { searchButtonRef.current = node; if (searchToggleRef) searchToggleRef.current = node; }} type="button">{searchOpen ? <X size={23} /> : <MagnifyingGlass size={23} />}</button>
      <button className={`cinema-icon-button cinema-alerts${activeSection === "alerts" ? " active" : ""}`} aria-label={alertsCount > 0 ? `Alerts, ${alertsCount} unread alert${alertsCount === 1 ? "" : "s"}` : "Alerts"} onClick={() => choose("alerts")} type="button"><Bell size={23} />{alertsCount > 0 ? <b aria-hidden="true" className="nav-alert-badge">{alertsCount > 99 ? "99+" : alertsCount}</b> : null}</button>
      <AccountMenu profile={profile} onLogout={onLogout} onNavigate={onAccountAction} />
    </div>
    <div className="cinema-search" id="cinema-search" hidden={!searchOpen && !query}>
      <label className="search-box"><MagnifyingGlass size={22} /><input ref={inputRef} aria-label="Search shows, movies, episodes" placeholder="Search shows, movies, episodes..." value={query} onChange={event => onQueryChange?.(event.target.value)} onFocus={() => setSearchOpen(true)} /></label>
      <button className="cinema-icon-button" aria-label="Clear and close search" onClick={() => { onQueryChange?.(""); setSearchOpen(false); searchButtonRef.current?.focus(); }} type="button"><X size={22} /></button>
    </div>
  </header>;
}
