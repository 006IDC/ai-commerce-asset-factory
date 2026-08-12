"""Production-controlled ComfyUI output nodes for the asset factory.

The node executes visual workflow steps, but obtains model execution through a
short-lived ThinkPHP callback. Provider credentials, routing, billing and task
state never enter ComfyUI.
"""

from __future__ import annotations

import base64
import hashlib
import ipaddress
import json
import mimetypes
import os
import re
import secrets
import socket
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path
from typing import Any
from urllib.parse import urlparse

import folder_paths
from PIL import Image, ImageColor, ImageDraw, ImageFont


MAX_REFERENCE_COUNT = 6
MAX_REFERENCE_BYTES = 48 * 1024 * 1024
MAX_OUTPUT_BYTES = 25 * 1024 * 1024
MAX_VIDEO_BYTES = 512 * 1024 * 1024
MAX_RESPONSE_BYTES = 36 * 1024 * 1024
MAX_SEED = 2_147_483_647
SUPPORTED_SIZES = {"1024x1024", "1536x1024", "1024x1536"}
ALLOWED_IMAGE_FORMATS = {
    "JPEG": ("image/jpeg", ".jpg"),
    "PNG": ("image/png", ".png"),
    "WEBP": ("image/webp", ".webp"),
}


class AssetFactoryNodeError(RuntimeError):
    """A safe, user-readable node error."""


class AssetFactorySub2ApiImage:
    """Call Sub2API with the ComfyUI-only service credential."""

    RETURN_TYPES = ("IMAGE", "STRING", "STRING", "INT", "STRING")
    RETURN_NAMES = (
        "image",
        "sub2api_request_id",
        "model",
        "duration_ms",
        "usage_json",
    )
    FUNCTION = "generate"
    CATEGORY = "Asset Factory/Production V2"
    DESCRIPTION = (
        "Provider node for complex workflows. The Sub2API credential is loaded "
        "from the systemd credential directory and never enters workflow JSON."
    )

    @classmethod
    def INPUT_TYPES(cls) -> dict[str, Any]:
        return {
            "required": {
                "prompt": (
                    "STRING",
                    {"multiline": True, "default": "", "dynamicPrompts": False},
                ),
                "negative_prompt": (
                    "STRING",
                    {"multiline": True, "default": "", "dynamicPrompts": False},
                ),
                "reference_images_json": (
                    "STRING",
                    {"multiline": False, "default": "[]", "dynamicPrompts": False},
                ),
                "mask_image": (
                    "STRING",
                    {"multiline": False, "default": "", "dynamicPrompts": False},
                ),
                "sub2api_model": (
                    "STRING",
                    {"multiline": False, "default": "gpt-image-2", "dynamicPrompts": False},
                ),
                "size": (sorted(SUPPORTED_SIZES),),
                "seed": ("INT", {"default": 1, "min": 1, "max": MAX_SEED}),
                "job_no": (
                    "STRING",
                    {"multiline": False, "default": "", "dynamicPrompts": False},
                ),
                "item_id": (
                    "INT",
                    {"default": 0, "min": 0, "max": 9_223_372_036_854_775_807},
                ),
                "workflow_code": (
                    "STRING",
                    {"multiline": False, "default": "", "dynamicPrompts": False},
                ),
                "workflow_version": (
                    "INT",
                    {"default": 1, "min": 1, "max": 1_000_000},
                ),
                "idempotency_key": (
                    "STRING",
                    {"multiline": False, "default": "", "dynamicPrompts": False},
                ),
            }
        }

    def generate(
        self,
        prompt: str,
        negative_prompt: str,
        reference_images_json: str,
        mask_image: str,
        sub2api_model: str,
        size: str,
        seed: int,
        job_no: str,
        item_id: int,
        workflow_code: str,
        workflow_version: int,
        idempotency_key: str,
    ) -> tuple[Any, str, str, int, str]:
        prompt = str(prompt or "").strip()
        model = str(sub2api_model or "").strip()
        if not prompt or not model:
            raise AssetFactoryNodeError("Sub2API image prompt and model are required.")
        if size not in SUPPORTED_SIZES:
            raise AssetFactoryNodeError("The requested Sub2API image size is not supported.")
        negative_prompt = str(negative_prompt or "").strip()
        if negative_prompt:
            prompt += "\n\nNegative constraints:\n" + negative_prompt
        references = [_read_reference(name) for name in _decode_references(reference_images_json)]
        mask_name = str(mask_image or "").strip()
        mask = _read_reference(mask_name) if mask_name else None
        request_identity = _paid_request_identity(
            idempotency_key,
            "image",
            job_no,
            item_id,
            workflow_code,
            workflow_version,
        )
        prior = _begin_paid_dispatch(request_identity, "image")
        if prior is not None:
            content = _cached_dispatch_content(prior, request_identity)
            return (
                _image_tensor(content),
                str(prior.get("request_id") or request_identity),
                str(prior.get("model") or model),
                int(prior.get("duration_ms") or 0),
                json.dumps(prior.get("usage") or {}, separators=(",", ":")),
            )

        started = time.monotonic()
        response = _request_sub2api_image(
            prompt=prompt,
            references=references,
            mask=mask,
            model=model,
            size=size,
            seed=max(1, min(MAX_SEED, int(seed))),
            request_identity=request_identity,
        )
        duration_ms = round((time.monotonic() - started) * 1000)
        content = _response_image(response)
        _verify_image(content)
        request_id = str(
            response.get("id")
            or response.get("_asset_factory_request_id")
            or request_identity
        ).strip()
        usage = _safe_usage(response.get("usage"))
        _complete_paid_dispatch(
            request_identity,
            "image",
            content,
            {
                "request_id": request_id,
                "model": model,
                "duration_ms": duration_ms,
                "usage": usage,
            },
        )
        return (
            _image_tensor(content),
            request_id,
            model,
            duration_ms,
            json.dumps(usage, separators=(",", ":")),
        )


class AssetFactorySaveImage:
    """Save a verified IMAGE result using the controlled output path."""

    RETURN_TYPES: tuple[str, ...] = ()
    FUNCTION = "save"
    OUTPUT_NODE = True
    CATEGORY = "Asset Factory/Production V2"

    @classmethod
    def INPUT_TYPES(cls) -> dict[str, Any]:
        return {
            "required": {
                "image": ("IMAGE",),
                "output_prefix": (
                    "STRING",
                    {
                        "multiline": False,
                        "default": "asset-factory/product-image-v2",
                        "dynamicPrompts": False,
                    },
                ),
                "sub2api_request_id": (
                    "STRING",
                    {"multiline": False, "default": "", "dynamicPrompts": False},
                ),
                "model": (
                    "STRING",
                    {"multiline": False, "default": "", "dynamicPrompts": False},
                ),
                "duration_ms": ("INT", {"default": 0, "min": 0, "max": 86_400_000}),
                "usage_json": (
                    "STRING",
                    {"multiline": False, "default": "{}", "dynamicPrompts": False},
                ),
            },
            "optional": {
                "quality_json": (
                    "STRING",
                    {"multiline": False, "default": "{}", "dynamicPrompts": False},
                ),
            },
        }

    def save(
        self,
        image: Any,
        output_prefix: str,
        sub2api_request_id: str,
        model: str,
        duration_ms: int,
        usage_json: str,
        quality_json: str = "{}",
    ) -> dict[str, Any]:
        content = _tensor_png(image)
        filename, subfolder = _store_output(content, output_prefix, ".png")
        return {
            "ui": {
                "images": [
                    {
                        "filename": filename,
                        "subfolder": subfolder,
                        "type": "output",
                        "mime": "image/png",
                    }
                ],
                "asset_factory": [
                    {
                        "sub2api_request_id": str(sub2api_request_id or "")[:190],
                        "model": str(model or "")[:190],
                        "duration_ms": max(0, int(duration_ms)),
                        "usage": _safe_usage_json(usage_json),
                        "quality": _safe_json_object(quality_json),
                    }
                ],
            }
        }


