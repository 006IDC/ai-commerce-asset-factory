#!/usr/bin/env python3
"""Loopback-only OpenAI/xAI media adapter for Ark and Zhipu.

The service deliberately keeps provider credentials out of Sub2API. It
exchanges Volcengine AK/SK credentials for short-lived per-model Ark keys and
translates Sub2API-compatible media requests into provider-specific calls.
"""

from __future__ import annotations

import base64
import json
import logging
import os
from pathlib import Path
import secrets
import signal
import stat
import sys
import threading
import urllib.error
import urllib.request
from email.parser import BytesParser
from email.policy import default as email_policy
from http import HTTPStatus
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any
from urllib.parse import urlparse

from volcenginesdkarkruntime import Ark
from volcenginesdkarkruntime._client import StsTokenManager


SEEDREAM_MODEL = "gpt-image-seedream-5"
GLM_IMAGE_MODEL = "gpt-image-glm"
SEEDANCE_MODEL = "grok-imagine-video-seedance-2"
ARK_IMAGE_MODEL = "doubao-seedream-5-0-260128"
ARK_VIDEO_MODEL = "doubao-seedance-2-0-260128"
ZHIPU_IMAGE_MODEL = "glm-image"
ZHIPU_IMAGE_URL = "https://open.bigmodel.cn/api/paas/v4/images/generations"
MODELS = {
    SEEDREAM_MODEL: {
        "owned_by": "volcengine",
        "capabilities": ["image_generation", "image_edit"],
    },
    GLM_IMAGE_MODEL: {
        "owned_by": "zhipu",
        "capabilities": ["image_generation"],
    },
    SEEDANCE_MODEL: {
        "owned_by": "volcengine",
        "capabilities": ["video_i2v", "video_t2v"],
    },
}
MAX_BODY_BYTES = 64 * 1024 * 1024
IMAGE_MIME_TYPES = {"image/jpeg", "image/png", "image/webp"}
BRIDGE_SECRET_NAMES = ("VOLC_ACCESSKEY", "VOLC_SECRETKEY", "ZHIPU_API_KEY", "ARK_BRIDGE_API_KEY")


def bridge_credentials() -> dict[str, str]:
    """Read systemd's private runtime credential, or the legacy process env."""
    directory = os.environ.get("CREDENTIALS_DIRECTORY", "").strip()
    if not directory:
        return {name: os.environ.get(name, "").strip() for name in BRIDGE_SECRET_NAMES}
    try:
        descriptor = os.open(Path(directory) / "ark-bridge-secrets", os.O_RDONLY | os.O_NOFOLLOW)
        with os.fdopen(descriptor, "r", encoding="utf-8") as credential:
            metadata = os.fstat(credential.fileno())
            if (not stat.S_ISREG(metadata.st_mode)
                    or metadata.st_uid not in {0, os.geteuid()}
                    or metadata.st_mode & 0o077):
                raise ValueError("credential is not private")
            content = credential.read(65537)
        if len(content) > 65536:
            raise ValueError("credential is oversized")
        values = json.loads(content)
        if (not isinstance(values, dict) or set(values) != set(BRIDGE_SECRET_NAMES)
                or any(not isinstance(values[name], str) or not values[name].strip()
                       or "\n" in values[name] or "\r" in values[name]
                       for name in BRIDGE_SECRET_NAMES)):
            raise ValueError("credential schema is invalid")
        return {name: values[name].strip() for name in BRIDGE_SECRET_NAMES}
    except (OSError, ValueError, UnicodeError):
        raise RuntimeError("Ark Bridge runtime credential is missing, invalid or not private") from None


class BridgeError(RuntimeError):
    def __init__(self, status: int, code: str, message: str):
        super().__init__(message)
        self.status = status
        self.code = code
        self.message = message


