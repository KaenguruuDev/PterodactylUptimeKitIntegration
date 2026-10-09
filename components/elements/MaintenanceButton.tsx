import React, { useEffect, useMemo, useRef, useState } from "react";
import { Button } from "@/components/elements/button";
import { ServerContext } from "@/state/server";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faCalendarAlt, faClock } from "@fortawesome/free-solid-svg-icons";
import {
  emptyDraft,
  MaintenanceDraft,
  MaintenanceListResponse,
  MaintenanceWindow,
  UptimeBucket,
  UptimeRange,
} from "./maintenance/types";
import {
  UPTIME_CACHE_TTL_MS,
  parseUptimeResponse,
  request,
  statusPageUrlCache,
  uptimeResponseCache,
} from "./maintenance/api";
import {
  getStatus,
  isValidDateTimeLocal,
  isVisibleWindow,
  toDateTimeLocal,
} from "./maintenance/utils";
import { MaintenanceDialog } from "./maintenance/MaintenanceDialog";
import { UptimePanel } from "./maintenance/UptimePanel";

const MaintenanceButton = () => {
  const serverId = ServerContext.useStoreState(
    (state) => state.server.data!.uuid,
  );
  const serverStatus = ServerContext.useStoreState(
    (state) => state.status.value,
  );
  const [isOpen, setIsOpen] = useState(false);
  const [isLoading, setIsLoading] = useState(false);
  const [editingId, setEditingId] = useState<string>();
  const [pendingDeleteId, setPendingDeleteId] = useState<string>();
  const [draft, setDraft] = useState<MaintenanceDraft>(emptyDraft);
  const [windows, setWindows] = useState<MaintenanceWindow[]>([]);
  const [error, setError] = useState<string>();
  const [isConfigured, setIsConfigured] = useState(false);
  const [enforceStop, setEnforceStop] = useState(true);
  const [now, setNow] = useState(() => Date.now());
  const [uptimeRange, setUptimeRange] = useState<UptimeRange>("24h");
  const [uptimeBuckets, setUptimeBuckets] = useState<UptimeBucket[]>([]);
  const [isUptimeConfigured, setIsUptimeConfigured] = useState(false);
  const [isUptimeLoading, setIsUptimeLoading] = useState(true);
  const [uptimeError, setUptimeError] = useState<string>();
  const [statusPageUrl, setStatusPageUrl] = useState<string>();
  const [uptimePercent, setUptimePercent] = useState<number>();
  const placeholderUptimeBuckets = useMemo(
    () => Array.from({ length: 24 }, () => null),
    [],
  );
  const serverApiPath = `/servers/${encodeURIComponent(serverId)}`;
  const layoutRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const layout = layoutRef.current;
    if (!layout || typeof ResizeObserver === "undefined") return;

    let frame: number | undefined;
    let timeout: number | undefined;
    const refitConsole = () => {
      window.dispatchEvent(new Event("resize"));
      frame = window.requestAnimationFrame(() =>
        window.dispatchEvent(new Event("resize")),
      );
      timeout = window.setTimeout(
        () => window.dispatchEvent(new Event("resize")),
        150,
      );
    };
    const observer = new ResizeObserver(refitConsole);
    observer.observe(layout);

    // The terminal and this component live in separate grid columns. Observe
    // their shared row as well because that is the element whose height grows
    // when the availability panel is inserted.
    const row = layout.parentElement?.parentElement;
    if (row) observer.observe(row);
    refitConsole();

    return () => {
      observer.disconnect();
      if (frame !== undefined) window.cancelAnimationFrame(frame);
      if (timeout !== undefined) window.clearTimeout(timeout);
    };
  }, [isConfigured]);

  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), 1000);
    return () => window.clearInterval(timer);
  }, []);

  const currentWindows = useMemo(
    () =>
      windows
        .filter(isVisibleWindow)
        .map((window) => ({ ...window, status: getStatus(window) })),
    [windows, now],
  );
  const hasActiveWindow = currentWindows.some(
    (window) => window.status === "in_progress",
  );

  useEffect(() => {
    let mounted = true;
    void request<MaintenanceListResponse>(`${serverApiPath}/maintenance`)
      .then((remote) => {
        if (!mounted) return;
        setIsConfigured(remote.configured);
        setEnforceStop(remote.enforceStop ?? true);
        setWindows(remote.windows || []);
      })
      .catch((requestError: unknown) => {
        if (!mounted) return;
        setIsConfigured(true);
        setError(
          requestError instanceof Error
            ? requestError.message
            : "Could not load maintenance windows.",
        );
      });
    return () => {
      mounted = false;
    };
  }, [serverApiPath]);

  useEffect(() => {
    let mounted = true;
    const cacheKey = `${serverId}:${uptimeRange}`;
    const cached = uptimeResponseCache.get(cacheKey);
    if (cached && Date.now() - cached.fetchedAt < UPTIME_CACHE_TTL_MS) {
      setIsUptimeConfigured(cached.response.configured);
      setUptimeBuckets(cached.response.buckets || []);
      const cachedStatusPageUrl = cached.response.statusPageUrl || undefined;
      if (cachedStatusPageUrl)
        statusPageUrlCache.set(serverId, cachedStatusPageUrl);
      else statusPageUrlCache.delete(serverId);
      setStatusPageUrl(cachedStatusPageUrl);
      setUptimePercent(
        typeof cached.response.uptimePercent === "number"
          ? cached.response.uptimePercent
          : undefined,
      );
      setUptimeError(undefined);
      setIsUptimeLoading(false);
      return () => {
        mounted = false;
      };
    }
    uptimeResponseCache.delete(cacheKey);

    setIsUptimeLoading(true);
    setUptimeError(undefined);
    setUptimeBuckets([]);
    setStatusPageUrl(undefined);
    setUptimePercent(undefined);
    void request<unknown>(`${serverApiPath}/uptime?range=${uptimeRange}`)
      .then((payload) => {
        const remote = parseUptimeResponse(payload);
        if (!mounted) return;
        uptimeResponseCache.set(cacheKey, {
          response: remote,
          fetchedAt: Date.now(),
        });
        setIsUptimeConfigured(remote.configured);
        setUptimeBuckets(remote.buckets || []);
        const nextStatusPageUrl = remote.statusPageUrl || undefined;
        if (nextStatusPageUrl)
          statusPageUrlCache.set(serverId, nextStatusPageUrl);
        else statusPageUrlCache.delete(serverId);
        setStatusPageUrl(nextStatusPageUrl);
        setUptimePercent(
          typeof remote.uptimePercent === "number"
            ? remote.uptimePercent
            : undefined,
        );
      })
      .catch((requestError: unknown) => {
        if (!mounted) return;
        uptimeResponseCache.delete(cacheKey);
        setIsUptimeConfigured(false);
        setUptimeBuckets([]);
        setStatusPageUrl(undefined);
        setUptimePercent(undefined);
        setUptimeError(
          requestError instanceof Error
            ? requestError.message
            : "Could not load uptime history.",
        );
      })
      .finally(() => {
        if (mounted) setIsUptimeLoading(false);
      });
    return () => {
      mounted = false;
    };
  }, [serverApiPath, serverId, uptimeRange]);

  useEffect(() => {
    const isStopControl = (button: HTMLButtonElement) => {
      const label = button.textContent
        ?.replace(/\s+/g, " ")
        .trim()
        .toLowerCase();
      return label === "stop" || label === "kill";
    };
    const syncStopControls = () => {
      document
        .querySelectorAll<HTMLButtonElement>("button")
        .forEach((button) => {
          if (!isStopControl(button)) return;
          const disabled =
            serverStatus === "offline" || (enforceStop && !hasActiveWindow);
          if (button.disabled !== disabled) button.disabled = disabled;
          button.title = disabled
            ? "Create an active maintenance window before stopping this server"
            : "Stop the server";
          button.setAttribute("aria-disabled", String(disabled));
        });
    };
    const preventInactiveStop = (event: MouseEvent) => {
      const target =
        event.target instanceof Element
          ? event.target.closest<HTMLButtonElement>("button")
          : null;
      if (
        target &&
        isStopControl(target) &&
        serverStatus !== "offline" &&
        enforceStop &&
        !hasActiveWindow
      ) {
        event.preventDefault();
        event.stopPropagation();
      }
    };

    syncStopControls();
    const observer = new MutationObserver(syncStopControls);
    if (document.body)
      observer.observe(document.body, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ["disabled"],
      });
    document.addEventListener("click", preventInactiveStop, true);
    return () => {
      observer.disconnect();
      document.removeEventListener("click", preventInactiveStop, true);
    };
  }, [enforceStop, hasActiveWindow, serverStatus]);

  const resetEditor = () => {
    setEditingId(undefined);
    setDraft(emptyDraft);
    setError(undefined);
  };

  if (!isConfigured) return null;

  const editWindow = (window: MaintenanceWindow) => {
    setEditingId(window.id);
    setDraft({
      title: window.title,
      description: window.description,
      startAt: toDateTimeLocal(window.startAt),
      endAt: toDateTimeLocal(window.endAt),
    });
    setError(undefined);
  };

  const saveWindow = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError(undefined);
    if (!draft.title.trim() || !draft.startAt || !draft.endAt)
      return setError("Enter a title and time range.");
    if (
      !isValidDateTimeLocal(draft.startAt) ||
      !isValidDateTimeLocal(draft.endAt)
    ) {
      return setError("Enter times in 24-hour HH:mm format.");
    }

    const startAt = new Date(draft.startAt).toISOString();
    const endAt = new Date(draft.endAt).toISOString();
    if (endAt <= startAt)
      return setError("The end time must be after the start time.");

    setIsLoading(true);
    const id = editingId || `mock-${Date.now()}`;
    const previous = windows.find((window) => window.id === id);
    const next: MaintenanceWindow = {
      id,
      title: draft.title.trim(),
      description: draft.description.trim(),
      startAt,
      endAt,
      status: "scheduled",
      monitorIds: previous?.monitorIds,
    };

    try {
      if (editingId) {
        const saved = await request<MaintenanceWindow>(
          `${serverApiPath}/maintenance/${encodeURIComponent(editingId)}`,
          {
            method: "PATCH",
            body: JSON.stringify({ startAt, endAt }),
          },
        );
        setWindows((current) =>
          current.map((window) =>
            window.id === editingId ? { ...next, ...saved } : window,
          ),
        );
      } else {
        const saved = await request<MaintenanceWindow>(
          `${serverApiPath}/maintenance`,
          {
            method: "POST",
            body: JSON.stringify(next),
          },
        );
        setWindows((current) => [...current, saved]);
      }
      resetEditor();
    } catch (requestError: unknown) {
      setError(
        requestError instanceof Error
          ? requestError.message
          : "Could not save maintenance window.",
      );
    } finally {
      setIsLoading(false);
    }
  };

  const deleteWindow = async (id: string) => {
    setPendingDeleteId(id);
  };

  const confirmDelete = async () => {
    if (!pendingDeleteId) return;
    const id = pendingDeleteId;
    setIsLoading(true);
    try {
      await request(`${serverApiPath}/maintenance/${encodeURIComponent(id)}`, {
        method: "DELETE",
      });
      setWindows((current) => current.filter((window) => window.id !== id));
      if (editingId === id) resetEditor();
    } catch (requestError: unknown) {
      setError(
        requestError instanceof Error
          ? requestError.message
          : "Could not delete maintenance window.",
      );
    } finally {
      setIsLoading(false);
    }
    setPendingDeleteId(undefined);
  };

  return (
    <div ref={layoutRef} className={"col-span-6 min-w-0 w-full"}>
      <Button.Text
        className={"flex h-10 w-full items-center justify-center"}
        type={"button"}
        aria-haspopup={"dialog"}
        title={
          hasActiveWindow
            ? "Active maintenance window"
            : "Manage maintenance windows"
        }
        onClick={() => setIsOpen(true)}
      >
        <span className={"inline-flex items-center gap-2 leading-none"}>
          <FontAwesomeIcon
            className={hasActiveWindow ? "text-green-300" : "text-blue-200"}
            icon={hasActiveWindow ? faClock : faCalendarAlt}
            fixedWidth
            aria-hidden
          />
          <span>Maintenance</span>
        </span>
      </Button.Text>

      <UptimePanel
        isConfigured={isUptimeConfigured}
        isLoading={isUptimeLoading}
        error={uptimeError}
        range={uptimeRange}
        onRangeChange={setUptimeRange}
        buckets={uptimeBuckets}
        placeholderBuckets={placeholderUptimeBuckets}
        statusPageUrl={statusPageUrl}
        uptimePercent={uptimePercent}
      />

      <MaintenanceDialog
        isOpen={isOpen}
        onClose={() => {
          setIsOpen(false);
          resetEditor();
        }}
        hasActiveWindow={hasActiveWindow}
        error={error}
        currentWindows={currentWindows}
        onEdit={editWindow}
        onDelete={deleteWindow}
        editingId={editingId}
        draft={draft}
        setDraft={setDraft}
        isLoading={isLoading}
        onSave={saveWindow}
        onReset={resetEditor}
        pendingDeleteId={pendingDeleteId}
        setPendingDeleteId={setPendingDeleteId}
        onConfirmDelete={confirmDelete}
      />
    </div>
  );
};

export default MaintenanceButton;
