import React from "react";
import { themedSecondarySurface } from "./utils";

export const DateTimeField = ({
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
  const update = (nextDate: string, nextTime: string) =>
    onChange(nextDate ? `${nextDate}T${nextTime || "00:00"}` : "");

  return (
    <fieldset className={"grid gap-2"}>
      <legend className={"text-xs text-neutral-300"}>{label}</legend>
      <div className={"grid gap-2 sm:grid-cols-5"}>
        <input
          className={
            "min-w-0 w-full rounded border border-neutral-600 bg-neutral-800 px-3 py-2 text-sm text-gray-50 sm:col-span-3"
          }
          style={themedSecondarySurface}
          type={"date"}
          value={date}
          onChange={(event) => update(event.target.value, time)}
          required
        />
        <input
          className={
            "min-w-0 w-full rounded border border-neutral-600 bg-neutral-800 px-3 py-2 text-sm text-gray-50 sm:col-span-2"
          }
          style={themedSecondarySurface}
          type={"text"}
          inputMode={"numeric"}
          placeholder={"HH:mm"}
          pattern={"[0-9]{2}:[0-9]{2}"}
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