class AssetFactoryDeterministicTextLayout:
    """Render approved copy with measured typography inside an adaptive safe area."""

    RETURN_TYPES = ("IMAGE", "STRING")
    RETURN_NAMES = ("image", "quality_json")
    FUNCTION = "compose"
    CATEGORY = "Asset Factory/Production V2"
    DESCRIPTION = (
        "Renders ThinkPHP-owned text slots deterministically. It never invents, "
        "rewrites, translates, or asks an image model to draw the approved copy."
    )

    @classmethod
    def INPUT_TYPES(cls) -> dict[str, Any]:
        return {
            "required": {
                "image": ("IMAGE",),
                "layout_json": (
                    "STRING",
                    {"multiline": True, "default": "{}", "dynamicPrompts": False},
                ),
                "canvas_width": ("INT", {"default": 1024, "min": 320, "max": 4096}),
                "canvas_height": ("INT", {"default": 1024, "min": 320, "max": 4096}),
                "safe_area_percent": (
                    "INT",
                    {"default": 8, "min": 5, "max": 20},
                ),
            }
        }

    def compose(
        self,
        image: Any,
        layout_json: str,
        canvas_width: int,
        canvas_height: int,
        safe_area_percent: int,
    ) -> tuple[Any, str]:
        layout = _decode_text_layout(layout_json)
        content = _tensor_png(image)
        import io

        with Image.open(io.BytesIO(content)) as source:
            canvas = source.convert("RGB").resize(
                (int(canvas_width), int(canvas_height)),
                Image.Resampling.LANCZOS,
            )
        headline = str(layout.get("headline") or "").strip()
        body = str(layout.get("body") or "").strip()
        plan = layout.get("layout_plan")
        plan = plan if isinstance(plan, dict) else {}
        safe_area_percent = max(
            5,
            min(20, int(plan.get("safe_area_percent") or safe_area_percent)),
        )
        if not headline and not body:
            quality = {
                "status": "skipped",
                "reason": "no_approved_text_slots",
                "slot_count": 0,
                "safe_area_ok": True,
                "overflow": False,
                "text_render_mode": "deterministic",
            }
            return _pil_tensor(canvas), json.dumps(
                quality, ensure_ascii=False, separators=(",", ":")
            )

        regular_font_path, bold_font_path = _cjk_font_paths()
        safe = max(
            20,
            round(min(canvas.width, canvas.height) * int(safe_area_percent) / 100),
        )
        anchor = str(plan.get("anchor") or layout.get("position") or "bottom_left").lower()
        if anchor == "top":
            anchor = "top_left"
        elif anchor == "bottom":
            anchor = "bottom_left"
        if anchor not in {
            "top_left",
            "top_center",
            "top_right",
            "bottom_left",
            "bottom_center",
            "bottom_right",
        }:
            anchor = "bottom_left"
        alignment = str(plan.get("alignment") or "").lower()
        if alignment not in {"left", "center", "right"}:
            alignment = (
                "right"
                if anchor.endswith("right")
                else ("center" if anchor.endswith("center") else "left")
            )
        max_width_percent = max(
            20, min(80, int(plan.get("max_width_percent") or 50))
        )
        max_height_percent = max(
            12, min(60, int(plan.get("max_height_percent") or 34))
        )
        maximum_width = min(
            canvas.width - safe * 2,
            round(canvas.width * max_width_percent / 100),
        )
        maximum_height = min(
            canvas.height - safe * 2,
            round(canvas.height * max_height_percent / 100),
        )
        draw = ImageDraw.Draw(canvas, "RGBA")
        colors = layout.get("colors")
        colors = colors if isinstance(colors, dict) else {}
        background = _layout_color(
            colors.get("panel") or layout.get("panel_color"), "#F8FAFC"
        )
        foreground = _layout_color(
            colors.get("text") or layout.get("text_color"), "#0F172A"
        )
        accent = _layout_color(
            colors.get("accent") or layout.get("accent_color"), "#14B8A6"
        )
        padding = max(14, round(min(canvas.width, canvas.height) * 0.022))
        available_width = max(80, maximum_width - padding * 2)
        available_height = max(80, maximum_height - padding * 2)
        typography = plan.get("typography")
        typography = typography if isinstance(typography, dict) else {
            "headline": plan.get("headline", {}),
            "body": plan.get("body", {}),
        }
        fitted = _fit_text_layout(
            draw,
            headline,
            body,
            bold_font_path,
            regular_font_path,
            available_width,
            available_height,
            typography,
        )
        content_width = max(1, int(fitted["content_width"]))
        content_height = max(1, int(fitted["content_height"]))
        panel_width = min(maximum_width, content_width + padding * 2)
        panel_height = min(maximum_height, content_height + padding * 2)
        minimum_panel_width = min(maximum_width, round(canvas.width * 0.24))
        panel_width = max(panel_width, minimum_panel_width)
        anchor_x = (
            safe
            if anchor.endswith("left")
            else (
                canvas.width - safe - panel_width
                if anchor.endswith("right")
                else round((canvas.width - panel_width) / 2)
            )
        )
        anchor_y = (
            safe
            if anchor.startswith("top")
            else canvas.height - safe - panel_height
        )
        panel_box = (
            int(anchor_x),
            int(anchor_y),
            int(anchor_x + panel_width),
            int(anchor_y + panel_height),
        )
        backplate = plan.get("backplate") or plan.get("panel")
        backplate = backplate if isinstance(backplate, dict) else {}
        raw_opacity = backplate.get("opacity", 232)
        try:
            opacity_number = float(raw_opacity)
        except (TypeError, ValueError):
            opacity_number = 232
        opacity = max(
            0,
            min(255, round(opacity_number * 255 if opacity_number <= 1 else opacity_number)),
        )
        draw.rounded_rectangle(
            panel_box,
            radius=max(14, round(min(canvas.width, canvas.height) * 0.018)),
            fill=(*background, opacity),
            outline=(*accent, 210),
            width=max(2, round(canvas.width / 512)),
        )
        cursor_y = panel_box[1] + padding
        rendered_boxes: list[dict[str, Any]] = []
        for role in ("headline", "body"):
            lines = fitted[f"{role}_lines"]
            font = fitted[f"{role}_font"]
            line_height = int(fitted[f"{role}_line_height"])
            if role == "body" and lines and fitted["headline_lines"]:
                cursor_y += int(fitted["gap"])
            for line in lines:
                line_width = float(draw.textlength(line, font=font))
                text_x = (
                    panel_box[0] + padding
                    if alignment == "left"
                    else (
                        panel_box[2] - padding - line_width
                        if alignment == "right"
                        else panel_box[0] + (panel_width - line_width) / 2
                    )
                )
                text_box = draw.textbbox((text_x, cursor_y), line, font=font)
                draw.text(
                    (text_x, cursor_y),
                    line,
                    font=font,
                    fill=(*foreground, 255 if role == "headline" else 238),
                )
                rendered_boxes.append(
                    {
                        "role": role,
                        "text": line,
                        "bbox": [int(value) for value in text_box],
                    }
                )
                cursor_y += line_height

        safe_box = (safe, safe, canvas.width - safe, canvas.height - safe)
        boxes_inside_safe_area = all(
            box["bbox"][0] >= safe_box[0]
            and box["bbox"][1] >= safe_box[1]
            and box["bbox"][2] <= safe_box[2]
            and box["bbox"][3] <= safe_box[3]
            for box in rendered_boxes
        )
        panel_inside_safe_area = (
            panel_box[0] >= safe_box[0]
            and panel_box[1] >= safe_box[1]
            and panel_box[2] <= safe_box[2]
            and panel_box[3] <= safe_box[3]
        )
        overflow = not boxes_inside_safe_area or not panel_inside_safe_area
        if overflow:
            raise AssetFactoryNodeError("Measured text layout exceeded its safe area.")
        approved = "\n".join(filter(None, [headline, body]))
        approved_hash = hashlib.sha256(approved.encode("utf-8")).hexdigest()
        quality = {
            "status": "passed",
            "verification_mode": "deterministic_source_match",
            "approved_text_sha256": approved_hash,
            "rendered_text_sha256": approved_hash,
            "slot_count": int(bool(headline)) + int(bool(body)),
            "safe_area_ok": boxes_inside_safe_area and panel_inside_safe_area,
            "safe_area_percent": int(safe_area_percent),
            "overflow": overflow,
            "canvas": {"width": canvas.width, "height": canvas.height},
            "anchor": anchor,
            "alignment": alignment,
            "panel_bbox": list(panel_box),
            "text_bboxes": rendered_boxes,
            "headline_font_size": int(fitted["headline_size"]),
            "body_font_size": int(fitted["body_size"]),
            "headline_line_count": len(fitted["headline_lines"]),
            "body_line_count": len(fitted["body_lines"]),
            "product_avoidance": (
                "planned_reserved_zone"
                if bool(plan.get("product_avoidance", False))
                else "not_requested"
            ),
        }
        return _pil_tensor(canvas), json.dumps(
            quality, ensure_ascii=False, separators=(",", ":")
        )


