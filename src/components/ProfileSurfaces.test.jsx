// @vitest-environment jsdom
import "@testing-library/jest-dom/vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import {
  AccountMenu,
  FriendsSection,
  FriendInviteLandingPage,
  InviteFriendsSection,
  OwnProfileSection,
  PrivacyControls,
  PublicProfilePage,
} from "./ProfileSurfaces.jsx";

afterEach(() => cleanup());

beforeEach(() => {
  Object.defineProperty(navigator, "clipboard", {
    configurable: true,
    value: { writeText: vi.fn().mockResolvedValue(undefined) },
  });
});

describe("AccountMenu", () => {
  it("opens, navigates safely, closes outside, and logs out only from the logout item", () => {
    const onNavigate = vi.fn();
    const onLogout = vi.fn();
    render(<AccountMenu onLogout={onLogout} onNavigate={onNavigate} profile={{ displayName: "Gunner", username: "gunner" }} />);

    const trigger = screen.getByRole("button", { name: "Open account menu" });
    fireEvent.click(trigger);
    expect(screen.getByRole("menu", { name: "Account" })).toBeInTheDocument();
    expect(onLogout).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole("menuitem", { name: "View Profile" }));
    expect(onNavigate).toHaveBeenCalledWith("view-profile");
    expect(screen.queryByRole("menu", { name: "Account" })).not.toBeInTheDocument();

    fireEvent.click(trigger);
    fireEvent.mouseDown(document.body);
    expect(screen.queryByRole("menu", { name: "Account" })).not.toBeInTheDocument();

    fireEvent.click(trigger);
    fireEvent.click(screen.getByRole("menuitem", { name: "Logout" }));
    expect(onLogout).toHaveBeenCalledTimes(1);
  });

  it("supports Escape and arrow-key focus", () => {
    render(<AccountMenu onLogout={vi.fn()} onNavigate={vi.fn()} profile={{ displayName: "Gunner", username: "gunner" }} />);
    const trigger = screen.getByRole("button", { name: "Open account menu" });
    fireEvent.click(trigger);
    fireEvent.keyDown(screen.getByRole("menu", { name: "Account" }), { key: "ArrowDown" });
    expect(screen.getByRole("menuitem", { name: "View Profile" })).toHaveFocus();

    fireEvent.keyDown(document, { key: "Escape" });
    expect(screen.queryByRole("menu", { name: "Account" })).not.toBeInTheDocument();
    expect(trigger).toHaveFocus();
  });
});

