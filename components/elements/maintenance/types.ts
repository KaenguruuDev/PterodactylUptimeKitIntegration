export type MaintenanceWindow = {
  id: string;
  title: string;
  description: string;
  startAt: string;
  endAt: string;
  status: "scheduled" | "in_progress" | "completed";
  monitorIds?: string[];
};

export type MaintenanceDraft = Pick<
  MaintenanceWindow,
  "title" | "description" | "startAt" | "endAt"
>;

export type MaintenanceListResponse = {
  configured: boolean;
  enforceStop: boolean;
  windows: MaintenanceWindow[];
};

export type UptimeRange = "24h" | "7d" | "28d";
export type UptimeStatus =
  | "operational"
  | "degraded"
  | "partial_outage"
  | "major_outage"
  | "maintenance";

export type UptimeBucket = {
  startAt: string;
  endAt: string;
  status: UptimeStatus;
  titles?: string[];
};

export type UptimeResponse = {
  configured: boolean;
  range: UptimeRange;
  statusPageUrl?: string | null;
  uptimePercent?: number;
  buckets: UptimeBucket[];
};

export const emptyDraft: MaintenanceDraft = {
  title: "",
  description: "",
  startAt: "",
  endAt: "",
};