class AssetFactorySub2ApiVideo:
    """Submit one asynchronous video request through the ComfyUI service key."""

    RETURN_TYPES = ("STRING", "STRING", "STRING", "INT", "STRING")
    RETURN_NAMES = (
        "video_url",
        "sub2api_request_id",
        "model",
        "duration_ms",
        "usage_json",
    )
    FUNCTION = "generate"
    CATEGORY = "Asset Factory/Production V2"

    @classmethod
    def INPUT_TYPES(cls) -> dict[str, Any]:
        return {
            "required": {
                "prompt": (
                    "STRING",
                    {"multiline": True, "default": "", "dynamicPrompts": False},
                ),
                "negative_prompt": (
                    "STRING",
                    {"multiline": True, "default": "", "dynamicPrompts": False},
                ),
                "reference_image_url": (
                    "STRING",
                    {"multiline": False, "default": "", "dynamicPrompts": False},
                ),
                "sub2api_model": (
                    "STRING",
                    {"multiline": False, "default": "", "dynamicPrompts": False},
                ),
                "ratio": (["1:1", "16:9", "9:16", "4:3", "3:4"],),
                "resolution": (["720p", "1080p"],),
                "duration_seconds": ("INT", {"default": 5, "min": 5, "max": 10}),
                "generate_audio": ("BOOLEAN", {"default": False}),
                "job_no": (
                    "STRING",
                    {"multiline": False, "default": "", "dynamicPrompts": False},
                ),
                "item_id": (
                    "INT",
                    {"default": 0, "min": 0, "max": 9_223_372_036_854_775_807},
                ),
                "workflow_code": (
                    "STRING",
                    {"multiline": False, "default": "", "dynamicPrompts": False},
                ),
                "workflow_version": ("INT", {"default": 1, "min": 1, "max": 1_000_000}),
                "idempotency_key": (
                    "STRING",
                    {"multiline": False, "default": "", "dynamicPrompts": False},
                ),
            }
        }

    def generate(
        self,
        prompt: str,
        negative_prompt: str,
        reference_image_url: str,
        sub2api_model: str,
        ratio: str,
        resolution: str,
        duration_seconds: int,
        generate_audio: bool,
        job_no: str,
        item_id: int,
        workflow_code: str,
        workflow_version: int,
        idempotency_key: str,
    ) -> tuple[str, str, str, int, str]:
        prompt = str(prompt or "").strip()
        model = str(sub2api_model or "").strip()
        if not prompt or not model:
            raise AssetFactoryNodeError("Sub2API video prompt and model are required.")
        negative_prompt = str(negative_prompt or "").strip()
        if negative_prompt:
            prompt += "\n\nNegative constraints:\n" + negative_prompt
        reference_image_url = str(reference_image_url or "").strip()
        if reference_image_url:
            parsed = urlparse(reference_image_url)
            if parsed.scheme != "https" or not parsed.hostname:
                raise AssetFactoryNodeError("Video reference must use a public HTTPS URL.")
            _reject_private_host(parsed.hostname)
        request_identity = _paid_request_identity(
            idempotency_key,
            "video",
            job_no,
            item_id,
            workflow_code,
            workflow_version,
        )
        state = _begin_paid_dispatch(request_identity, "video")
        started = time.monotonic()
        if state is None:
            submitted = _request_sub2api_json(
                "POST",
                "/v1/videos/generations",
                {
                    "model": model,
                    "prompt": prompt,
                    "image": [reference_image_url] if reference_image_url else [],
                    "ratio": ratio,
                    "resolution": resolution,
                    "duration": max(5, min(10, int(duration_seconds))),
                    "generate_audio": bool(generate_audio),
                },
                request_identity,
                180,
            )
            task_id = str(
                submitted.get("id") or submitted.get("request_id") or ""
            ).strip()
            if not task_id:
                raise AssetFactoryNodeError("Sub2API video request returned no task ID.")
            _update_paid_dispatch(
                request_identity,
                {"status": "accepted", "request_id": task_id, "model": model},
            )
        else:
            if str(state.get("status")) == "completed" and state.get("video_url"):
                return (
                    str(state["video_url"]),
                    str(state.get("request_id") or request_identity),
                    str(state.get("model") or model),
                    int(state.get("duration_ms") or 0),
                    json.dumps(state.get("usage") or {}, separators=(",", ":")),
                )
            if str(state.get("status")) != "accepted" or not state.get("request_id"):
                raise AssetFactoryNodeError(
                    "The paid video dispatch has an uncertain prior state and will not be submitted again."
                )
            task_id = str(state["request_id"])

        timeout = max(
            60,
            min(1800, int(os.environ.get("ASSET_FACTORY_VIDEO_TIMEOUT_SECONDS", "1200"))),
        )
        deadline = time.monotonic() + timeout
        result: dict[str, Any] = {}
        while time.monotonic() < deadline:
            result = _request_sub2api_json(
                "GET",
                "/v1/videos/" + urllib.parse.quote(task_id, safe=""),
                None,
                request_identity,
                90,
            )
            provider_status = str(
                result.get("status")
                or (result.get("data") or {}).get("status")
                or ""
            ).lower()
            if provider_status in {"completed", "succeeded", "success"}:
                break
            if provider_status in {
                "failed",
                "error",
                "cancelled",
                "canceled",
                "expired",
                "result_missing",
            }:
                raise AssetFactoryNodeError(
                    _response_error(result) or "Sub2API video generation failed."
                )
            time.sleep(3)
        else:
            raise AssetFactoryNodeError(
                f"Sub2API video workflow exceeded the {timeout}-second time limit."
            )
        video_url = _response_video_url(result)
        duration_ms = round((time.monotonic() - started) * 1000)
        usage = _safe_usage(result.get("usage"))
        _update_paid_dispatch(
            request_identity,
            {
                "status": "completed",
                "request_id": task_id,
                "model": model,
                "duration_ms": duration_ms,
                "usage": usage,
                "video_url": video_url,
            },
        )
        return (
            video_url,
            task_id,
            model,
            duration_ms,
            json.dumps(usage, separators=(",", ":")),
        )


class AssetFactorySaveVideo:
    """Download and save the provider video after its async task completes."""

    RETURN_TYPES: tuple[str, ...] = ()
    FUNCTION = "save"
    OUTPUT_NODE = True
    CATEGORY = "Asset Factory/Production V2"

    @classmethod
    def INPUT_TYPES(cls) -> dict[str, Any]:
        return {
            "required": {
                "video_url": (
                    "STRING",
                    {"multiline": False, "default": "", "dynamicPrompts": False},
                ),
                "output_prefix": (
                    "STRING",
                    {
                        "multiline": False,
                        "default": "asset-factory/product-video-v2",
                        "dynamicPrompts": False,
                    },
                ),
                "sub2api_request_id": (
                    "STRING",
                    {"multiline": False, "default": "", "dynamicPrompts": False},
                ),
                "model": (
                    "STRING",
                    {"multiline": False, "default": "", "dynamicPrompts": False},
                ),
                "duration_ms": ("INT", {"default": 0, "min": 0, "max": 86_400_000}),
                "usage_json": (
                    "STRING",
                    {"multiline": False, "default": "{}", "dynamicPrompts": False},
                ),
            }
        }

    def save(
        self,
        video_url: str,
        output_prefix: str,
        sub2api_request_id: str,
        model: str,
        duration_ms: int,
        usage_json: str,
    ) -> dict[str, Any]:
        content, extension, mime = _download_public_video(str(video_url or "").strip())
        filename, subfolder = _store_output(content, output_prefix, extension)
        return {
            "ui": {
                "videos": [
                    {
                        "filename": filename,
                        "subfolder": subfolder,
                        "type": "output",
                        "mime": mime,
                    }
                ],
                "asset_factory": [
                    {
                        "sub2api_request_id": str(sub2api_request_id or "")[:190],
                        "model": str(model or "")[:190],
                        "duration_ms": max(0, int(duration_ms)),
                        "usage": _safe_usage_json(usage_json),
                    }
                ],
            }
        }


class AssetFactoryRoutedImage:
    """Generate one image through the ThinkPHP-controlled account pool."""

    RETURN_TYPES: tuple[str, ...] = ()
    FUNCTION = "generate"
    OUTPUT_NODE = True
    CATEGORY = "Asset Factory/Production"
    DESCRIPTION = (
        "Controlled image workflow. Model accounts, routing and billing are "
        "managed by the ThinkPHP control plane."
    )

    @classmethod
    def INPUT_TYPES(cls) -> dict[str, Any]:
        return {
            "required": {
                "prompt": (
                    "STRING",
                    {
                        "multiline": True,
                        "default": "",
                        "dynamicPrompts": False,
                    },
                ),
                "reference_images_json": (
                    "STRING",
                    {
                        "multiline": False,
                        "default": "[]",
                        "dynamicPrompts": False,
                    },
                ),
                "model": (
                    "STRING",
                    {
                        "multiline": False,
                        "default": "",
                        "dynamicPrompts": False,
                    },
                ),
                "model_id": (
                    "INT",
                    {
                        "default": 0,
                        "min": 0,
                        "max": 2_147_483_647,
                    },
                ),
                "size": (sorted(SUPPORTED_SIZES),),
                "seed": (
                    "INT",
                    {
                        "default": 1,
                        "min": 1,
                        "max": MAX_SEED,
                    },
                ),
                "output_prefix": (
                    "STRING",
                    {
                        "multiline": False,
                        "default": "asset-factory/product-image",
                        "dynamicPrompts": False,
                    },
                ),
                "job_id": (
                    "INT",
                    {
                        "default": 0,
                        "min": 0,
                        "max": 9_223_372_036_854_775_807,
                    },
                ),
                "generation_item_id": (
                    "INT",
                    {
                        "default": 0,
                        "min": 0,
                        "max": 9_223_372_036_854_775_807,
                    },
                ),
                "callback_token": (
                    "STRING",
                    {
                        "multiline": False,
                        "default": "",
                        "dynamicPrompts": False,
                    },
                ),
            }
        }

    def generate(
        self,
        prompt: str,
        reference_images_json: str,
        model: str,
        model_id: int,
        size: str,
        seed: int,
        output_prefix: str,
        job_id: int,
        generation_item_id: int,
        callback_token: str,
    ) -> dict[str, Any]:
        prompt = str(prompt or "").strip()
        if not prompt:
            raise AssetFactoryNodeError("Product-image prompt is required.")
        if int(model_id) <= 0:
            raise AssetFactoryNodeError("The routed image model is missing.")
        if not str(callback_token or "").strip():
            raise AssetFactoryNodeError("The workflow callback token is missing.")
        if size not in SUPPORTED_SIZES:
            raise AssetFactoryNodeError("The requested image size is not supported.")

        references = _decode_references(reference_images_json)
        uploads = [_read_reference(name) for name in references]
        response = _request_control_plane(
            prompt=prompt,
            uploads=uploads,
            model=model,
            model_id=int(model_id),
            size=size,
            seed=max(1, min(MAX_SEED, int(seed))),
            job_id=max(0, int(job_id)),
            generation_item_id=max(0, int(generation_item_id)),
            callback_token=str(callback_token).strip(),
        )
        content = _response_image(response)
        image_format = _verify_image(content)
        mime, extension = ALLOWED_IMAGE_FORMATS[image_format]
        filename, subfolder = _store_output(
            content,
            output_prefix=output_prefix,
            extension=extension,
        )

        return {
            "ui": {
                "images": [
                    {
                        "filename": filename,
                        "subfolder": subfolder,
                        "type": "output",
                        "mime": mime,
                    }
                ]
            }
        }