class ArkBridge:
    def __init__(self) -> None:
        credentials = bridge_credentials()
        access_key = credentials["VOLC_ACCESSKEY"]
        secret_key = credentials["VOLC_SECRETKEY"]
        self.zhipu_api_key = credentials["ZHIPU_API_KEY"]
        self.internal_key = credentials["ARK_BRIDGE_API_KEY"]
        self.region = os.environ.get("VOLC_REGION", "cn-beijing").strip()
        self.project = os.environ.get("VOLC_PROJECT", "default").strip()
        if (
            not access_key
            or not secret_key
            or not self.zhipu_api_key
            or not self.internal_key
        ):
            raise RuntimeError(
                "VOLC_ACCESSKEY, VOLC_SECRETKEY, ZHIPU_API_KEY and "
                "ARK_BRIDGE_API_KEY are required"
            )
        self.tokens = StsTokenManager(access_key, secret_key, self.region)

    def authorize(self, value: str) -> None:
        supplied = value.removeprefix("Bearer ").strip()
        if not supplied or not secrets.compare_digest(supplied, self.internal_key):
            raise BridgeError(HTTPStatus.UNAUTHORIZED, "invalid_api_key", "Invalid API key")

    def client(self, model: str, timeout: int = 180) -> Ark:
        upstream_model = {
            SEEDREAM_MODEL: ARK_IMAGE_MODEL,
            SEEDANCE_MODEL: ARK_VIDEO_MODEL,
        }.get(model)
        if not upstream_model:
            raise BridgeError(
                HTTPStatus.BAD_REQUEST,
                "model_not_supported",
                f"Model {model} is not supported by this adapter",
            )
        token = self.tokens.get(
            upstream_model,
            resource_type="presetendpoint",
            project_name=self.project,
        )
        return Ark(api_key=token, timeout=timeout, max_retries=1)

    def models(self) -> dict[str, Any]:
        return {
            "object": "list",
            "data": [
                {
                    "id": model,
                    "object": "model",
                    "owned_by": config["owned_by"],
                    "capabilities": config["capabilities"],
                }
                for model, config in MODELS.items()
            ],
        }

    def generate_image(self, payload: dict[str, Any]) -> dict[str, Any]:
        model = clean_text(payload.get("model")) or SEEDREAM_MODEL
        if model == GLM_IMAGE_MODEL:
            return self.generate_glm_image(payload)
        if model != SEEDREAM_MODEL:
            raise BridgeError(
                HTTPStatus.BAD_REQUEST,
                "invalid_model",
                "Seedream or GLM-Image model required",
            )
        prompt = clean_text(payload.get("prompt"))
        if not prompt:
            raise BridgeError(HTTPStatus.BAD_REQUEST, "invalid_prompt", "prompt is required")
        images = normalize_image_values(
            payload.get("image")
            or payload.get("images")
            or payload.get("reference_images")
        )
        response = self.client(model, 480).images.generate(
            model=ARK_IMAGE_MODEL,
            prompt=prompt,
            image=images or None,
            response_format="url",
            size=normalize_image_size(payload.get("size")),
            seed=normalize_image_seed(payload.get("seed")),
            watermark=bool(payload.get("watermark", False)),
            optimize_prompt=bool(payload.get("optimize_prompt", False)),
        )
        result = model_json(response)
        result["model"] = SEEDREAM_MODEL
        return result

    def generate_glm_image(self, payload: dict[str, Any]) -> dict[str, Any]:
        prompt = clean_text(payload.get("prompt"))
        if not prompt:
            raise BridgeError(HTTPStatus.BAD_REQUEST, "invalid_prompt", "prompt is required")
        if normalize_image_values(
            payload.get("image")
            or payload.get("images")
            or payload.get("reference_images")
        ):
            raise BridgeError(
                HTTPStatus.UNPROCESSABLE_ENTITY,
                "reference_not_supported",
                "GLM-Image currently supports text-to-image only",
            )
        provider_payload = {
            "model": ZHIPU_IMAGE_MODEL,
            "prompt": prompt[:1000],
            "size": normalize_glm_image_size(payload.get("size")),
        }
        request = urllib.request.Request(
            ZHIPU_IMAGE_URL,
            data=json.dumps(provider_payload, ensure_ascii=False).encode("utf-8"),
            headers={
                "Authorization": "Bearer " + self.zhipu_api_key,
                "Content-Type": "application/json",
            },
            method="POST",
        )
        try:
            with urllib.request.urlopen(request, timeout=180) as response:
                result = json.loads(response.read())
        except urllib.error.HTTPError as error:
            raw = error.read()
            try:
                details = json.loads(raw) if raw else {}
            except json.JSONDecodeError:
                details = {}
            provider_error = details.get("error") if isinstance(details, dict) else {}
            message = clean_text(
                provider_error.get("message")
                if isinstance(provider_error, dict)
                else details
            )
            raise BridgeError(
                error.code,
                clean_text(
                    provider_error.get("code")
                    if isinstance(provider_error, dict)
                    else "zhipu_error"
                )
                or "zhipu_error",
                message or "GLM-Image request failed",
            ) from error
        except (urllib.error.URLError, TimeoutError) as error:
            raise BridgeError(
                HTTPStatus.BAD_GATEWAY,
                "zhipu_network_error",
                "GLM-Image upstream is temporarily unavailable",
            ) from error
        if not isinstance(result, dict):
            raise BridgeError(
                HTTPStatus.BAD_GATEWAY,
                "invalid_response",
                "GLM-Image returned an invalid response",
            )
        result["model"] = GLM_IMAGE_MODEL
        return result

    def edit_image(
        self,
        fields: dict[str, str],
        uploads: list[dict[str, Any]],
        mask: dict[str, Any] | None,
    ) -> dict[str, Any]:
        if mask is not None:
            raise BridgeError(
                HTTPStatus.UNPROCESSABLE_ENTITY,
                "mask_not_supported",
                "Seedream supports reference-image editing but not pixel masks",
            )
        model = clean_text(fields.get("model")) or SEEDREAM_MODEL
        prompt = clean_text(fields.get("prompt"))
        if model != SEEDREAM_MODEL or not prompt:
            raise BridgeError(
                HTTPStatus.BAD_REQUEST,
                "invalid_request",
                "Seedream model and prompt are required",
            )
        if not uploads:
            raise BridgeError(
                HTTPStatus.BAD_REQUEST,
                "reference_required",
                "At least one reference image is required",
            )
        references = [upload_data_url(upload) for upload in uploads[:6]]
        return self.generate_image(
            {
                "model": model,
                "prompt": prompt,
                "image": references,
                "size": fields.get("size"),
                "seed": fields.get("seed"),
                "watermark": False,
                "optimize_prompt": False,
            }
        )

    def create_video(self, payload: dict[str, Any]) -> dict[str, Any]:
        model = clean_text(payload.get("model")) or SEEDANCE_MODEL
        if model != SEEDANCE_MODEL:
            raise BridgeError(HTTPStatus.BAD_REQUEST, "invalid_model", "Seedance model required")
        prompt = clean_text(payload.get("prompt"))
        if not prompt:
            raise BridgeError(HTTPStatus.BAD_REQUEST, "invalid_prompt", "prompt is required")
        content: list[dict[str, Any]] = [{"type": "text", "text": prompt}]
        references = normalize_image_values(
            payload.get("image")
            or payload.get("images")
            or payload.get("reference_images")
        )
        for reference in references[:6]:
            content.append(
                {
                    "type": "image_url",
                    "image_url": {"url": reference},
                    "role": "reference_image",
                }
            )
        create_options: dict[str, Any] = {
            "model": ARK_VIDEO_MODEL,
            "content": content,
            "safety_identifier": clean_text(payload.get("safety_identifier")) or None,
            "return_last_frame": True,
            "generate_audio": bool(payload.get("generate_audio", False)),
            "watermark": bool(payload.get("watermark", False)),
            "resolution": normalize_video_resolution(payload.get("resolution")),
            "ratio": normalize_video_ratio(
                payload.get("ratio") or payload.get("aspect_ratio")
            ),
            "duration": normalize_video_duration(payload.get("duration")),
        }
        # Seedance 2.0 currently rejects camera_fixed for both text-to-video
        # and reference-to-video requests, even when the value is false.
        # Omit the provider field instead of turning a valid request into 400.
        response = self.client(model, 180).content_generation.tasks.create(
            **create_options
        )
        task_id = clean_text(getattr(response, "id", ""))
        if not task_id:
            raise BridgeError(
                HTTPStatus.BAD_GATEWAY,
                "missing_task_id",
                "Ark did not return a video task ID",
            )
        return {
            "id": task_id,
            "request_id": task_id,
            "object": "video.generation",
            "model": model,
            "status": "queued",
        }

    def video_status(self, task_id: str) -> dict[str, Any]:
        if not task_id:
            raise BridgeError(HTTPStatus.BAD_REQUEST, "invalid_task_id", "task ID is required")
        response = self.client(SEEDANCE_MODEL, 180).content_generation.tasks.get(
            task_id=task_id
        )
        data = model_json(response)
        content = data.get("content") if isinstance(data.get("content"), dict) else {}
        video_url = clean_text(content.get("video_url") or content.get("file_url"))
        status = clean_text(data.get("status")) or "unknown"
        result = {
            "id": task_id,
            "request_id": task_id,
            "object": "video.generation",
            "model": SEEDANCE_MODEL,
            "status": status,
            "progress": 100 if status == "succeeded" else 0,
            "duration": optional_int(data.get("duration")),
            "resolution": clean_text(data.get("resolution")),
            "ratio": clean_text(data.get("ratio")),
            "usage": data.get("usage") if isinstance(data.get("usage"), dict) else {},
        }
        if video_url:
            result["video"] = {"url": video_url}
            result["url"] = video_url
        error = data.get("error")
        if isinstance(error, dict) and (error.get("code") or error.get("message")):
            result["error"] = {
                "code": clean_text(error.get("code")),
                "message": clean_text(error.get("message")),
            }
        return result


