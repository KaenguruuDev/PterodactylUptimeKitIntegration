import React from "react";
import consoleStyles from "@/components/server/console/style.module.css";
import { formatUptimeRange, uptimePresentation } from "./utils";
import { UptimeBucket, UptimeRange } from "./types";

const ExternalLinkIcon = () => (
  <svg
    className={"h-5 w-5"}
    viewBox={"0 0 24 24"}
    fill={"none"}
    stroke={"currentColor"}
    strokeWidth={1.75}
    strokeLinecap={"round"}
    strokeLinejoin={"round"}
    aria-hidden
  >
    <path d={"M14 3h7v7"} />
    <path d={"M10 14 21 3"} />
    <path d={"M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5"} />
  </svg>
);

type UptimePanelProps = {
  isConfigured: boolean;
  isLoading: boolean;
  error?: string;
  range: UptimeRange;
  onRangeChange: (range: UptimeRange) => void;
  buckets: UptimeBucket[];
  placeholderBuckets: null[];
  statusPageUrl?: string;
  uptimePercent?: number;
};

export const UptimePanel = ({
  isConfigured,
  isLoading,
  error,
  range,
  onRangeChange,
  buckets,
  placeholderBuckets,
  statusPageUrl,
  uptimePercent,
}: UptimePanelProps) => {
  if (!isConfigured && !isLoading && !error) return null;

  return (
    <section className={"mt-3"} aria-label={"Uptime history"}>
      <div className={`${consoleStyles.stat_block} w-full bg-gray-600`}>
        <div className={"min-w-0 w-full"}>
          <div className={"mb-2 flex items-center justify-between gap-3"}>
            <p className={"text-sm font-semibold text-gray-50"}>
              Availability
            </p>
            <div
              className={"flex rounded border border-neutral-600 p-0.5"}
              aria-label={"Uptime range"}
            >
              {(["24h", "7d", "28d"] as UptimeRange[]).map((nextRange) => (
                <button
                  key={nextRange}
                  className={`rounded px-2 py-1 text-xs font-medium transition-colors ${range === nextRange ? "bg-blue-500 text-white" : "text-neutral-300 hover:bg-neutral-700"}`}
                  type={"button"}
                  aria-pressed={range === nextRange}
                  onClick={() => onRangeChange(nextRange)}
                >
                  {nextRange}
                </button>
              ))}
            </div>
          </div>
          {error ? (
            <p
              className={"break-words whitespace-pre-wrap text-xs text-red-200"}
              role={"alert"}
            >
              {error}
            </p>
          ) : (
            <>
              <div
                className={"rounded p-2"}
                style={{
                  backgroundColor: "var(--item-secondary-color, rgb(55 65 81))",
                }}
              >
                <div
                  className={"grid h-5 gap-px"}
                  style={{
                    gridTemplateColumns: "repeat(24, minmax(0, 1fr))",
                  }}
                  role={"list"}
                  aria-label={`Uptime history for the last ${range}`}
                >
                  {(isLoading ? placeholderBuckets : buckets).map(
                    (bucket, index) => {
                      const presentation = bucket
                        ? uptimePresentation[bucket.status]
                        : undefined;
                      const details = bucket
                        ? [
                            formatUptimeRange(bucket.startAt, bucket.endAt),
                            presentation!.label,
                            ...(bucket.titles || []),
                          ].join(": ")
                        : "Loading uptime history";
                      return (
                        <span
                          key={index}
                          className={`min-w-0 rounded-sm transition-colors duration-200 ease-out ${presentation?.className || "bg-gray-700"}`}
                          role={"listitem"}
                          title={details}
                          aria-label={details}
                        />
                      );
                    },
                  )}
                </div>
              </div>
              <div className={"mt-2 flex items-center justify-between"}>
                <p className={"text-xs font-semibold text-neutral-200"}>
                  {isLoading
                    ? "000.00%"
                    : uptimePercent === undefined
                      ? "—"
                      : `${uptimePercent.toFixed(2)}%`} uptime
                </p>
                {!isLoading && statusPageUrl ? (
                  <a
                    className={
                      "inline-flex h-7 w-7 items-center justify-center rounded text-neutral-300 hover:bg-neutral-700 hover:text-gray-50"
                    }
                    href={statusPageUrl}
                    target={"_blank"}
                    rel={"noreferrer"}
                    title={"Open status page"}
                    aria-label={"Open status page in a new tab"}
                  >
                    <ExternalLinkIcon />
                  </a>
                ) : (
                  <span className={"h-7 w-7"} aria-hidden />
                )}
              </div>
            </>
          )}
        </div>
      </div>
    </section>
  );
};
