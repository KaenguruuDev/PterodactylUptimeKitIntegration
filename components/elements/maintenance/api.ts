import {
  UptimeRange,
  UptimeResponse,
  UptimeStatus,
} from "./types";

const API_BASE = "/api/client/extensions/uptimekit";

export const UPTIME_CACHE_TTL_MS = 60_000;
export type UptimeResponseCacheEntry = {
  response: UptimeResponse;
  fetchedAt: number;
};
export const uptimeResponseCache = new Map<
  string,
  UptimeResponseCacheEntry
>();
export const statusPageUrlCache = new Map<string, string | undefined>();

const uptimeStatuses: UptimeStatus[] = [
  "operational",
  "degraded",
  "partial_outage",
  "major_outage",
  "maintenance",
];
const uptimeRanges: UptimeRange[] = ["24h", "7d", "28d"];

const isUptimeStatus = (value: unknown): value is UptimeStatus =>
  typeof value === "string" && uptimeStatuses.includes(value as UptimeStatus);

export const parseUptimeResponse = (payload: unknown): UptimeResponse => {
  if (!payload || typeof payload !== "object")
    throw new Error(
      "UptimeKit returned an invalid uptime response: expected a JSON object.",
    );

  const response = payload as Record<string, unknown>;
  if (
    typeof response.configured !== "boolean" ||
    typeof response.range !== "string" ||
    !uptimeRanges.includes(response.range as UptimeRange) ||
    !Array.isArray(response.buckets)
  ) {
    throw new Error(
      "UptimeKit returned an invalid uptime response: expected configured, range, and buckets fields.",
    );
  }

  if (
    response.buckets.some(
      (bucket) =>
        !bucket ||
        typeof bucket !== "object" ||
        !isUptimeStatus((bucket as Record<string, unknown>).status) ||
        typeof (bucket as Record<string, unknown>).startAt !== "string" ||
        typeof (bucket as Record<string, unknown>).endAt !== "string",
    )
  ) {
    throw new Error(
      "UptimeKit returned invalid uptime bucket data: each bucket needs a valid status, startAt, and endAt.",
    );
  }

  if (
    response.uptimePercent !== undefined &&
    typeof response.uptimePercent !== "number"
  ) {
    throw new Error(
      "UptimeKit returned an invalid uptime percentage: expected a number.",
    );
  }

  return response as UptimeResponse;
};

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
    const errorPayload =
      payload && typeof payload === "object"
        ? (payload as Record<string, unknown>)
        : undefined;
    const message =
      typeof errorPayload?.message === "string"
        ? errorPayload.message
        : `Request failed (${response.status})`;
    const details =
      typeof errorPayload?.upstreamMessage === "string"
        ? errorPayload.upstreamMessage
        : undefined;
    const code =
      typeof errorPayload?.upstreamCode === "string"
        ? ` (code: ${errorPayload.upstreamCode})`
        : "";
    const upstreamStatus =
      typeof errorPayload?.upstreamStatus === "number"
        ? ` (UptimeKit HTTP ${errorPayload.upstreamStatus})`
        : "";
    throw new Error(
      details && details !== message
        ? `${message} Details: ${details}${code}${upstreamStatus}`
        : `${message}${code}${upstreamStatus}`,
    );
  }
  return payload as T;
};