def clean_text(value: Any) -> str:
    return str(value or "").strip()


def optional_int(value: Any) -> int | None:
    if value is None or value == "":
        return None
    try:
        return int(value)
    except (TypeError, ValueError):
        return None


def normalize_image_seed(value: Any) -> int | None:
    seed = optional_int(value)
    if seed is None:
        return None
    return max(1, min(2_147_483_647, seed))


def model_json(value: Any) -> dict[str, Any]:
    if hasattr(value, "model_dump"):
        try:
            data = value.model_dump(mode="json", exclude_none=True)
            return data if isinstance(data, dict) else {}
        except (TypeError, ValueError):
            # Ark currently supports Pydantic v1, whose compatibility
            # model_dump method rejects the v2-only mode argument.
            pass
    if hasattr(value, "json"):
        try:
            data = json.loads(value.json(exclude_none=True))
            return data if isinstance(data, dict) else {}
        except (TypeError, ValueError, json.JSONDecodeError):
            pass
    if hasattr(value, "dict"):
        data = value.dict(exclude_none=True)
        return data if isinstance(data, dict) else {}
    if isinstance(value, dict):
        return value
    return {}


def normalize_image_values(value: Any) -> list[str]:
    values = value if isinstance(value, list) else [value]
    normalized: list[str] = []
    for item in values:
        if isinstance(item, dict):
            item = item.get("url") or item.get("image_url")
        text = clean_text(item)
        if text:
            normalized.append(text)
    return normalized


