"""Persistent local mock API for the maintenance-window UI.

Run with:
    python maintenance_mock_server.py

Data is stored in maintenance-data.json next to this file.
"""

from __future__ import annotations

import json
import os
import threading
import uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlparse


HOST = os.environ.get("MAINTENANCE_MOCK_HOST", "0.0.0.0")
PORT = int(os.environ.get("MAINTENANCE_MOCK_PORT", "8765"))
DATA_FILE = Path(__file__).with_name("maintenance-data.json")
LOCK = threading.Lock()


def read_windows() -> list[dict]:
    if not DATA_FILE.exists():
        return []
    try:
        value = json.loads(DATA_FILE.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return []
    return value if isinstance(value, list) else []


def write_windows(windows: list[dict]) -> None:
    temporary_file = DATA_FILE.with_suffix(".tmp")
    temporary_file.write_text(json.dumps(windows, indent=2) + "\n", encoding="utf-8")
    temporary_file.replace(DATA_FILE)


def maintenance_id(path: str) -> str | None:
    parts = [part for part in path.split("/") if part]
    return parts[1] if len(parts) == 2 and parts[0] == "maintenance" else None


class Handler(BaseHTTPRequestHandler):
    def send_json(self, status: int, payload: object) -> None:
        body = json.dumps(payload).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, PATCH, DELETE, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type")
        self.end_headers()
        self.wfile.write(body)

    def read_json(self) -> dict:
        length = int(self.headers.get("Content-Length", "0"))
        value = json.loads(self.rfile.read(length) or b"{}")
        return value if isinstance(value, dict) else {}

    def do_OPTIONS(self) -> None:  # noqa: N802
        self.send_json(204, {})

    def do_GET(self) -> None:  # noqa: N802
        parsed = urlparse(self.path)
        item_id = maintenance_id(parsed.path)

        with LOCK:
            windows = read_windows()

        if item_id:
            match = next((window for window in windows if window["id"] == item_id), None)
            self.send_json(200, match) if match else self.send_json(404, {"error": "Maintenance not found"})
            return

        if parsed.path != "/maintenance":
            self.send_json(404, {"error": "Not found"})
            return

        status_page_id = parse_qs(parsed.query).get("statusPageId", [None])[0]
        if status_page_id:
            windows = [window for window in windows if window.get("statusPageId") == status_page_id]
        self.send_json(200, windows)

    def do_POST(self) -> None:  # noqa: N802
        if urlparse(self.path).path != "/maintenance":
            self.send_json(404, {"error": "Not found"})
            return

        payload = self.read_json()
        required = ("statusPageId", "title", "startAt", "endAt", "status")
        missing = [field for field in required if not payload.get(field)]
        if missing:
            self.send_json(422, {"error": "Missing fields", "fields": missing})
            return

        window = {**payload, "id": payload.get("id") or str(uuid.uuid4())}
        with LOCK:
            windows = read_windows()
            windows.append(window)
            write_windows(windows)
        self.send_json(200, window)

    def do_PATCH(self) -> None:  # noqa: N802
        item_id = maintenance_id(urlparse(self.path).path)
        if not item_id:
            self.send_json(404, {"error": "Not found"})
            return

        payload = self.read_json()
        with LOCK:
            windows = read_windows()
            match = next((window for window in windows if window["id"] == item_id), None)
            if not match:
                self.send_json(404, {"error": "Maintenance not found"})
                return
            match.update({key: payload[key] for key in ("startAt", "endAt") if key in payload})
            write_windows(windows)
        self.send_json(200, match)

    def do_DELETE(self) -> None:  # noqa: N802
        item_id = maintenance_id(urlparse(self.path).path)
        if not item_id:
            self.send_json(404, {"error": "Not found"})
            return

        with LOCK:
            windows = read_windows()
            remaining = [window for window in windows if window["id"] != item_id]
            if len(remaining) == len(windows):
                self.send_json(404, {"error": "Maintenance not found"})
                return
            write_windows(remaining)
        self.send_json(200, {"deleted": item_id})

    def log_message(self, format: str, *args: object) -> None:
        print(f"{self.address_string()} - {format % args}")


if __name__ == "__main__":
    server = ThreadingHTTPServer((HOST, PORT), Handler)
    print(f"Maintenance mock API listening on http://{HOST}:{PORT}")
    print(f"Persistent data file: {DATA_FILE}")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nStopping maintenance mock API")
    finally:
        server.server_close()
