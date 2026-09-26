import React, { useEffect, useMemo, useState } from 'react';
import { Button } from '@/components/elements/button';
import { Dialog } from '@/components/elements/dialog';
import { ServerContext } from '@/state/server';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faCalendarAlt, faCheckCircle, faClock, faPencilAlt, faTrashAlt } from '@fortawesome/free-solid-svg-icons';

type MaintenanceWindow = {
  id: string;
  title: string;
  description: string;
  startAt: string;
  endAt: string;
  status: 'scheduled' | 'in_progress' | 'completed';
  monitorIds?: string[];
};

type MaintenanceDraft = Pick<MaintenanceWindow, 'title' | 'description' | 'startAt' | 'endAt'>;
type MaintenanceListResponse = {
  configured: boolean;
  enforceStop: boolean;
  windows: MaintenanceWindow[];
};

const API_BASE = '/api/client/extensions/uptimekitmaintenancetoggle';
const emptyDraft: MaintenanceDraft = { title: '', description: '', startAt: '', endAt: '' };

const getBrowserLocale = () =>
  typeof navigator === 'undefined' ? undefined : navigator.languages?.[0] || navigator.language || undefined;

const browserLocale = getBrowserLocale();

const DateTimeField = ({
  label,
  value,
  onChange,
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
}) => {
  const date = value.slice(0, 10);
  const time = value.slice(11, 16);
  const update = (nextDate: string, nextTime: string) => onChange(nextDate ? `${nextDate}T${nextTime || '00:00'}` : '');

  return (
    <fieldset className={'grid gap-2'}>
      <legend className={'text-xs text-neutral-300'}>{label}</legend>
      <div className={'grid gap-2 sm:grid-cols-5'}>
        <input
          className={'min-w-0 w-full rounded border border-neutral-600 bg-neutral-800 px-3 py-2 text-sm text-gray-50 sm:col-span-3'}
          type={'date'}
          value={date}
          onChange={(event) => update(event.target.value, time)}
          required
        />
        <input
          className={'min-w-0 w-full rounded border border-neutral-600 bg-neutral-800 px-3 py-2 text-sm text-gray-50 sm:col-span-2'}
          type={'text'}
          inputMode={'numeric'}
          placeholder={'HH:mm'}
          pattern={'[0-9]{2}:[0-9]{2}'}
          maxLength={5}
          value={time}
          onChange={(event) => update(date, event.target.value)}
          required
          aria-label={`${label} time`}
        />
      </div>
    </fieldset>
  );
};

const request = async <T,>(path: string, init?: RequestInit): Promise<T> => {
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
  const response = await fetch(`${API_BASE}${path}`, {
    ...init,
    headers: {
      'Content-Type': 'application/json',
      ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
      ...(init?.headers || {}),
    },
  });
  const payload = await response.json().catch(() => undefined);
  if (!response.ok) {
    const message = payload && typeof payload === 'object' && 'message' in payload ? String(payload.message) : `Request failed (${response.status})`;
    throw new Error(message);
  }
  return payload as T;
};

const formatDate = (value: string) => {
  const date = new Date(value);
  return Number.isNaN(date.getTime())
    ? 'Invalid date'
    : date.toLocaleString(getBrowserLocale(), {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
      });
};

const toDateTimeLocal = (value: string) => {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '';
  return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
};

const isValidDateTimeLocal = (value: string) =>
  /^\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d$/.test(value);

const getStatus = (window: MaintenanceWindow) => {
  const now = Date.now();
  const start = new Date(window.startAt).getTime();
  const end = new Date(window.endAt).getTime();
  if (start <= now && now <= end) return 'in_progress' as const;
  return now < start ? ('scheduled' as const) : ('completed' as const);
};

const isVisibleWindow = (window: MaintenanceWindow) => {
  const end = new Date(window.endAt).getTime();
  if (Number.isNaN(end)) return false;
  const thirtyDaysAgo = Date.now() - 30 * 24 * 60 * 60 * 1000;
  return end >= thirtyDaysAgo;
};

const getStatusPresentation = (status: MaintenanceWindow['status']) => {
  switch (status) {
    case 'in_progress':
      return {
        icon: faClock,
        label: 'Active',
        iconClass: 'bg-green-500/20 text-green-300',
        textClass: 'text-green-300',
      };
    case 'completed':
      return {
        icon: faCheckCircle,
        label: 'Completed',
        iconClass: 'bg-neutral-500/30 text-neutral-300',
        textClass: 'text-neutral-300',
      };
    default:
      return {
        icon: faCalendarAlt,
        label: 'Scheduled',
        iconClass: 'bg-blue-500/20 text-blue-200',
        textClass: 'text-blue-200',
      };
  }
};