def normalize_image_size(value: Any) -> str:
    size = clean_text(value).lower()
    seedream_sizes = {
        "1024x1024": "2048x2048",
        "1536x1024": "2496x1664",
        "1024x1536": "1664x2496",
        "2048x2048": "2048x2048",
    }
    return seedream_sizes.get(size, "2048x2048")


def normalize_glm_image_size(value: Any) -> str:
    size = clean_text(value).lower()
    if "x" not in size:
        return "1024x1024"
    width_text, height_text = size.split("x", 1)
    try:
        width = int(width_text)
        height = int(height_text)
    except ValueError:
        return "1024x1024"
    if (
        512 <= width <= 2048
        and 512 <= height <= 2048
        and width % 32 == 0
        and height % 32 == 0
    ):
        return f"{width}x{height}"
    return "1024x1024"


def normalize_video_resolution(value: Any) -> str:
    resolution = clean_text(value).lower()
    return resolution if resolution in {"720p", "1080p"} else "720p"


def normalize_video_ratio(value: Any) -> str:
    ratio = clean_text(value)
    return ratio if ratio in {"1:1", "16:9", "9:16", "4:3", "3:4"} else "9:16"


def normalize_video_duration(value: Any) -> int:
    duration = optional_int(value) or 5
    return max(5, min(10, duration))