describe("PublicProfilePage", () => {
  it("renders a private shell without private content", async () => {
    const apiClient = vi.fn().mockResolvedValue({
      profile: {
        slug: "private-member",
        username: "private_member",
        displayName: "Private Member",
        avatar: null,
        isPrivate: true,
        contentVisible: false,
      },
      content: { privateNotes: "Never render this", watchHistory: ["Private title"] },
      relationship: { status: "guest", canRequest: false },
      shareUrl: null,
    });

    render(<PublicProfilePage apiClient={apiClient} slug="private-member" />);

    expect(await screen.findByText("Private Member")).toBeInTheDocument();
    expect(screen.getByText("This profile is private")).toBeInTheDocument();
    expect(screen.queryByText("Never render this")).not.toBeInTheDocument();
    expect(screen.queryByText("Private title")).not.toBeInTheDocument();
    expect(screen.queryByText(/email/i)).not.toBeInTheDocument();
  });

  it("renders enabled public fields and sends a friend request by slug", async () => {
    const apiClient = vi.fn()
      .mockResolvedValueOnce({
        profile: {
          slug: "public-member",
          username: "public_member",
          displayName: "Public Member",
          bio: "A public bio.",
          memberSince: "2026-07-01",
          favoriteGenres: ["Drama"],
          isPrivate: false,
          contentVisible: true,
          canShare: true,
        },
        content: {
          statistics: { moviesWatched: 12, episodesWatched: 30, hoursWatched: 44, showsFollowed: 4 },
          favoriteMovies: [{ title: "Public favorite", poster: null, year: "2025" }],
        },
        relationship: { status: "none", canRequest: true },
        shareUrl: "https://example.test/u/public-member",
      })
      .mockResolvedValueOnce({ friendship: { status: "pending" } });

    render(<PublicProfilePage apiClient={apiClient} slug="public-member" />);

    expect(await screen.findByText("A public bio.")).toBeInTheDocument();
    expect(screen.getByText("Public favorite")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Add friend" }));

    await waitFor(() => expect(apiClient).toHaveBeenLastCalledWith(
      "/api/v1/friends/request/public-member",
      { method: "POST" },
    ));
    expect(await screen.findByText("Request sent")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Copy link" }));
    expect(navigator.clipboard.writeText).toHaveBeenCalledWith("https://example.test/u/public-member");
  });
});

describe("OwnProfileSection", () => {
  it("edits identity and selects only supplied favorite options", async () => {
    const onOpenPrivacy = vi.fn();
    const apiClient = vi.fn(async (path, options = {}) => {
      if (path === "/api/v1/profile/options") return {
        movies: [{ id: 4, title: "Favorite movie" }],
        shows: [{ id: 8, title: "Favorite show" }],
        publicLists: [],
      };
      if (path === "/api/v1/profile" && options.method === "PATCH") return { profile: {} };
      return {
        profile: {
          username: "gunner",
          displayName: "Gunner",
          slug: "gunner",
          shareUrl: "https://example.test/u/gunner",
          favoriteGenres: [],
          favoriteMovieIds: [],
          favoriteShowIds: [],
          featuredListIds: [],
        },
        privacy: { publicProfileEnabled: true, allowProfileSharing: true },
      };
    });

    const { rerender } = render(<OwnProfileSection apiClient={apiClient} editInitially onOpenPrivacy={onOpenPrivacy} />);
    fireEvent.change(await screen.findByLabelText("Bio"), { target: { value: "My public bio" } });
    fireEvent.click(screen.getByRole("checkbox", { name: "Favorite movie" }));
    fireEvent.click(screen.getByRole("button", { name: "Save profile" }));

    await waitFor(() => expect(apiClient).toHaveBeenCalledWith(
      "/api/v1/profile",
      expect.objectContaining({
        method: "PATCH",
        body: expect.objectContaining({ bio: "My public bio", favorite_movie_ids: [4] }),
      }),
    ));

    rerender(<OwnProfileSection apiClient={apiClient} onOpenPrivacy={onOpenPrivacy} />);
    fireEvent.click(await screen.findByRole("button", { name: "Copy profile link" }));
    expect(navigator.clipboard.writeText).toHaveBeenCalledWith("https://example.test/u/gunner");
    fireEvent.click(screen.getByRole("button", { name: "Privacy" }));
    expect(onOpenPrivacy).toHaveBeenCalledTimes(1);
  });

  it("edits full profile fields and previews/uploads a responsive avatar", async () => {
    Object.defineProperty(URL, "createObjectURL", { configurable: true, value: vi.fn(() => "blob:avatar-preview") });
    Object.defineProperty(URL, "revokeObjectURL", { configurable: true, value: vi.fn() });
    const uploadClient = vi.fn(async (path, body, onProgress) => {
      onProgress(100);
      return { profile: { avatar: "/storage/avatars/1/random-512.jpg" } };
    });
    const apiClient = vi.fn(async (path, options = {}) => {
      if (path === "/api/v1/profile/options") return { movies: [], shows: [], publicLists: [] };
      if (options.method) return {};
      return {
        profile: {
          username: "gunner",
          displayName: "Gunner",
          fullName: "Aleksandar Dimovski",
          email: "gunner@example.test",
          slug: "gunner",
          country: "MK",
          favoriteGenres: [],
          favoriteMovieIds: [],
          favoriteShowIds: [],
          featuredListIds: [],
        },
        privacy: { publicProfileEnabled: false, profileVisibility: "private", showAvatar: false },
      };
    });

    const { container } = render(<OwnProfileSection apiClient={apiClient} editInitially uploadClient={uploadClient} />);
    expect(await screen.findByLabelText("Full name")).toHaveValue("Aleksandar Dimovski");
    expect(screen.getByLabelText("Email")).toHaveValue("gunner@example.test");
    expect(screen.getByLabelText("Email")).toHaveAttribute("readonly");
    expect(screen.getByLabelText("Country")).toHaveValue("North Macedonia (MK)");
    expect(container.querySelectorAll("#mediahub-countries option").length).toBeGreaterThan(240);

    const file = new File(["avatar"], "avatar.webp", { type: "image/webp" });
    fireEvent.change(screen.getByLabelText("Choose avatar"), { target: { files: [file] } });
    expect(container.querySelector(".avatar-preview img")).toHaveAttribute("src", "blob:avatar-preview");
    expect(container.querySelector(".avatar-editor")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Upload avatar" }));

    await waitFor(() => expect(uploadClient).toHaveBeenCalledWith(
      "/api/v1/profile/avatar",
      expect.any(FormData),
      expect.any(Function),
    ));
  });
});

describe("PrivacyControls", () => {
  it("saves opt-in profile visibility controls", async () => {
    const apiClient = vi.fn().mockResolvedValue({ privacy: {} });
    render(<PrivacyControls apiClient={apiClient} />);

    await screen.findByRole("checkbox", { name: "Enable public profile" });
    fireEvent.click(screen.getByRole("checkbox", { name: "Enable public profile" }));
    fireEvent.click(screen.getByRole("checkbox", { name: "Show avatar when profile visibility allows" }));
    fireEvent.click(screen.getByRole("checkbox", { name: "Show statistics" }));
    fireEvent.change(screen.getByLabelText("Profile visibility"), { target: { value: "public" } });
    fireEvent.click(screen.getByRole("button", { name: "Save privacy" }));

    await waitFor(() => expect(apiClient).toHaveBeenCalledWith(
      "/api/v1/profile/privacy",
      expect.objectContaining({
        method: "PATCH",
        body: expect.objectContaining({
          public_profile_enabled: true,
          show_avatar: true,
          profile_visibility: "public",
          show_statistics: true,
        }),
      }),
    ));
  });
});

describe("FriendsSection", () => {
  it("accepts and declines incoming requests without exposing email", async () => {
    const apiClient = vi.fn(async (path, options = {}) => {
      if (path === "/api/v1/friends") return { friends: [] };
      if (path === "/api/v1/friends/requests") return {
        incoming: [
          { friendshipId: 11, profile: { slug: "first", username: "first", displayName: "First", email: "private@example.test" } },
          { friendshipId: 12, profile: { slug: "second", username: "second", displayName: "Second" } },
        ],
        outgoing: [],
      };
      if (options.method === "POST") return { friendship: { status: "accepted" } };
      return {};
    });

    render(<FriendsSection apiClient={apiClient} />);
    expect(await screen.findByText("First")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Accept First" }));
    fireEvent.click(screen.getByRole("button", { name: "Decline Second" }));

    await waitFor(() => {
      expect(apiClient).toHaveBeenCalledWith("/api/v1/friends/11/accept", { method: "POST" });
      expect(apiClient).toHaveBeenCalledWith("/api/v1/friends/12/decline", { method: "POST" });
    });
    expect(document.body.textContent).not.toContain("private@example.test");
  });
});

describe("InviteFriendsSection", () => {
  it("creates and copies a revocable invite link", async () => {
    const apiClient = vi.fn(async (path, options = {}) => {
      if (path === "/api/v1/friend-invites" && options.method === "POST") {
        return { invite: { id: 9, url: "https://example.test/invite/safe-token", status: "pending", expiresAt: "2026-07-19T00:00:00Z" } };
      }
      return { invites: [] };
    });

    render(<InviteFriendsSection apiClient={apiClient} />);
    fireEvent.click(await screen.findByRole("button", { name: "Create invite link" }));
    fireEvent.click(await screen.findByRole("button", { name: "Copy invite link" }));

    expect(navigator.clipboard.writeText).toHaveBeenCalledWith("https://example.test/invite/safe-token");
    expect(await screen.findByText("Copied")).toBeInTheDocument();
  });
});

describe("FriendInviteLandingPage", () => {
  it("creates no relationship until the signed-in recipient explicitly accepts", async () => {
    const apiClient = vi.fn(async (path, options = {}) => {
      if (path === "/api/v1/me") return { user: { name: "Recipient" } };
      if (path.endsWith("/accept") && options.method === "POST") return { status: "accepted" };
      return { invite: { inviter: { displayName: "Inviter" }, status: "opened" } };
    });

    render(<FriendInviteLandingPage apiClient={apiClient} token="safe-token" />);
    expect(await screen.findByText("Inviter invited you")).toBeInTheDocument();
    expect(apiClient).not.toHaveBeenCalledWith(expect.stringContaining("/accept"), expect.anything());
    fireEvent.click(screen.getByRole("button", { name: "Accept invitation" }));
    await waitFor(() => expect(apiClient).toHaveBeenCalledWith(
      "/api/v1/friend-invites/safe-token/accept",
      { method: "POST" },
    ));
  });

  it("preserves the invitation when an unauthenticated recipient signs in", async () => {
    const apiClient = vi.fn(async (path) => {
      if (path === "/api/v1/me") throw new Error("Unauthenticated");
      return { invite: { inviter: { displayName: "Inviter" }, status: "opened" } };
    });

    render(<FriendInviteLandingPage apiClient={apiClient} token="safe-token" />);
    expect(await screen.findByRole("link", { name: "Sign in to accept" })).toHaveAttribute(
      "href",
      "/?friend-invite=safe-token",
    );
    expect(screen.queryByRole("button", { name: "Accept invitation" })).not.toBeInTheDocument();
  });
});
