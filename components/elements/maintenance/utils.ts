import {
  faCalendarAlt,
  faCheckCircle,
  faClock,
} from "@fortawesome/free-solid-svg-icons";
import {
  MaintenanceWindow,
  UptimeStatus,
} from "./types";

export const getBrowserLocale = () =>
  typeof navigator === "undefined"
    ? undefined
    : navigator.languages?.[0] || navigator.language || undefined;

const browserLocale = getBrowserLocale();

export const formatDate = (value: string) => {
  const date = new Date(value);
  return Number.isNaN(date.getTime())
    ? "Invalid date"
    : date.toLocaleString(getBrowserLocale(), {
        year: "numeric",
        month: "short",
        day: "numeric",
        hour: "numeric",
        minute: "2-digit",
      });
};

export const formatUptimeRange = (startAt: string, endAt: string) => {
  const start = new Date(startAt);
  const end = new Date(endAt);
  if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime()))
    return "Unknown time range";
  return `${start.toLocaleString(getBrowserLocale(), { month: "short", day: "numeric", hour: "numeric", minute: "2-digit" })} – ${end.toLocaleString(getBrowserLocale(), { month: "short", day: "numeric", hour: "numeric", minute: "2-digit" })}`;
};

export const uptimePresentation: Record<
  UptimeStatus,
  { label: string; className: string }
> = {
  operational: { label: "Operational", className: "bg-green-500" },
  degraded: { label: "Degraded", className: "bg-yellow-400" },
  partial_outage: { label: "Partial outage", className: "bg-orange-500" },
  major_outage: { label: "Major outage", className: "bg-red-500" },
  maintenance: { label: "Maintenance", className: "bg-blue-500" },
};

export const themedSecondarySurface = {
  backgroundColor: "var(--item-secondary-color, rgb(38 38 38))",
};

export const toDateTimeLocal = (value: string) => {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "";
  return new Date(date.getTime() - date.getTimezoneOffset() * 60000)
    .toISOString()
    .slice(0, 16);
};

export const isValidDateTimeLocal = (value: string) =>
  /^\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d$/.test(value);

export const getStatus = (window: MaintenanceWindow) => {
  const now = Date.now();
  const start = new Date(window.startAt).getTime();
  const end = new Date(window.endAt).getTime();
  if (start <= now && now <= end) return "in_progress" as const;
  return now < start ? ("scheduled" as const) : ("completed" as const);
};

export const isVisibleWindow = (window: MaintenanceWindow) => {
  const end = new Date(window.endAt).getTime();
  if (Number.isNaN(end)) return false;
  const thirtyDaysAgo = Date.now() - 30 * 24 * 60 * 60 * 1000;
  return end >= thirtyDaysAgo;
};

export const getStatusPresentation = (
  status: MaintenanceWindow["status"],
) => {
  switch (status) {
    case "in_progress":
      return {
        icon: faClock,
        label: "Active",
        iconClass: "bg-green-500/20 text-green-300",
        textClass: "text-green-300",
      };
    case "completed":
      return {
        icon: faCheckCircle,
        label: "Completed",
        iconClass: "bg-neutral-500/30 text-neutral-300",
        textClass: "text-neutral-300",
      };
    default:
      return {
        icon: faCalendarAlt,
        label: "Scheduled",
        iconClass: "bg-blue-500/20 text-blue-200",
        textClass: "text-blue-200",
      };
  }
};