def upload_data_url(upload: dict[str, Any]) -> str:
    content = upload.get("content")
    mime = clean_text(upload.get("content_type")).lower()
    if not isinstance(content, bytes) or not content or mime not in IMAGE_MIME_TYPES:
        raise BridgeError(
            HTTPStatus.UNPROCESSABLE_ENTITY,
            "invalid_image",
            "Reference images must be non-empty JPG, PNG or WebP files",
        )
    return f"data:{mime};base64,{base64.b64encode(content).decode('ascii')}"


def parse_multipart(content_type: str, body: bytes) -> tuple[dict[str, str], list[dict[str, Any]], dict[str, Any] | None]:
    message = BytesParser(policy=email_policy).parsebytes(
        b"Content-Type: "
        + content_type.encode("ascii", "ignore")
        + b"\r\nMIME-Version: 1.0\r\n\r\n"
        + body
    )
    if not message.is_multipart():
        raise BridgeError(
            HTTPStatus.BAD_REQUEST, "invalid_multipart", "Invalid multipart request"
        )
    fields: dict[str, str] = {}
    uploads: list[dict[str, Any]] = []
    mask: dict[str, Any] | None = None
    for part in message.iter_parts():
        name = clean_text(part.get_param("name", header="content-disposition"))
        filename = clean_text(part.get_filename())
        content = part.get_payload(decode=True) or b""
        if filename:
            upload = {
                "field": name,
                "filename": filename,
                "content_type": clean_text(part.get_content_type()).lower(),
                "content": content,
            }
            if name == "mask":
                mask = upload
            elif name == "image" or name.startswith("image["):
                uploads.append(upload)
        elif name:
            fields[name] = content.decode(part.get_content_charset() or "utf-8", "replace")
    return fields, uploads, mask