const MaintenanceButton = () => {
  const serverId = ServerContext.useStoreState((state) => state.server.data!.uuid);
  const serverStatus = ServerContext.useStoreState((state) => state.status.value);
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

  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), 1000);
    return () => window.clearInterval(timer);
  }, []);

  const currentWindows = useMemo(
    () => windows.filter(isVisibleWindow).map((window) => ({ ...window, status: getStatus(window) })),
    [windows, now]
  );
  const hasActiveWindow = currentWindows.some((window) => window.status === 'in_progress');

  useEffect(() => {
    let mounted = true;
    void request<MaintenanceListResponse>(`/maintenance?serverId=${encodeURIComponent(serverId)}`)
      .then((remote) => {
        if (!mounted) return;
        setIsConfigured(remote.configured);
        setEnforceStop(remote.enforceStop ?? true);
        setWindows(remote.windows || []);
      })
      .catch((requestError: unknown) => {
        if (!mounted) return;
        setIsConfigured(true);
        setError(requestError instanceof Error ? requestError.message : 'Could not load maintenance windows.');
      });
    return () => {
      mounted = false;
    };
  }, [serverId]);

  useEffect(() => {
    const isStopControl = (button: HTMLButtonElement) => {
      const label = button.textContent?.replace(/\s+/g, ' ').trim().toLowerCase();
      return label === 'stop' || label === 'kill';
    };
    const syncStopControls = () => {
      document.querySelectorAll<HTMLButtonElement>('button').forEach((button) => {
        if (!isStopControl(button)) return;
        const disabled = serverStatus === 'offline' || (enforceStop && !hasActiveWindow);
        if (button.disabled !== disabled) button.disabled = disabled;
        button.title = disabled
          ? 'Create an active maintenance window before stopping this server'
          : 'Stop the server';
        button.setAttribute('aria-disabled', String(disabled));
      });
    };
    const preventInactiveStop = (event: MouseEvent) => {
      const target = event.target instanceof Element ? event.target.closest<HTMLButtonElement>('button') : null;
      if (target && isStopControl(target) && serverStatus !== 'offline' && enforceStop && !hasActiveWindow) {
        event.preventDefault();
        event.stopPropagation();
      }
    };

    syncStopControls();
    const observer = new MutationObserver(syncStopControls);
    if (document.body) observer.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled'] });
    document.addEventListener('click', preventInactiveStop, true);
    return () => {
      observer.disconnect();
      document.removeEventListener('click', preventInactiveStop, true);
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
    if (!draft.title.trim() || !draft.startAt || !draft.endAt) return setError('Enter a title and time range.');
    if (!isValidDateTimeLocal(draft.startAt) || !isValidDateTimeLocal(draft.endAt)) {
      return setError('Enter times in 24-hour HH:mm format.');
    }

    const startAt = new Date(draft.startAt).toISOString();
    const endAt = new Date(draft.endAt).toISOString();
    if (endAt <= startAt) return setError('The end time must be after the start time.');

    setIsLoading(true);
    const id = editingId || `mock-${Date.now()}`;
    const previous = windows.find((window) => window.id === id);
    const next: MaintenanceWindow = {
      id,
      title: draft.title.trim(),
      description: draft.description.trim(),
      startAt,
      endAt,
      status: 'scheduled',
      monitorIds: previous?.monitorIds,
    };

    try {
      if (editingId) {
        const saved = await request<MaintenanceWindow>(`/maintenance/${encodeURIComponent(editingId)}`, {
          method: 'PATCH',
          body: JSON.stringify({ serverId, startAt, endAt }),
        });
        setWindows((current) => current.map((window) => (window.id === editingId ? { ...next, ...saved } : window)));
      } else {
        const saved = await request<MaintenanceWindow>('/maintenance', {
          method: 'POST',
          body: JSON.stringify({ serverId, ...next }),
        });
        setWindows((current) => [...current, saved]);
      }
      resetEditor();
    } catch (requestError: unknown) {
      setError(requestError instanceof Error ? requestError.message : 'Could not save maintenance window.');
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
      await request(`/maintenance/${encodeURIComponent(id)}?serverId=${encodeURIComponent(serverId)}`, { method: 'DELETE' });
      setWindows((current) => current.filter((window) => window.id !== id));
      if (editingId === id) resetEditor();
    } catch (requestError: unknown) {
      setError(requestError instanceof Error ? requestError.message : 'Could not delete maintenance window.');
    } finally {
      setIsLoading(false);
    }
    setPendingDeleteId(undefined);
  };

  return (
    <div className={'col-span-6 min-w-0 w-full'}>
      <Button.Text
        className={'flex h-10 w-full items-center justify-center'}
        type={'button'}
        aria-haspopup={'dialog'}
        title={hasActiveWindow ? 'Active maintenance window' : 'Manage maintenance windows'}
        onClick={() => setIsOpen(true)}
      >
        <span className={'inline-flex items-center gap-2 leading-none'}>
          <FontAwesomeIcon
            className={hasActiveWindow ? 'text-green-300' : 'text-blue-200'}
            icon={hasActiveWindow ? faClock : faCalendarAlt}
            fixedWidth
            aria-hidden
          />
          <span>Maintenance</span>
        </span>
      </Button.Text>

      <Dialog
        open={isOpen}
        onClose={() => {
          setIsOpen(false);
          resetEditor();
        }}
        title={'Maintenance windows'}
        description={hasActiveWindow ? 'An active maintenance window is currently protecting this server.' : 'Manage planned maintenance windows for this server.'}
      >
        <div className={'mt-4 grid gap-5'}>

          {error && <p className={'rounded bg-red-500/20 p-2 text-sm text-red-100'}>{error}</p>}

          {currentWindows.length === 0 ? (
            <p className={'text-sm text-neutral-300'}>No maintenance windows have been created.</p>
          ) : (
            <div className={'grid gap-2'}>
              {currentWindows.map((window) => (
                <div key={window.id} className={'flex min-w-0 items-start gap-3 rounded border border-neutral-700 bg-neutral-800 p-3'}>
                  {(() => {
                    const presentation = getStatusPresentation(window.status);
                    return (
                      <span className={`flex h-8 w-8 flex-none items-center justify-center rounded ${presentation.iconClass}`}>
                        <FontAwesomeIcon icon={presentation.icon} fixedWidth title={presentation.label} />
                      </span>
                    );
                  })()}
                  <div className={'min-w-0 flex-1'}>
                    <p className={'font-semibold leading-5 text-gray-50'}>{window.title}</p>
                    <p className={'text-xs leading-4 text-neutral-300'}>
                      {formatDate(window.startAt)} - {formatDate(window.endAt)}
                    </p>
                    {window.description && <p className={'mt-1 break-words text-xs leading-4 text-neutral-300'}>{window.description}</p>}
                    {(() => {
                      const presentation = getStatusPresentation(window.status);
                      return <p className={`mt-1 text-[11px] font-semibold uppercase tracking-wide ${presentation.textClass}`}>{presentation.label}</p>;
                    })()}
                  </div>
                  <div className={'flex flex-none gap-1.5'}>
                    <Button
                      className={'px-2.5'}
                      type={'button'}
                      aria-label={`Edit ${window.title}`}
                      title={'Edit maintenance window'}
                      onClick={() => editWindow(window)}
                    >
                      <FontAwesomeIcon icon={faPencilAlt} fixedWidth />
                    </Button>
                    <Button.Danger
                      className={'px-2.5'}
                      type={'button'}
                      aria-label={`Delete ${window.title}`}
                      title={'Delete maintenance window'}
                      onClick={() => deleteWindow(window.id)}
                    >
                      <FontAwesomeIcon icon={faTrashAlt} fixedWidth />
                    </Button.Danger>
                  </div>
                </div>
              ))}
            </div>
          )}

          <form className={'grid gap-3 border-t border-neutral-600 pt-4'} onSubmit={saveWindow}>
            <h3 className={'font-semibold text-gray-50'}>{editingId ? 'Update window' : 'Create window'}</h3>
            <input className={'min-w-0 w-full rounded border border-neutral-600 bg-neutral-800 px-3 py-2 text-sm text-gray-50'} placeholder={'Title'} value={draft.title} onChange={(event) => setDraft({ ...draft, title: event.target.value })} required />
            <textarea className={'min-w-0 w-full rounded border border-neutral-600 bg-neutral-800 px-3 py-2 text-sm text-gray-50'} placeholder={'Description'} value={draft.description} onChange={(event) => setDraft({ ...draft, description: event.target.value })} rows={3} />
            <div className={'grid gap-3 sm:grid-cols-2'}>
              <DateTimeField label={'Starts'} value={draft.startAt} onChange={(startAt) => setDraft({ ...draft, startAt })} />
              <DateTimeField label={'Ends'} value={draft.endAt} onChange={(endAt) => setDraft({ ...draft, endAt })} />
            </div>
            <div className={'flex gap-2'}>
              <Button type={'submit'} disabled={isLoading}>{isLoading ? 'Saving…' : editingId ? 'Update' : 'Create'}</Button>
              {editingId && <Button type={'button'} onClick={resetEditor}>Cancel</Button>}
            </div>
          </form>
        </div>
      </Dialog>

      <Dialog
        open={Boolean(pendingDeleteId)}
        onClose={() => setPendingDeleteId(undefined)}
        title={'Delete maintenance window'}
        description={'This will permanently remove the selected maintenance window.'}
      >
        <div className={'mt-4 flex justify-end gap-2'}>
          <Button.Text type={'button'} onClick={() => setPendingDeleteId(undefined)} disabled={isLoading}>
            Cancel
          </Button.Text>
          <Button.Danger type={'button'} onClick={confirmDelete} disabled={isLoading}>
            {isLoading ? 'Deleting…' : 'Delete'}
          </Button.Danger>
        </div>
      </Dialog>
    </div>
  );
};

export default MaintenanceButton;
