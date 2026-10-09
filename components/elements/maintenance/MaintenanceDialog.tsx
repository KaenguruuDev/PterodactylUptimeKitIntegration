import React from "react";
import { Button } from "@/components/elements/button";
import { Dialog } from "@/components/elements/dialog";
import Icon from "@/components/elements/Icon";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import {
  faPencilAlt,
  faTrashAlt,
} from "@fortawesome/free-solid-svg-icons";
import { DateTimeField } from "./DateTimeField";
import {
  formatDate,
  getStatusPresentation,
  themedSecondarySurface,
} from "./utils";
import { MaintenanceDraft, MaintenanceWindow } from "./types";

type MaintenanceDialogProps = {
  isOpen: boolean;
  onClose: () => void;
  hasActiveWindow: boolean;
  error?: string;
  currentWindows: MaintenanceWindow[];
  onEdit: (window: MaintenanceWindow) => void;
  onDelete: (id: string) => void;
  editingId?: string;
  draft: MaintenanceDraft;
  setDraft: (draft: MaintenanceDraft) => void;
  isLoading: boolean;
  onSave: (event: React.FormEvent<HTMLFormElement>) => void;
  onReset: () => void;
  pendingDeleteId?: string;
  setPendingDeleteId: (id: string | undefined) => void;
  onConfirmDelete: () => void;
};

export const MaintenanceDialog = ({
  isOpen,
  onClose,
  hasActiveWindow,
  error,
  currentWindows,
  onEdit,
  onDelete,
  editingId,
  draft,
  setDraft,
  isLoading,
  onSave,
  onReset,
  pendingDeleteId,
  setPendingDeleteId,
  onConfirmDelete,
}: MaintenanceDialogProps) => (
  <>
    <Dialog
      open={isOpen}
      onClose={onClose}
      title={"Maintenance windows"}
      description={
        hasActiveWindow
          ? "An active maintenance window is currently protecting this server."
          : "Manage planned maintenance windows for this server."
      }
    >
      <div className={"mt-4 grid gap-5"}>
        {error && (
          <p className={"rounded bg-red-500/20 p-2 text-sm text-red-100"}>
            {error}
          </p>
        )}

        {currentWindows.length === 0 ? (
          <p className={"text-sm text-neutral-300"}>
            No maintenance windows have been created.
          </p>
        ) : (
          <div className={"grid gap-2"}>
            {currentWindows.map((window) => (
              <div
                key={window.id}
                className={
                  "flex min-w-0 items-start gap-3 rounded border border-neutral-700 bg-neutral-800 p-3"
                }
                style={themedSecondarySurface}
              >
                {(() => {
                  const presentation = getStatusPresentation(window.status);
                  return (
                    <span
                      className={`flex h-8 w-8 flex-none items-center justify-center rounded ${presentation.iconClass}`}
                    >
                      <FontAwesomeIcon
                        icon={presentation.icon}
                        fixedWidth
                        title={presentation.label}
                      />
                    </span>
                  );
                })()}
                <div className={"min-w-0 flex-1"}>
                  <p className={"font-semibold leading-5 text-gray-50"}>
                    {window.title}
                  </p>
                  <p className={"text-xs leading-4 text-neutral-300"}>
                    {formatDate(window.startAt)} - {formatDate(window.endAt)}
                  </p>
                  {window.description && (
                    <p
                      className={
                        "mt-1 break-words text-xs leading-4 text-neutral-300"
                      }
                    >
                      {window.description}
                    </p>
                  )}
                  {(() => {
                    const presentation = getStatusPresentation(window.status);
                    return (
                      <p
                        className={`mt-1 text-[11px] font-semibold uppercase tracking-wide ${presentation.textClass}`}
                      >
                        {presentation.label}
                      </p>
                    );
                  })()}
                </div>
                <div className={"flex flex-none gap-1.5"}>
                  <Button
                    style={{ padding: "0.375rem 0.625rem" }}
                    type={"button"}
                    aria-label={`Edit ${window.title}`}
                    title={"Edit maintenance window"}
                    onClick={() => onEdit(window)}
                  >
                    <span
                      className={"flex h-4 w-4 items-center justify-center"}
                    >
                      <Icon className={"h-3.5 w-3.5"} icon={faPencilAlt} />
                    </span>
                  </Button>
                  <Button.Danger
                    style={{ padding: "0.375rem 0.625rem" }}
                    type={"button"}
                    aria-label={`Delete ${window.title}`}
                    title={"Delete maintenance window"}
                    onClick={() => onDelete(window.id)}
                  >
                    <span
                      className={"flex h-4 w-4 items-center justify-center"}
                    >
                      <Icon className={"h-3.5 w-3.5"} icon={faTrashAlt} />
                    </span>
                  </Button.Danger>
                </div>
              </div>
            ))}
          </div>
        )}

        <form
          className={"grid gap-3 border-t border-neutral-600 pt-4"}
          onSubmit={onSave}
        >
          <h3 className={"font-semibold text-gray-50"}>
            {editingId ? "Update window" : "Create window"}
          </h3>
          <input
            className={
              "min-w-0 w-full rounded border border-neutral-600 bg-neutral-800 px-3 py-2 text-sm text-gray-50"
            }
            style={themedSecondarySurface}
            placeholder={"Title"}
            value={draft.title}
            onChange={(event) =>
              setDraft({ ...draft, title: event.target.value })
            }
            required
          />
          <textarea
            className={
              "min-w-0 w-full rounded border border-neutral-600 bg-neutral-800 px-3 py-2 text-sm text-gray-50"
            }
            style={themedSecondarySurface}
            placeholder={"Description"}
            value={draft.description}
            onChange={(event) =>
              setDraft({ ...draft, description: event.target.value })
            }
            rows={3}
          />
          <div className={"grid gap-3 sm:grid-cols-2"}>
            <DateTimeField
              label={"Starts"}
              value={draft.startAt}
              onChange={(startAt) => setDraft({ ...draft, startAt })}
            />
            <DateTimeField
              label={"Ends"}
              value={draft.endAt}
              onChange={(endAt) => setDraft({ ...draft, endAt })}
            />
          </div>
          <div className={"flex gap-2"}>
            <Button type={"submit"} disabled={isLoading}>
              {isLoading ? "Saving…" : editingId ? "Update" : "Create"}
            </Button>
            {editingId && (
              <Button.Danger type={"button"} onClick={onReset}>
                Cancel
              </Button.Danger>
            )}
          </div>
        </form>
      </div>
    </Dialog>

    <Dialog
      open={Boolean(pendingDeleteId)}
      onClose={() => setPendingDeleteId(undefined)}
      title={"Delete maintenance window"}
      description={
        "This will permanently remove the selected maintenance window."
      }
    >
      <div className={"mt-4 flex justify-end gap-2"}>
        <Button.Danger
          type={"button"}
          onClick={() => setPendingDeleteId(undefined)}
          disabled={isLoading}
        >
          Cancel
        </Button.Danger>
        <Button.Danger
          type={"button"}
          onClick={onConfirmDelete}
          disabled={isLoading}
        >
          {isLoading ? "Deleting…" : "Delete"}
        </Button.Danger>
      </div>
    </Dialog>
  </>
);