class Handler(BaseHTTPRequestHandler):
    bridge: ArkBridge
    server_version = "ArkBridge/1.0"

    def log_message(self, fmt: str, *args: Any) -> None:
        logging.info("%s %s", self.address_string(), fmt % args)

    def do_GET(self) -> None:
        try:
            path = urlparse(self.path).path.rstrip("/")
            if path == "/healthz":
                self.send_json(HTTPStatus.OK, {"status": "ok"})
                return
            self.bridge.authorize(self.headers.get("Authorization", ""))
            if path == "/v1/models":
                self.send_json(HTTPStatus.OK, self.bridge.models())
                return
            if path.startswith("/v1/videos/"):
                suffix = path.removeprefix("/v1/videos/")
                if suffix.endswith("/content"):
                    task_id = suffix.removesuffix("/content").rstrip("/")
                    status = self.bridge.video_status(task_id)
                    url = clean_text(status.get("url"))
                    if not url:
                        raise BridgeError(
                            HTTPStatus.CONFLICT,
                            "video_not_ready",
                            "Video content is not ready",
                        )
                    self.send_response(HTTPStatus.FOUND)
                    self.send_header("Location", url)
                    self.send_header("Content-Length", "0")
                    self.end_headers()
                    return
                self.send_json(HTTPStatus.OK, self.bridge.video_status(suffix))
                return
            raise BridgeError(HTTPStatus.NOT_FOUND, "not_found", "Endpoint not found")
        except Exception as exception:
            self.send_exception(exception)

    def do_POST(self) -> None:
        try:
            self.bridge.authorize(self.headers.get("Authorization", ""))
            path = urlparse(self.path).path.rstrip("/")
            content_type = self.headers.get("Content-Type", "")
            body = self.read_body()
            if path == "/v1/images/edits":
                fields, uploads, mask = parse_multipart(content_type, body)
                self.send_json(
                    HTTPStatus.OK, self.bridge.edit_image(fields, uploads, mask)
                )
                return
            payload = json.loads(body or b"{}")
            if not isinstance(payload, dict):
                raise BridgeError(
                    HTTPStatus.BAD_REQUEST, "invalid_json", "JSON object required"
                )
            if path == "/v1/images/generations":
                self.send_json(HTTPStatus.OK, self.bridge.generate_image(payload))
                return
            if path == "/v1/videos/generations":
                self.send_json(HTTPStatus.OK, self.bridge.create_video(payload))
                return
            raise BridgeError(HTTPStatus.NOT_FOUND, "not_found", "Endpoint not found")
        except json.JSONDecodeError:
            self.send_exception(
                BridgeError(HTTPStatus.BAD_REQUEST, "invalid_json", "Invalid JSON")
            )
        except Exception as exception:
            self.send_exception(exception)

    def read_body(self) -> bytes:
        try:
            length = int(self.headers.get("Content-Length", "0"))
        except ValueError as exception:
            raise BridgeError(
                HTTPStatus.BAD_REQUEST, "invalid_length", "Invalid Content-Length"
            ) from exception
        if length < 0 or length > MAX_BODY_BYTES:
            raise BridgeError(
                HTTPStatus.REQUEST_ENTITY_TOO_LARGE,
                "request_too_large",
                "Request body exceeds 64 MB",
            )
        return self.rfile.read(length)

    def send_exception(self, exception: Exception) -> None:
        if isinstance(exception, BridgeError):
            status = exception.status
            code = exception.code
            message = exception.message
        else:
            status = HTTPStatus.BAD_GATEWAY
            code = getattr(exception, "code", None) or type(exception).__name__
            message = str(exception)[:800] or "Ark upstream request failed"
            logging.exception("Ark bridge request failed")
        self.send_json(
            status,
            {"error": {"type": "upstream_error", "code": str(code), "message": message}},
        )

    def send_json(self, status: int, payload: dict[str, Any]) -> None:
        content = json.dumps(
            payload, ensure_ascii=False, separators=(",", ":")
        ).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(content)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(content)


def main() -> int:
    logging.basicConfig(
        level=os.environ.get("LOG_LEVEL", "INFO").upper(),
        format="%(asctime)s %(levelname)s %(message)s",
    )
    Handler.bridge = ArkBridge()
    host = os.environ.get("ARK_BRIDGE_HOST", "127.0.0.1")
    port = int(os.environ.get("ARK_BRIDGE_PORT", "3010"))
    server = ThreadingHTTPServer((host, port), Handler)

    def stop(_signum: int, _frame: Any) -> None:
        threading.Thread(target=server.shutdown, daemon=True).start()

    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT, stop)
    logging.info("Ark bridge listening on %s:%s", host, port)
    server.serve_forever(poll_interval=0.5)
    server.server_close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