class AssetFactoryRoutedVideo:
    """Run a video workflow while ThinkPHP owns routing and provider accounts."""

    RETURN_TYPES: tuple[str, ...] = ()
    FUNCTION = "generate"
    OUTPUT_NODE = True
    CATEGORY = "Asset Factory/Production"
    DESCRIPTION = (
        "Controlled video workflow. ComfyUI orchestrates the workflow while "
        "ThinkPHP selects and bills the provider account."
    )

    @classmethod
    def INPUT_TYPES(cls) -> dict[str, Any]:
        return {
            "required": {
                "prompt": (
                    "STRING",
                    {"multiline": True, "default": "", "dynamicPrompts": False},
                ),
                "reference_image_url": (
                    "STRING",
                    {"multiline": False, "default": "", "dynamicPrompts": False},
                ),
                "model": (
                    "STRING",
                    {"multiline": False, "default": "", "dynamicPrompts": False},
                ),
                "model_id": (
                    "INT",
                    {"default": 0, "min": 0, "max": 2_147_483_647},
                ),
                "ratio": (["1:1", "16:9", "9:16", "4:3", "3:4"],),
                "resolution": (["720p", "1080p"],),
                "duration_seconds": (
                    "INT",
                    {"default": 5, "min": 5, "max": 10},
                ),
                "generate_audio": ("BOOLEAN", {"default": False}),
                "output_prefix": (
                    "STRING",
                    {
                        "multiline": False,
                        "default": "asset-factory/product-video",
                        "dynamicPrompts": False,
                    },
                ),
                "job_id": (
                    "INT",
                    {"default": 0, "min": 0, "max": 9_223_372_036_854_775_807},
                ),
                "callback_token": (
                    "STRING",
                    {"multiline": False, "default": "", "dynamicPrompts": False},
                ),
            }
        }

    def generate(
        self,
        prompt: str,
        reference_image_url: str,
        model: str,
        model_id: int,
        ratio: str,
        resolution: str,
        duration_seconds: int,
        generate_audio: bool,
        output_prefix: str,
        job_id: int,
        callback_token: str,
    ) -> dict[str, Any]:
        prompt = str(prompt or "").strip()
        reference_image_url = str(reference_image_url or "").strip()
        if not prompt:
            raise AssetFactoryNodeError("Video prompt is required.")
        if int(job_id) <= 0 or int(model_id) <= 0:
            raise AssetFactoryNodeError("The routed video job or model is missing.")
        if not str(callback_token or "").strip():
            raise AssetFactoryNodeError("The video workflow callback token is missing.")

        common = {
            "prompt": prompt,
            "reference_image_url": reference_image_url,
            "model": str(model or "").strip(),
            "model_id": str(int(model_id)),
            "ratio": ratio,
            "resolution": resolution,
            "duration_seconds": str(max(5, min(10, int(duration_seconds)))),
            "generate_audio": "1" if bool(generate_audio) else "0",
            "job_id": str(int(job_id)),
        }
        submitted = _request_video_control_plane(
            fields={"action": "submit", **common},
            callback_token=str(callback_token).strip(),
        )
        task_id = str(
            submitted.get("id") or submitted.get("request_id") or ""
        ).strip()
        if not task_id:
            raise AssetFactoryNodeError("Video provider returned no task ID.")

        timeout = max(
            60,
            min(
                1800,
                int(os.environ.get("ASSET_FACTORY_VIDEO_TIMEOUT_SECONDS", "1200")),
            ),
        )
        deadline = time.monotonic() + timeout
        status_payload: dict[str, Any] = {}
        while time.monotonic() < deadline:
            status_payload = _request_video_control_plane(
                fields={
                    "action": "status",
                    "provider_task_id": task_id,
                    **common,
                },
                callback_token=str(callback_token).strip(),
            )
            status = str(status_payload.get("status") or "").strip().lower()
            if status in {"succeeded", "completed", "success"}:
                break
            if status in {
                "failed",
                "error",
                "cancelled",
                "canceled",
                "expired",
                "result_missing",
            }:
                error = status_payload.get("error")
                message = (
                    str(error.get("message") or "").strip()
                    if isinstance(error, dict)
                    else ""
                )
                raise AssetFactoryNodeError(
                    message or "Video provider reported a failed task."
                )
            time.sleep(3)
        else:
            raise AssetFactoryNodeError(
                f"Video workflow exceeded the {timeout}-second time limit."
            )

        video_url = _response_video_url(status_payload)
        content, extension, mime = _download_public_video(video_url)
        filename, subfolder = _store_output(
            content,
            output_prefix=output_prefix,
            extension=extension,
        )
        return {
            "ui": {
                "videos": [
                    {
                        "filename": filename,
                        "subfolder": subfolder,
                        "type": "output",
                        "mime": mime,
                    }
                ]
            }
        }

def _decode_references(value: str) -> list[str]:
    try:
        decoded = json.loads(str(value or "[]"))
    except json.JSONDecodeError as exception:
        raise AssetFactoryNodeError("Reference image list is not valid JSON.") from exception
    if not isinstance(decoded, list):
        raise AssetFactoryNodeError("Reference image list must be an array.")
    references = [str(item).strip() for item in decoded if str(item).strip()]
    if len(references) > MAX_REFERENCE_COUNT:
        raise AssetFactoryNodeError(
            f"No more than {MAX_REFERENCE_COUNT} reference images are allowed."
        )
    return references


def _read_reference(name: str) -> dict[str, Any]:
    input_root = Path(folder_paths.get_input_directory()).resolve()
    relative = Path(name.replace("\\", "/"))
    if relative.is_absolute() or ".." in relative.parts:
        raise AssetFactoryNodeError("Reference image path is invalid.")
    path = (input_root / relative).resolve()
    if input_root not in path.parents or not path.is_file():
        raise AssetFactoryNodeError("Reference image does not exist in ComfyUI input.")
    content = path.read_bytes()
    if not content:
        raise AssetFactoryNodeError("Reference image is empty.")
    if len(content) > MAX_REFERENCE_BYTES:
        raise AssetFactoryNodeError("Reference image exceeds the allowed size.")
    image_format = _verify_image(content)
    mime, _extension = ALLOWED_IMAGE_FORMATS[image_format]
    return {
        "name": path.name,
        "mime": mime,
        "content": content,
    }


def _request_control_plane(
    *,
    prompt: str,
    uploads: list[dict[str, Any]],
    model: str,
    model_id: int,
    size: str,
    seed: int,
    job_id: int,
    generation_item_id: int,
    callback_token: str,
) -> dict[str, Any]:
    base_url = os.environ.get(
        "ASSET_FACTORY_CONTROL_PLANE_URL", "http://127.0.0.1"
    ).strip()
    endpoint = _loopback_endpoint(base_url, "/internal/comfyui/images")
    fields = {
        "model": str(model or ""),
        "model_id": str(model_id),
        "prompt": prompt,
        "size": size,
        "seed": str(seed),
        "job_id": str(job_id),
        "generation_item_id": str(generation_item_id),
    }
    body, content_type = _multipart(fields, uploads)
    headers = {
        "Authorization": "Bearer " + callback_token,
        "Content-Type": content_type,
        "Accept": "application/json",
    }
    host = os.environ.get("ASSET_FACTORY_CONTROL_PLANE_HOST", "").strip()
    if host:
        if any(character in host for character in "\r\n/"):
            raise AssetFactoryNodeError("ThinkPHP control-plane host is invalid.")
        headers["Host"] = host
    request = urllib.request.Request(
        endpoint,
        data=body,
        headers=headers,
        method="POST",
    )
    try:
        with urllib.request.urlopen(request, timeout=480) as response:
            payload = json.loads(response.read(MAX_RESPONSE_BYTES))
    except urllib.error.HTTPError as exception:
        raw = exception.read(8192)
        message = _upstream_error(raw) or f"HTTP {exception.code}"
        raise AssetFactoryNodeError(
            "ThinkPHP control plane rejected the workflow request: " + message
        ) from exception
    except (urllib.error.URLError, TimeoutError, socket.timeout) as exception:
        raise AssetFactoryNodeError(
            "ThinkPHP control plane is temporarily unavailable."
        ) from exception
    except json.JSONDecodeError as exception:
        raise AssetFactoryNodeError(
            "ThinkPHP control plane returned an invalid response."
        ) from exception
    if not isinstance(payload, dict):
        raise AssetFactoryNodeError("ThinkPHP control plane returned no result.")
    return payload


def _request_video_control_plane(
    *, fields: dict[str, str], callback_token: str
) -> dict[str, Any]:
    base_url = os.environ.get(
        "ASSET_FACTORY_CONTROL_PLANE_URL", "http://127.0.0.1"
    ).strip()
    endpoint = _loopback_endpoint(base_url, "/internal/comfyui/videos")
    encoded = urllib.parse.urlencode(fields).encode("utf-8")
    headers = {
        "Authorization": "Bearer " + callback_token,
        "Content-Type": "application/x-www-form-urlencoded",
        "Accept": "application/json",
    }
    host = os.environ.get("ASSET_FACTORY_CONTROL_PLANE_HOST", "").strip()
    if host:
        if any(character in host for character in "\r\n/"):
            raise AssetFactoryNodeError("ThinkPHP control-plane host is invalid.")
        headers["Host"] = host
    request = urllib.request.Request(
        endpoint,
        data=encoded,
        headers=headers,
        method="POST",
    )
    try:
        with urllib.request.urlopen(request, timeout=120) as response:
            payload = json.loads(response.read(1024 * 1024))
    except urllib.error.HTTPError as exception:
        raw = exception.read(8192)
        message = _upstream_error(raw) or f"HTTP {exception.code}"
        raise AssetFactoryNodeError(
            "ThinkPHP control plane rejected the video workflow request: " + message
        ) from exception
    except (urllib.error.URLError, TimeoutError, socket.timeout) as exception:
        raise AssetFactoryNodeError(
            "ThinkPHP video control plane is temporarily unavailable."
        ) from exception
    except json.JSONDecodeError as exception:
        raise AssetFactoryNodeError(
            "ThinkPHP video control plane returned an invalid response."
        ) from exception
    if not isinstance(payload, dict):
        raise AssetFactoryNodeError(
            "ThinkPHP video control plane returned no result."
        )
    return payload


