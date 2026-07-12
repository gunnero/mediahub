export class SessionExpiredError extends Error {
  constructor(message = "Session expired") {
    super(message);
    this.name = "SessionExpiredError";
  }
}

export class ApiError extends Error {
  constructor(message = "API request failed", status = 500) {
    super(message);
    this.name = "ApiError";
    this.status = status;
  }
}

function readCookie(name) {
  if (typeof document === "undefined") {
    return "";
  }

  return document.cookie
    .split("; ")
    .find((entry) => entry.startsWith(`${name}=`))
    ?.split("=")[1] || "";
}

async function ensureCsrfCookie() {
  if (readCookie("XSRF-TOKEN")) {
    return;
  }

  await fetch("/api/v1/status", {
    credentials: "include",
    headers: { Accept: "application/json" },
  });
}

export async function apiRequest(path, options = {}) {
  const hasBody = Object.prototype.hasOwnProperty.call(options, "body");
  const formBody = typeof FormData !== "undefined" && options.body instanceof FormData;
  const method = (options.method || "GET").toUpperCase();

  if (!["GET", "HEAD", "OPTIONS"].includes(method)) {
    await ensureCsrfCookie();
  }

  const csrfToken = decodeURIComponent(readCookie("XSRF-TOKEN"));

  const response = await fetch(path, {
    ...options,
    credentials: "include",
    headers: {
      Accept: "application/json",
      ...(hasBody && !formBody ? { "Content-Type": "application/json" } : {}),
      ...(csrfToken ? { "X-XSRF-TOKEN": csrfToken } : {}),
      ...(options.headers || {}),
    },
    body:
      hasBody && !formBody && options.body !== undefined && typeof options.body !== "string"
        ? JSON.stringify(options.body)
        : options.body,
  });

  const contentType = response.headers.get("content-type") || "";
  const payload = contentType.includes("application/json")
    ? await response.json()
    : null;

  if (response.status === 401) {
    throw new SessionExpiredError(payload?.message);
  }

  if (!response.ok) {
    throw new ApiError(payload?.message || "API request failed", response.status);
  }

  return payload;
}

export async function apiUpload(path, formData, onProgress = () => {}) {
  await ensureCsrfCookie();
  const csrfToken = decodeURIComponent(readCookie("XSRF-TOKEN"));

  return new Promise((resolve, reject) => {
    const request = new XMLHttpRequest();
    request.open("POST", path);
    request.withCredentials = true;
    request.setRequestHeader("Accept", "application/json");
    if (csrfToken) request.setRequestHeader("X-XSRF-TOKEN", csrfToken);
    request.upload.addEventListener("progress", (event) => {
      if (event.lengthComputable) onProgress(Math.round((event.loaded / event.total) * 100));
    });
    request.addEventListener("load", () => {
      let payload = null;
      try {
        payload = request.responseText ? JSON.parse(request.responseText) : null;
      } catch {
        payload = null;
      }

      if (request.status === 401) {
        reject(new SessionExpiredError(payload?.message));
      } else if (request.status < 200 || request.status >= 300) {
        reject(new ApiError(payload?.message || payload?.errors?.avatar?.[0] || "Avatar upload failed", request.status));
      } else {
        onProgress(100);
        resolve(payload);
      }
    });
    request.addEventListener("error", () => reject(new ApiError("Avatar upload failed", 0)));
    request.send(formData);
  });
}
