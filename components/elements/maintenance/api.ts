import { UptimeResponse } from "./types";

const API_BASE = "/api/client/extensions/uptimekit";

export const uptimeResponseCache = new Map<string, UptimeResponse>();
export const statusPageUrlCache = new Map<string, string | undefined>();

export const request = async <T,>(
  path: string,
  init?: RequestInit,
): Promise<T> => {
  const csrfToken = document
    .querySelector('meta[name="csrf-token"]')
    ?.getAttribute("content");
  const response = await fetch(`${API_BASE}${path}`, {
    ...init,
    headers: {
      "Content-Type": "application/json",
      ...(csrfToken ? { "X-CSRF-TOKEN": csrfToken } : {}),
      ...(init?.headers || {}),
    },
  });
  const payload = await response.json().catch(() => undefined);
  if (!response.ok) {
    const message =
      payload && typeof payload === "object" && "message" in payload
        ? String(payload.message)
        : `Request failed (${response.status})`;
    throw new Error(message);
  }
  return payload as T;
};