def _loopback_endpoint(base_url: str, path: str) -> str:
    parsed = urlparse(base_url.rstrip("/"))
    if parsed.scheme != "http" or parsed.hostname not in {
        "127.0.0.1",
        "::1",
        "localhost",
    }:
        raise AssetFactoryNodeError(
            "ThinkPHP control plane must use a loopback HTTP address."
        )
    if parsed.username or parsed.password or parsed.query or parsed.fragment:
        raise AssetFactoryNodeError("ThinkPHP control-plane address is invalid.")
    return base_url.rstrip("/") + path


# Keep the historical class name loadable so old workflow metadata produces a
# clear missing-input error instead of breaking ComfyUI startup.
AssetFactorySeedreamProductImage = AssetFactoryRoutedImage


def _sub2api_configuration() -> tuple[str, str]:
    """Load the isolated ComfyUI credential without exposing it to workflows."""
    values: dict[str, str] = {}
    explicit_file = os.environ.get(
        "ASSET_FACTORY_SUB2API_CREDENTIAL_FILE", ""
    ).strip()
    credentials_directory = os.environ.get("CREDENTIALS_DIRECTORY", "").strip()
    credential_file = (
        Path(explicit_file)
        if explicit_file
        else Path(credentials_directory) / "sub2api.env"
        if credentials_directory
        else None
    )
    if credential_file is not None:
        try:
            content = credential_file.read_text(encoding="utf-8")
        except OSError as exception:
            raise AssetFactoryNodeError(
                "The ComfyUI Sub2API credential could not be read."
            ) from exception
        for raw_line in content.splitlines():
            line = raw_line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            name, value = line.split("=", 1)
            value = value.strip()
            if (
                len(value) >= 2
                and value[0] == value[-1]
                and value[0] in {"'", '"'}
            ):
                value = value[1:-1]
            values[name.strip()] = value

    base_url = os.environ.get(
        "ASSET_FACTORY_SUB2API_BASE_URL",
        values.get("SUB2API_BASE_URL", ""),
    ).strip()
    service_key = os.environ.get(
        "ASSET_FACTORY_SUB2API_SERVICE_KEY",
        values.get("SUB2API_SERVICE_KEY", ""),
    ).strip()
    if not base_url or not service_key:
        raise AssetFactoryNodeError(
            "The ComfyUI Sub2API service credential is not configured."
        )
    _sub2api_endpoint(base_url, "/v1/models")
    return base_url.rstrip("/"), service_key


def _sub2api_endpoint(base_url: str, path: str) -> str:
    parsed = urlparse(str(base_url or "").rstrip("/"))
    if parsed.scheme != "http" or parsed.hostname not in {
        "127.0.0.1",
        "::1",
        "localhost",
    }:
        raise AssetFactoryNodeError(
            "ComfyUI may only call Sub2API through a loopback HTTP address."
        )
    if parsed.username or parsed.password or parsed.query or parsed.fragment:
        raise AssetFactoryNodeError("The ComfyUI Sub2API address is invalid.")
    if not str(path).startswith("/") or any(character in path for character in "\r\n"):
        raise AssetFactoryNodeError("The Sub2API request path is invalid.")
    return str(base_url).rstrip("/") + str(path)


def _paid_request_identity(
    provided: str,
    kind: str,
    *parts: Any,
) -> str:
    provided = str(provided or "").strip()
    material = provided or "|".join(
        [str(kind or "").strip()] + [str(part or "").strip() for part in parts]
    )
    if not material or material.replace("|", "") == "":
        raise AssetFactoryNodeError(
            "A task-scoped idempotency identity is required for paid generation."
        )
    digest = hashlib.sha256(
        ("asset-factory-comfyui-v2|" + str(kind) + "|" + material).encode("utf-8")
    ).hexdigest()
    return "af-comfy-" + digest


def _provider_state_dir() -> Path:
    configured = os.environ.get(
        "ASSET_FACTORY_SUB2API_STATE_DIR",
        "/var/lib/asset-factory/comfyui/provider-state",
    ).strip()
    if not configured:
        raise AssetFactoryNodeError("The paid-dispatch state directory is missing.")
    root = Path(configured)
    try:
        root.mkdir(mode=0o700, parents=True, exist_ok=True)
        root.chmod(0o700)
        return root.resolve()
    except OSError as exception:
        raise AssetFactoryNodeError(
            "The paid-dispatch state directory is unavailable."
        ) from exception


def _dispatch_paths(request_identity: str) -> tuple[Path, Path]:
    if (
        not request_identity.startswith("af-comfy-")
        or len(request_identity) != len("af-comfy-") + 64
        or not all(
            character in "0123456789abcdef"
            for character in request_identity[len("af-comfy-") :]
        )
    ):
        raise AssetFactoryNodeError("The paid-dispatch identity is invalid.")
    root = _provider_state_dir()
    return (
        root / f"{request_identity}.json",
        root / f"{request_identity}.image",
    )


def _read_dispatch(request_identity: str) -> dict[str, Any] | None:
    state_path, _cache_path = _dispatch_paths(request_identity)
    try:
        raw = state_path.read_bytes()
    except FileNotFoundError:
        return None
    except OSError as exception:
        raise AssetFactoryNodeError(
            "The paid-dispatch state could not be read."
        ) from exception
    if len(raw) > 64 * 1024:
        raise AssetFactoryNodeError("The paid-dispatch state is invalid.")
    try:
        state = json.loads(raw)
    except (json.JSONDecodeError, UnicodeDecodeError) as exception:
        raise AssetFactoryNodeError("The paid-dispatch state is invalid.") from exception
    if not isinstance(state, dict) or state.get("request_identity") != request_identity:
        raise AssetFactoryNodeError("The paid-dispatch state is invalid.")
    return state


def _begin_paid_dispatch(
    request_identity: str,
    kind: str,
) -> dict[str, Any] | None:
    state_path, _cache_path = _dispatch_paths(request_identity)
    initial = {
        "request_identity": request_identity,
        "kind": str(kind),
        "status": "dispatched",
        "created_at": int(time.time()),
        "updated_at": int(time.time()),
    }
    try:
        descriptor = os.open(
            state_path,
            os.O_WRONLY | os.O_CREAT | os.O_EXCL,
            0o600,
        )
    except FileExistsError:
        prior = _read_dispatch(request_identity)
        if prior is None or prior.get("kind") != str(kind):
            raise AssetFactoryNodeError("The paid-dispatch state is inconsistent.")
        status = str(prior.get("status") or "")
        resumable = {"completed"} if kind == "image" else {"accepted", "completed"}
        if status not in resumable:
            raise AssetFactoryNodeError(
                "This paid request was already dispatched and will not be submitted again."
            )
        return prior
    except OSError as exception:
        raise AssetFactoryNodeError(
            "The paid-dispatch state could not be created."
        ) from exception
    try:
        with os.fdopen(descriptor, "w", encoding="utf-8") as handle:
            json.dump(initial, handle, separators=(",", ":"), sort_keys=True)
            handle.flush()
            os.fsync(handle.fileno())
    except Exception:
        state_path.unlink(missing_ok=True)
        raise
    return None


def _write_dispatch(request_identity: str, state: dict[str, Any]) -> None:
    state_path, _cache_path = _dispatch_paths(request_identity)
    payload = dict(state)
    payload["request_identity"] = request_identity
    payload["updated_at"] = int(time.time())
    temporary = state_path.with_suffix(
        state_path.suffix + "." + secrets.token_hex(8) + ".tmp"
    )
    try:
        descriptor = os.open(
            temporary,
            os.O_WRONLY | os.O_CREAT | os.O_EXCL,
            0o600,
        )
        with os.fdopen(descriptor, "w", encoding="utf-8") as handle:
            json.dump(payload, handle, separators=(",", ":"), sort_keys=True)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, state_path)
        state_path.chmod(0o600)
    except OSError as exception:
        temporary.unlink(missing_ok=True)
        raise AssetFactoryNodeError(
            "The paid-dispatch state could not be updated."
        ) from exception


def _update_paid_dispatch(
    request_identity: str,
    updates: dict[str, Any],
) -> dict[str, Any]:
    state = _read_dispatch(request_identity)
    if state is None:
        raise AssetFactoryNodeError("The paid-dispatch state is missing.")
    for name, value in updates.items():
        if name in {
            "status",
            "request_id",
            "model",
            "duration_ms",
            "usage",
            "video_url",
            "cache_file",
        }:
            state[name] = value
    _write_dispatch(request_identity, state)
    return state


def _complete_paid_dispatch(
    request_identity: str,
    kind: str,
    content: bytes,
    metadata: dict[str, Any],
) -> None:
    _verify_image(content)
    _state_path, cache_path = _dispatch_paths(request_identity)
    state = _read_dispatch(request_identity)
    if state is None or state.get("kind") != kind:
        raise AssetFactoryNodeError("The paid-dispatch state is inconsistent.")
    temporary = cache_path.with_suffix(
        cache_path.suffix + "." + secrets.token_hex(8) + ".tmp"
    )
    try:
        descriptor = os.open(
            temporary,
            os.O_WRONLY | os.O_CREAT | os.O_EXCL,
            0o600,
        )
        with os.fdopen(descriptor, "wb") as handle:
            handle.write(content)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, cache_path)
        cache_path.chmod(0o600)
    except OSError as exception:
        temporary.unlink(missing_ok=True)
        raise AssetFactoryNodeError(
            "The paid image result could not be cached safely."
        ) from exception
    state.update(metadata)
    state.update(
        {
            "status": "completed",
            "cache_file": cache_path.name,
            "content_sha256": hashlib.sha256(content).hexdigest(),
        }
    )
    _write_dispatch(request_identity, state)


def _cached_dispatch_content(
    state: dict[str, Any],
    request_identity: str,
) -> bytes:
    if state.get("status") != "completed":
        raise AssetFactoryNodeError(
            "This paid request has no completed cached image result."
        )
    _state_path, cache_path = _dispatch_paths(request_identity)
    if state.get("cache_file") != cache_path.name:
        raise AssetFactoryNodeError("The paid image cache reference is invalid.")
    try:
        content = cache_path.read_bytes()
    except OSError as exception:
        raise AssetFactoryNodeError("The paid image cache is unavailable.") from exception
    _verify_image(content)
    if hashlib.sha256(content).hexdigest() != str(state.get("content_sha256") or ""):
        raise AssetFactoryNodeError("The paid image cache failed integrity verification.")
    return content


def _request_sub2api_json(
    method: str,
    path: str,
    payload: dict[str, Any] | None,
    request_identity: str,
    timeout: int,
) -> dict[str, Any]:
    base_url, service_key = _sub2api_configuration()
    method = str(method).upper()
    data = (
        json.dumps(payload, separators=(",", ":")).encode("utf-8")
        if payload is not None
        else None
    )
    headers = {
        "Authorization": "Bearer " + service_key,
        "Accept": "application/json",
    }
    if data is not None:
        headers["Content-Type"] = "application/json"
    if method == "POST":
        headers["X-Request-ID"] = request_identity
        headers["Idempotency-Key"] = request_identity
    request = urllib.request.Request(
        _sub2api_endpoint(base_url, path),
        data=data,
        headers=headers,
        method=method,
    )
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            raw = response.read(MAX_RESPONSE_BYTES + 1)
            response_request_id = str(
                response.headers.get("X-Request-ID", "")
                or response.headers.get("Request-ID", "")
            ).strip()
    except urllib.error.HTTPError as exception:
        message = _upstream_error(exception.read(8192)) or f"HTTP {exception.code}"
        raise AssetFactoryNodeError(
            "Sub2API rejected the paid request; it will not be retried automatically: "
            + message
        ) from exception
    except (urllib.error.URLError, TimeoutError, socket.timeout) as exception:
        raise AssetFactoryNodeError(
            "The paid Sub2API request has an uncertain result and will not be submitted again."
        ) from exception
    if len(raw) > MAX_RESPONSE_BYTES:
        raise AssetFactoryNodeError("Sub2API returned an oversized response.")
    try:
        decoded = json.loads(raw)
    except (json.JSONDecodeError, UnicodeDecodeError) as exception:
        raise AssetFactoryNodeError("Sub2API returned an invalid response.") from exception
    if not isinstance(decoded, dict):
        raise AssetFactoryNodeError("Sub2API returned no usable result.")
    if response_request_id:
        decoded["_asset_factory_request_id"] = response_request_id[:190]
    return decoded


def _sub2api_multipart(
    fields: dict[str, str],
    uploads: list[tuple[str, dict[str, Any]]],
) -> tuple[bytes, str]:
    boundary = "----AssetFactorySub2API" + secrets.token_hex(16)
    body = bytearray()
    for name, value in fields.items():
        body.extend(f"--{boundary}\r\n".encode())
        body.extend(
            f'Content-Disposition: form-data; name="{name}"\r\n\r\n'.encode()
        )
        body.extend(str(value).encode("utf-8"))
        body.extend(b"\r\n")
    for field_name, upload in uploads:
        content = upload.get("content")
        if not isinstance(content, bytes):
            raise AssetFactoryNodeError("Reference image data is invalid.")
        body.extend(f"--{boundary}\r\n".encode())
        body.extend(
            (
                'Content-Disposition: form-data; name="'
                + field_name
                + '"; filename="'
                + _header_filename(str(upload.get("name") or "reference.png"))
                + '"\r\n'
            ).encode("utf-8")
        )
        body.extend(
            f'Content-Type: {str(upload.get("mime") or "application/octet-stream")}\r\n\r\n'.encode()
        )
        body.extend(content)
        body.extend(b"\r\n")
    body.extend(f"--{boundary}--\r\n".encode())
    if len(body) > MAX_REFERENCE_BYTES:
        raise AssetFactoryNodeError("Combined Sub2API image inputs are too large.")
    return bytes(body), "multipart/form-data; boundary=" + boundary


def _request_sub2api_image(
    *,
    prompt: str,
    references: list[dict[str, Any]],
    mask: dict[str, Any] | None,
    model: str,
    size: str,
    seed: int,
    request_identity: str,
) -> dict[str, Any]:
    del seed  # The upstream OpenAI image contract does not accept a seed field.
    if not references and mask is None:
        return _request_sub2api_json(
            "POST",
            "/v1/images/generations",
            {
                "model": model,
                "prompt": prompt,
                "n": 1,
                "size": size,
                "response_format": "b64_json",
            },
            request_identity,
            480,
        )
    if mask is not None and not references:
        raise AssetFactoryNodeError("An image-edit mask requires a reference image.")
    fields = {
        "model": model,
        "prompt": prompt,
        "n": "1",
        "size": size,
        "response_format": "b64_json",
    }
    uploads: list[tuple[str, dict[str, Any]]] = []
    for index, reference in enumerate(references):
        uploads.append(("image" if index == 0 else "image[]", reference))
    if mask is not None:
        uploads.append(("mask", mask))
    body, content_type = _sub2api_multipart(fields, uploads)
    base_url, service_key = _sub2api_configuration()
    request = urllib.request.Request(
        _sub2api_endpoint(base_url, "/v1/images/edits"),
        data=body,
        headers={
            "Authorization": "Bearer " + service_key,
            "Accept": "application/json",
            "Content-Type": content_type,
            "X-Request-ID": request_identity,
            "Idempotency-Key": request_identity,
        },
        method="POST",
    )
    try:
        with urllib.request.urlopen(request, timeout=480) as response:
            raw = response.read(MAX_RESPONSE_BYTES + 1)
            response_request_id = str(
                response.headers.get("X-Request-ID", "")
                or response.headers.get("Request-ID", "")
            ).strip()
    except urllib.error.HTTPError as exception:
        message = _upstream_error(exception.read(8192)) or f"HTTP {exception.code}"
        raise AssetFactoryNodeError(
            "Sub2API rejected the paid image edit; it will not be retried automatically: "
            + message
        ) from exception
    except (urllib.error.URLError, TimeoutError, socket.timeout) as exception:
        raise AssetFactoryNodeError(
            "The paid Sub2API image edit has an uncertain result and will not be submitted again."
        ) from exception
    if len(raw) > MAX_RESPONSE_BYTES:
        raise AssetFactoryNodeError("Sub2API returned an oversized image response.")
    try:
        decoded = json.loads(raw)
    except (json.JSONDecodeError, UnicodeDecodeError) as exception:
        raise AssetFactoryNodeError("Sub2API returned an invalid image response.") from exception
    if not isinstance(decoded, dict):
        raise AssetFactoryNodeError("Sub2API returned no usable image result.")
    if response_request_id:
        decoded["_asset_factory_request_id"] = response_request_id[:190]
    return decoded


def _safe_usage(value: Any) -> dict[str, Any]:
    if not isinstance(value, dict):
        return {}
    safe: dict[str, Any] = {}
    for name, item in list(value.items())[:30]:
        key = str(name)[:80]
        if isinstance(item, bool):
            safe[key] = item
        elif isinstance(item, (int, float)):
            safe[key] = max(0, item)
        elif isinstance(item, dict):
            nested = {
                str(nested_name)[:80]: max(0, nested_value)
                for nested_name, nested_value in list(item.items())[:20]
                if isinstance(nested_value, (int, float))
                and not isinstance(nested_value, bool)
            }
            if nested:
                safe[key] = nested
    return safe


def _safe_usage_json(value: str) -> dict[str, Any]:
    try:
        decoded = json.loads(str(value or "{}"))
    except json.JSONDecodeError:
        return {}
    return _safe_usage(decoded)


def _safe_json_object(value: str) -> dict[str, Any]:
    try:
        decoded = json.loads(str(value or "{}"))
    except (json.JSONDecodeError, UnicodeDecodeError):
        return {}
    return decoded if isinstance(decoded, dict) else {}


def _response_error(payload: dict[str, Any]) -> str:
    error = payload.get("error")
    if isinstance(error, dict):
        return str(error.get("message") or error.get("code") or "").strip()[:500]
    if isinstance(error, str):
        return error.strip()[:500]
    return str(payload.get("message") or "").strip()[:500]


def _image_tensor(content: bytes) -> Any:
    try:
        import io
        import numpy
        import torch
    except ImportError as exception:
        raise AssetFactoryNodeError(
            "ComfyUI image tensor dependencies are unavailable."
        ) from exception
    try:
        with Image.open(io.BytesIO(content)) as image:
            array = numpy.asarray(image.convert("RGB"), dtype=numpy.float32).copy()
    except (OSError, ValueError) as exception:
        raise AssetFactoryNodeError("Generated image could not become a tensor.") from exception
    return torch.from_numpy(array / 255.0).unsqueeze(0)


def _pil_tensor(image: Image.Image) -> Any:
    try:
        import numpy
        import torch
    except ImportError as exception:
        raise AssetFactoryNodeError(
            "ComfyUI image tensor dependencies are unavailable."
        ) from exception
    array = numpy.asarray(image.convert("RGB"), dtype=numpy.float32).copy()
    return torch.from_numpy(array / 255.0).unsqueeze(0)


def _decode_text_layout(value: str) -> dict[str, Any]:
    try:
        decoded = json.loads(str(value or "{}"))
    except (json.JSONDecodeError, UnicodeDecodeError) as exception:
        raise AssetFactoryNodeError("Deterministic text layout JSON is invalid.") from exception
    if not isinstance(decoded, dict):
        raise AssetFactoryNodeError("Deterministic text layout must be an object.")
    approved_copy = decoded.get("approved_copy")
    if isinstance(approved_copy, dict):
        decoded["headline"] = approved_copy.get("headline", decoded.get("headline", ""))
        decoded["body"] = approved_copy.get("body", decoded.get("body", ""))
    plan = decoded.get("layout_plan")
    if not isinstance(plan, dict):
        plan = {}
    legacy_colors = {
        "panel": decoded.get("panel_color"),
        "text": decoded.get("text_color"),
        "accent": decoded.get("accent_color"),
    }
    colors = plan.get("colors")
    if not isinstance(colors, dict):
        colors = {}
    decoded["colors"] = {
        key: colors.get(key) or value
        for key, value in legacy_colors.items()
        if colors.get(key) or value
    }
    decoded["layout_plan"] = plan
    for field in ("headline", "body"):
        text = str(decoded.get(field) or "").strip()
        if len(text) > (120 if field == "headline" else 360):
            raise AssetFactoryNodeError(f"Approved {field} text exceeds the slot limit.")
        decoded[field] = text
    return decoded


def _fit_text_layout(
    draw: ImageDraw.ImageDraw,
    headline: str,
    body: str,
    bold_font_path: Path,
    regular_font_path: Path,
    max_width: int,
    max_height: int,
    typography: dict[str, Any],
) -> dict[str, Any]:
    headline_rules = typography.get("headline")
    headline_rules = headline_rules if isinstance(headline_rules, dict) else {}
    body_rules = typography.get("body")
    body_rules = body_rules if isinstance(body_rules, dict) else {}
    headline_min = max(18, int(headline_rules.get("min_size") or 24))
    headline_max = max(headline_min, min(144, int(headline_rules.get("max_size") or 54)))
    body_min = max(13, int(body_rules.get("min_size") or 16))
    body_max = max(body_min, min(96, int(body_rules.get("max_size") or 30)))
    headline_max_lines = max(1, min(4, int(headline_rules.get("max_lines") or 2)))
    body_max_lines = max(1, min(8, int(body_rules.get("max_lines") or 3)))
    headline_size = headline_max
    body_size = body_max

    while True:
        headline_font = ImageFont.truetype(str(bold_font_path), headline_size)
        body_font = ImageFont.truetype(str(regular_font_path), body_size)
        try:
            headline_lines = (
                _wrap_text(draw, headline, headline_font, max_width, headline_max_lines)
                if headline
                else []
            )
            body_lines = (
                _wrap_text(draw, body, body_font, max_width, body_max_lines)
                if body
                else []
            )
        except AssetFactoryNodeError:
            headline_lines = []
            body_lines = []
            fits = False
        else:
            headline_line_height = max(
                1, round(_font_line_height(draw, headline_font) * 1.20)
            )
            body_line_height = max(
                1, round(_font_line_height(draw, body_font) * 1.34)
            )
            gap = max(7, round(body_size * 0.55)) if headline_lines and body_lines else 0
            content_height = (
                len(headline_lines) * headline_line_height
                + len(body_lines) * body_line_height
                + gap
            )
            widths = [
                float(draw.textlength(line, font=headline_font))
                for line in headline_lines
            ] + [
                float(draw.textlength(line, font=body_font))
                for line in body_lines
            ]
            content_width = max(widths, default=0.0)
            fits = content_height <= max_height and content_width <= max_width
            if fits:
                return {
                    "headline_font": headline_font,
                    "body_font": body_font,
                    "headline_lines": headline_lines,
                    "body_lines": body_lines,
                    "headline_line_height": headline_line_height,
                    "body_line_height": body_line_height,
                    "headline_size": headline_size,
                    "body_size": body_size,
                    "gap": gap,
                    "content_width": int(round(content_width)),
                    "content_height": int(round(content_height)),
                }
        if headline_size <= headline_min and body_size <= body_min:
            raise AssetFactoryNodeError(
                "Approved text cannot fit the adaptive layout without becoming unreadable."
            )
        if headline_size > headline_min and (
            headline_size / max(1, headline_max) >= body_size / max(1, body_max)
        ):
            headline_size -= 1
        elif body_size > body_min:
            body_size -= 1
        else:
            headline_size -= 1


def _font_line_height(
    draw: ImageDraw.ImageDraw,
    font: ImageFont.FreeTypeFont,
) -> int:
    bounds = draw.textbbox((0, 0), "Ag中文", font=font)
    return max(1, int(bounds[3] - bounds[1]))


def _cjk_font_paths() -> tuple[Path, Path]:
    explicit_regular = os.environ.get("ASSET_FACTORY_CJK_FONT", "").strip()
    explicit_bold = os.environ.get("ASSET_FACTORY_CJK_BOLD_FONT", "").strip()
    regular_candidates = [
        explicit_regular,
        "/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc",
        "/usr/share/fonts/truetype/wqy/wqy-zenhei.ttc",
        "/System/Library/Fonts/PingFang.ttc",
        "/System/Library/Fonts/STHeiti Light.ttc",
    ]
    bold_candidates = [
        explicit_bold,
        "/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc",
        "/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc",
        "/usr/share/fonts/truetype/wqy/wqy-zenhei.ttc",
        "/System/Library/Fonts/PingFang.ttc",
        "/System/Library/Fonts/STHeiti Medium.ttc",
    ]
    regular = next((Path(path) for path in regular_candidates if path and Path(path).is_file()), None)
    bold = next((Path(path) for path in bold_candidates if path and Path(path).is_file()), None)
    if regular is None or bold is None:
        raise AssetFactoryNodeError(
            "A reviewed CJK font is required for deterministic text layout."
        )
    return regular, bold


def _layout_color(value: Any, fallback: str) -> tuple[int, int, int]:
    try:
        return ImageColor.getrgb(str(value or fallback))
    except ValueError:
        return ImageColor.getrgb(fallback)


def _wrap_text(
    draw: ImageDraw.ImageDraw,
    text: str,
    font: ImageFont.FreeTypeFont,
    max_width: int,
    max_lines: int,
) -> list[str]:
    lines: list[str] = []
    current = ""
    normalized = text.replace("\r", "").replace("\n", " ")
    # Keep Latin words/numbers atomic while allowing CJK text to wrap per
    # character.  The old character-only loop could render "Essential" as
    # "Essentia" + "l Care", which is technically inside the safe area but
    # visibly broken for mixed-language ecommerce copy.
    tokens = re.findall(
        r"[A-Za-z0-9]+(?:[._&+/'-][A-Za-z0-9]+)*|[ \t]+|[^A-Za-z0-9 \t]",
        normalized,
    )
    for token in tokens:
        candidate = current + token
        if not current or draw.textlength(candidate, font=font) <= max_width:
            current = candidate
            continue
        lines.append(current.strip())
        current = token.lstrip()
        if len(lines) >= max_lines:
            raise AssetFactoryNodeError(
                "Approved text exceeds the deterministic layout line limit."
            )
        if current and draw.textlength(current, font=font) > max_width:
            raise AssetFactoryNodeError(
                "A Latin word exceeds the deterministic layout width."
            )
    if current.strip():
        lines.append(current.strip())
    if len(lines) > max_lines:
        raise AssetFactoryNodeError(
            "Approved text exceeds the deterministic layout line limit."
        )
    return lines


def _tensor_png(image: Any) -> bytes:
    try:
        import io
        import numpy
    except ImportError as exception:
        raise AssetFactoryNodeError(
            "ComfyUI image encoding dependencies are unavailable."
        ) from exception
    try:
        value = image.detach().cpu().numpy() if hasattr(image, "detach") else image
        array = numpy.asarray(value)
        if array.ndim == 4:
            array = array[0]
        if array.ndim != 3 or array.shape[2] not in {3, 4}:
            raise ValueError("unexpected tensor shape")
        encoded = (numpy.clip(array, 0.0, 1.0) * 255.0).round().astype(numpy.uint8)
        buffer = io.BytesIO()
        Image.fromarray(encoded, "RGBA" if encoded.shape[2] == 4 else "RGB").save(
            buffer,
            format="PNG",
        )
        content = buffer.getvalue()
    except (AttributeError, TypeError, ValueError, OSError) as exception:
        raise AssetFactoryNodeError("ComfyUI image tensor could not be encoded.") from exception
    _verify_image(content)
    return content


def _multipart(
    fields: dict[str, str], uploads: list[dict[str, Any]]
) -> tuple[bytes, str]:
    boundary = "----AssetFactory" + secrets.token_hex(16)
    body = bytearray()
    for name, value in fields.items():
        body.extend(f"--{boundary}\r\n".encode())
        body.extend(
            f'Content-Disposition: form-data; name="{name}"\r\n\r\n'.encode()
        )
        body.extend(str(value).encode("utf-8"))
        body.extend(b"\r\n")
    for index, upload in enumerate(uploads):
        content = upload["content"]
        if not isinstance(content, bytes):
            raise AssetFactoryNodeError("Reference image data is invalid.")
        body.extend(f"--{boundary}\r\n".encode())
        body.extend(
            (
                'Content-Disposition: form-data; name="image['
                + str(index)
                + ']"; filename="'
                + _header_filename(str(upload["name"]))
                + '"\r\n'
            ).encode("utf-8")
        )
        body.extend(f'Content-Type: {upload["mime"]}\r\n\r\n'.encode())
        body.extend(content)
        body.extend(b"\r\n")
    body.extend(f"--{boundary}--\r\n".encode())
    if len(body) > MAX_REFERENCE_BYTES:
        raise AssetFactoryNodeError("Combined reference images exceed the allowed size.")
    return bytes(body), "multipart/form-data; boundary=" + boundary


def _header_filename(value: str) -> str:
    safe = "".join(
        character
        for character in Path(value).name
        if character.isalnum() or character in "._-"
    )
    return safe[:120] or "reference.png"


def _response_image(payload: dict[str, Any]) -> bytes:
    items = payload.get("data")
    item = items[0] if isinstance(items, list) and items else None
    if not isinstance(item, dict):
        raise AssetFactoryNodeError("Image provider returned no usable image.")
    encoded = str(item.get("b64_json") or "").strip()
    if encoded:
        try:
            content = base64.b64decode(encoded, validate=True)
        except (ValueError, TypeError) as exception:
            raise AssetFactoryNodeError(
                "Image provider returned invalid base64 data."
            ) from exception
        if len(content) > MAX_OUTPUT_BYTES:
            raise AssetFactoryNodeError("Generated image exceeds the allowed size.")
        return content
    url = str(item.get("url") or "").strip()
    if url.startswith("data:"):
        return _data_url_image(url)
    if not url:
        raise AssetFactoryNodeError("Image provider returned no image URL.")
    return _download_public_image(url)


def _data_url_image(value: str) -> bytes:
    header, separator, encoded = value.partition(",")
    if not separator or ";base64" not in header.lower():
        raise AssetFactoryNodeError("Generated image data URL is invalid.")
    try:
        content = base64.b64decode(encoded, validate=True)
    except (ValueError, TypeError) as exception:
        raise AssetFactoryNodeError("Generated image data URL is invalid.") from exception
    if len(content) > MAX_OUTPUT_BYTES:
        raise AssetFactoryNodeError("Generated image exceeds the allowed size.")
    return content


def _download_public_image(url: str) -> bytes:
    parsed = urlparse(url)
    if parsed.scheme != "https" or not parsed.hostname or parsed.username or parsed.password:
        raise AssetFactoryNodeError("Generated image URL is not a safe HTTPS address.")
    _reject_private_host(parsed.hostname)
    request = urllib.request.Request(
        url,
        headers={"Accept": "image/jpeg,image/png,image/webp"},
        method="GET",
    )
    try:
        with urllib.request.urlopen(request, timeout=90) as response:
            content_type = str(response.headers.get("Content-Type", "")).split(";")[0]
            if content_type and content_type not in {
                "image/jpeg",
                "image/png",
                "image/webp",
                "application/octet-stream",
            }:
                raise AssetFactoryNodeError(
                    "Generated file is not a supported image type."
                )
            content = response.read(MAX_OUTPUT_BYTES + 1)
    except (urllib.error.URLError, TimeoutError, socket.timeout) as exception:
        raise AssetFactoryNodeError("Generated image could not be downloaded.") from exception
    if not content or len(content) > MAX_OUTPUT_BYTES:
        raise AssetFactoryNodeError("Generated image is empty or too large.")
    return content


def _response_video_url(payload: dict[str, Any]) -> str:
    content = payload.get("content")
    content = content if isinstance(content, dict) else {}
    video = payload.get("video")
    video = video if isinstance(video, dict) else {}
    url = str(
        content.get("video_url")
        or content.get("file_url")
        or video.get("url")
        or payload.get("video_url")
        or payload.get("url")
        or ""
    ).strip()
    if not url:
        raise AssetFactoryNodeError(
            "Video provider completed without a usable video URL."
        )
    return url


def _download_public_video(url: str) -> tuple[bytes, str, str]:
    parsed = urlparse(url)
    if (
        parsed.scheme != "https"
        or not parsed.hostname
        or parsed.username
        or parsed.password
    ):
        raise AssetFactoryNodeError("Generated video URL is not a safe HTTPS address.")
    _reject_private_host(parsed.hostname)
    request = urllib.request.Request(
        url,
        headers={"Accept": "video/mp4,video/webm,video/quicktime"},
        method="GET",
    )
    try:
        with urllib.request.urlopen(request, timeout=180) as response:
            final = urlparse(str(response.geturl() or url))
            if final.scheme != "https" or not final.hostname:
                raise AssetFactoryNodeError(
                    "Generated video redirected to an unsafe address."
                )
            _reject_private_host(final.hostname)
            content_type = str(response.headers.get("Content-Type", "")).split(";")[0]
            if content_type and content_type not in {
                "video/mp4",
                "video/webm",
                "video/quicktime",
                "application/octet-stream",
            }:
                raise AssetFactoryNodeError(
                    "Generated file is not a supported video type."
                )
            content = response.read(MAX_VIDEO_BYTES + 1)
    except (urllib.error.URLError, TimeoutError, socket.timeout) as exception:
        raise AssetFactoryNodeError("Generated video could not be downloaded.") from exception
    if not content or len(content) > MAX_VIDEO_BYTES:
        raise AssetFactoryNodeError("Generated video is empty or too large.")
    if len(content) >= 12 and content[4:8] == b"ftyp":
        return content, ".mp4", "video/mp4"
    if content.startswith(b"\x1aE\xdf\xa3"):
        return content, ".webm", "video/webm"
    raise AssetFactoryNodeError("Generated video format could not be verified.")


def _reject_private_host(hostname: str) -> None:
    try:
        addresses = {
            result[4][0]
            for result in socket.getaddrinfo(hostname, 443, type=socket.SOCK_STREAM)
        }
    except socket.gaierror as exception:
        raise AssetFactoryNodeError("Generated image host could not be resolved.") from exception
    if not addresses:
        raise AssetFactoryNodeError("Generated image host has no address.")
    for address in addresses:
        ip = ipaddress.ip_address(address)
        if (
            ip.is_private
            or ip.is_loopback
            or ip.is_link_local
            or ip.is_multicast
            or ip.is_reserved
            or ip.is_unspecified
        ):
            raise AssetFactoryNodeError(
                "Generated image URL resolved to a private address."
            )


def _verify_image(content: bytes) -> str:
    if not content or len(content) > MAX_OUTPUT_BYTES:
        raise AssetFactoryNodeError("Image is empty or exceeds the allowed size.")
    temporary = None
    try:
        with tempfile.NamedTemporaryFile(delete=False) as handle:
            handle.write(content)
            temporary = handle.name
        with Image.open(temporary) as image:
            image.verify()
            image_format = str(image.format or "").upper()
    except (OSError, ValueError) as exception:
        raise AssetFactoryNodeError("Image data could not be decoded.") from exception
    finally:
        if temporary:
            Path(temporary).unlink(missing_ok=True)
    if image_format not in ALLOWED_IMAGE_FORMATS:
        raise AssetFactoryNodeError("Image format must be JPG, PNG or WebP.")
    return image_format


def _store_output(content: bytes, output_prefix: str, extension: str) -> tuple[str, str]:
    segments = [
        _safe_segment(segment)
        for segment in str(output_prefix or "").replace("\\", "/").split("/")
        if segment.strip()
    ]
    if not segments:
        segments = ["asset-factory", "product-image"]
    filename_prefix = segments.pop()[:80] or "product-image"
    subfolder = "/".join(segments[:4])
    output_root = Path(folder_paths.get_output_directory()).resolve()
    target_directory = (output_root / subfolder).resolve()
    if output_root != target_directory and output_root not in target_directory.parents:
        raise AssetFactoryNodeError("Output path is invalid.")
    target_directory.mkdir(parents=True, exist_ok=True)
    filename = f"{filename_prefix}-{secrets.token_hex(8)}{extension}"
    temporary = target_directory / ("." + filename + ".tmp")
    target = target_directory / filename
    temporary.write_bytes(content)
    os.replace(temporary, target)
    return filename, subfolder


def _safe_segment(value: str) -> str:
    safe = "".join(
        character
        for character in value.strip()
        if character.isalnum() or character in "._-"
    )
    return safe[:80] or "output"


def _upstream_error(raw: bytes) -> str:
    try:
        payload = json.loads(raw)
    except (json.JSONDecodeError, UnicodeDecodeError):
        return ""
    if not isinstance(payload, dict):
        return ""
    error = payload.get("error")
    if isinstance(error, dict):
        return str(error.get("message") or error.get("code") or "").strip()[:500]
    return str(payload.get("message") or "").strip()[:500]
