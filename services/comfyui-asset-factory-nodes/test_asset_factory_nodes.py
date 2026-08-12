from __future__ import annotations

import base64
import importlib.util
import io
import json
import os
import sys
import tempfile
import types
import unittest
from pathlib import Path
from unittest.mock import patch

from PIL import Image


def png_bytes(color: tuple[int, int, int] = (20, 120, 180)) -> bytes:
    buffer = io.BytesIO()
    Image.new("RGB", (32, 32), color).save(buffer, format="PNG")
    return buffer.getvalue()


class FakeResponse:
    def __init__(
        self,
        payload: bytes,
        content_type: str = "application/json",
        url: str = "https://example.com/result",
        headers: dict[str, str] | None = None,
    ):
        self.payload = payload
        self.headers = {"Content-Type": content_type, **(headers or {})}
        self.url = url

    def __enter__(self):
        return self

    def __exit__(self, exc_type, exc, traceback):
        return False

    def read(self, size: int = -1) -> bytes:
        return self.payload if size < 0 else self.payload[:size]

    def geturl(self) -> str:
        return self.url


class AssetFactoryNodeTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory()
        cls.input_dir = Path(cls.temp.name) / "input"
        cls.output_dir = Path(cls.temp.name) / "output"
        cls.input_dir.mkdir()
        cls.output_dir.mkdir()
        folder_paths = types.ModuleType("folder_paths")
        folder_paths.get_input_directory = lambda: str(cls.input_dir)
        folder_paths.get_output_directory = lambda: str(cls.output_dir)
        sys.modules["folder_paths"] = folder_paths
        path = Path(__file__).with_name("asset_factory_nodes.py")
        spec = importlib.util.spec_from_file_location("asset_factory_nodes_test", path)
        cls.module = importlib.util.module_from_spec(spec)
        assert spec and spec.loader
        spec.loader.exec_module(cls.module)

    @classmethod
    def tearDownClass(cls):
        cls.temp.cleanup()

    def setUp(self):
        os.environ["ASSET_FACTORY_CONTROL_PLANE_URL"] = "http://127.0.0.1"
        os.environ["ASSET_FACTORY_CONTROL_PLANE_HOST"] = "studio.example.com"
        os.environ["ASSET_FACTORY_SUB2API_BASE_URL"] = "http://127.0.0.1:3002"
        os.environ["ASSET_FACTORY_SUB2API_SERVICE_KEY"] = "sub2api-test-key"
        state_dir = Path(self.temp.name) / "state" / self._testMethodName
        state_dir.mkdir(parents=True, exist_ok=True)
        os.environ["ASSET_FACTORY_SUB2API_STATE_DIR"] = str(state_dir)
        (self.input_dir / "reference.png").write_bytes(png_bytes())

    def test_node_posts_reference_and_saves_verified_output(self):
        generated = png_bytes((180, 80, 30))
        payload = json.dumps(
            {"data": [{"b64_json": base64.b64encode(generated).decode()}]}
        ).encode()
        with patch.object(
            self.module.urllib.request,
            "urlopen",
            return_value=FakeResponse(payload),
        ) as request:
            result = self.module.AssetFactoryRoutedImage().generate(
                prompt="Keep the product shape and create a clean main image.",
                reference_images_json='["reference.png"]',
                model="gpt-image-seedream-5",
                model_id=17,
                size="1024x1024",
                seed=9_999_999_999,
                output_prefix="asset-factory/TEST/main-1",
                job_id=91,
                generation_item_id=92,
                callback_token="signed-test-token",
            )
        image = result["ui"]["images"][0]
        saved = self.output_dir / image["subfolder"] / image["filename"]
        self.assertTrue(saved.is_file())
        self.assertEqual(saved.read_bytes(), generated)
        body = request.call_args.args[0].data
        self.assertIn(b'name="image[0]"', body)
        self.assertIn(b'name="seed"', body)
        self.assertIn(b"2147483647", body)
        self.assertIn(b'name="model_id"', body)
        self.assertNotIn(b"signed-test-token", body)
        outgoing = request.call_args.args[0]
        self.assertTrue(outgoing.full_url.endswith("/internal/comfyui/images"))
        self.assertEqual(outgoing.headers["Authorization"], "Bearer signed-test-token")
        self.assertEqual(outgoing.headers["Host"], "studio.example.com")

    def test_path_traversal_is_rejected(self):
        with self.assertRaises(self.module.AssetFactoryNodeError):
            self.module._read_reference("../outside.png")

    def test_node_supports_text_to_image_without_references(self):
        generated = png_bytes((40, 160, 90))
        payload = json.dumps(
            {"data": [{"b64_json": base64.b64encode(generated).decode()}]}
        ).encode()
        with patch.object(
            self.module.urllib.request,
            "urlopen",
            return_value=FakeResponse(payload),
        ) as request:
            result = self.module.AssetFactoryRoutedImage().generate(
                prompt="Create a clean ecommerce still life.",
                reference_images_json="[]",
                model="gpt-image-seedream-5",
                model_id=17,
                size="1024x1024",
                seed=42,
                output_prefix="asset-factory/TEST/free-1",
                job_id=0,
                generation_item_id=0,
                callback_token="system-test-token",
            )
        image = result["ui"]["images"][0]
        self.assertTrue((self.output_dir / image["subfolder"] / image["filename"]).is_file())
        outgoing = request.call_args.args[0]
        self.assertTrue(outgoing.full_url.endswith("/internal/comfyui/images"))
        self.assertIn(b'name="seed"', outgoing.data)
        self.assertIn(b"\r\n42\r\n", outgoing.data)
        self.assertNotIn(b'name="image[0]"', outgoing.data)

    def test_control_plane_must_remain_loopback_only(self):
        with self.assertRaises(self.module.AssetFactoryNodeError):
            self.module._loopback_endpoint(
                "https://example.com", "/v1/images/edits"
            )

    def test_reference_limit_is_enforced(self):
        references = json.dumps(["reference.png"] * 7)
        with self.assertRaises(self.module.AssetFactoryNodeError):
            self.module._decode_references(references)

    def test_video_node_uses_control_plane_and_stores_verified_output(self):
        video = b"\x00\x00\x00\x18ftypmp42" + b"\x00" * 64
        responses = [
            FakeResponse(json.dumps({"id": "video-task-1", "status": "queued"}).encode()),
            FakeResponse(
                json.dumps(
                    {
                        "id": "video-task-1",
                        "status": "succeeded",
                        "content": {"video_url": "https://example.com/video.mp4"},
                    }
                ).encode()
            ),
            FakeResponse(video, "video/mp4", "https://example.com/video.mp4"),
        ]
        with patch.object(
            self.module.urllib.request,
            "urlopen",
            side_effect=responses,
        ) as request, patch.object(self.module, "_reject_private_host"):
            result = self.module.AssetFactoryRoutedVideo().generate(
                prompt="Keep the product stable while the camera moves slowly.",
                reference_image_url="https://example.com/reference.png",
                model="doubao-seedance-2-0-260128",
                model_id=28,
                ratio="9:16",
                resolution="720p",
                duration_seconds=5,
                generate_audio=False,
                output_prefix="asset-factory/VIDEO/product-1",
                job_id=93,
                callback_token="signed-video-token",
            )
        output = result["ui"]["videos"][0]
        saved = self.output_dir / output["subfolder"] / output["filename"]
        self.assertTrue(saved.is_file())
        self.assertEqual(saved.read_bytes(), video)
        self.assertEqual(request.call_count, 3)
        submit = request.call_args_list[0].args[0]
        status = request.call_args_list[1].args[0]
        self.assertTrue(submit.full_url.endswith("/internal/comfyui/videos"))
        self.assertIn(b"action=submit", submit.data)
        self.assertIn(b"action=status", status.data)
        self.assertEqual(submit.headers["Authorization"], "Bearer signed-video-token")

    def test_sub2api_image_uses_isolated_key_and_cached_idempotent_result(self):
        generated = png_bytes((90, 30, 210))
        payload = json.dumps(
            {
                "id": "sub2api-image-1",
                "data": [{"b64_json": base64.b64encode(generated).decode()}],
                "usage": {"total_tokens": 321},
            }
        ).encode()
        sentinel = object()
        with patch.object(
            self.module.urllib.request,
            "urlopen",
            return_value=FakeResponse(
                payload,
                headers={"X-Request-ID": "gateway-request-1"},
            ),
        ) as request, patch.object(
            self.module,
            "_image_tensor",
            return_value=sentinel,
        ):
            first = self.module.AssetFactorySub2ApiImage().generate(
                prompt="Create a premium ecommerce hero image.",
                negative_prompt="No text.",
                reference_images_json="[]",
                mask_image="",
                sub2api_model="gpt-image-2",
                size="1024x1024",
                seed=42,
                job_no="JOB-1001",
                item_id=17,
                workflow_code="product_image_v2",
                workflow_version=1,
                idempotency_key="comfy-image-JOB-1001-17",
            )
            second = self.module.AssetFactorySub2ApiImage().generate(
                prompt="Create a premium ecommerce hero image.",
                negative_prompt="No text.",
                reference_images_json="[]",
                mask_image="",
                sub2api_model="gpt-image-2",
                size="1024x1024",
                seed=42,
                job_no="JOB-1001",
                item_id=17,
                workflow_code="product_image_v2",
                workflow_version=1,
                idempotency_key="comfy-image-JOB-1001-17",
            )
        self.assertIs(first[0], sentinel)
        self.assertIs(second[0], sentinel)
        self.assertEqual(first[1], "sub2api-image-1")
        self.assertEqual(second[1], "sub2api-image-1")
        self.assertEqual(request.call_count, 1)
        outgoing = request.call_args.args[0]
        self.assertTrue(outgoing.full_url.endswith("/v1/images/generations"))
        self.assertEqual(
            outgoing.headers["Authorization"],
            "Bearer sub2api-test-key",
        )
        self.assertTrue(outgoing.headers["Idempotency-key"].startswith("af-comfy-"))
        self.assertNotIn(b"sub2api-test-key", outgoing.data)
        state_files = list(
            Path(os.environ["ASSET_FACTORY_SUB2API_STATE_DIR"]).glob("*.json")
        )
        self.assertEqual(len(state_files), 1)
        state_text = state_files[0].read_text(encoding="utf-8")
        self.assertNotIn("sub2api-test-key", state_text)
        self.assertNotIn("premium ecommerce", state_text)

    def test_sub2api_ambiguous_failure_is_never_resubmitted(self):
        arguments = {
            "prompt": "Create a product image.",
            "negative_prompt": "",
            "reference_images_json": "[]",
            "mask_image": "",
            "sub2api_model": "gpt-image-2",
            "size": "1024x1024",
            "seed": 9,
            "job_no": "JOB-UNCERTAIN",
            "item_id": 18,
            "workflow_code": "product_image_v2",
            "workflow_version": 1,
            "idempotency_key": "comfy-image-JOB-UNCERTAIN-18",
        }
        with patch.object(
            self.module.urllib.request,
            "urlopen",
            side_effect=self.module.urllib.error.URLError("network timeout"),
        ) as request:
            with self.assertRaisesRegex(
                self.module.AssetFactoryNodeError,
                "uncertain result",
            ):
                self.module.AssetFactorySub2ApiImage().generate(**arguments)
            with self.assertRaisesRegex(
                self.module.AssetFactoryNodeError,
                "already dispatched",
            ):
                self.module.AssetFactorySub2ApiImage().generate(**arguments)
        self.assertEqual(request.call_count, 1)

    def test_sub2api_image_edit_uses_multipart_without_key_in_body(self):
        generated = png_bytes((200, 170, 20))
        payload = json.dumps(
            {"data": [{"b64_json": base64.b64encode(generated).decode()}]}
        ).encode()
        with patch.object(
            self.module.urllib.request,
            "urlopen",
            return_value=FakeResponse(payload),
        ) as request, patch.object(
            self.module,
            "_image_tensor",
            return_value="tensor",
        ):
            result = self.module.AssetFactorySub2ApiImage().generate(
                prompt="Preserve the exact product.",
                negative_prompt="",
                reference_images_json='["reference.png"]',
                mask_image="",
                sub2api_model="gpt-image-2",
                size="1024x1024",
                seed=7,
                job_no="JOB-EDIT",
                item_id=19,
                workflow_code="product_image_v2",
                workflow_version=1,
                idempotency_key="comfy-image-JOB-EDIT-19",
            )
        self.assertEqual(result[0], "tensor")
        outgoing = request.call_args.args[0]
        self.assertTrue(outgoing.full_url.endswith("/v1/images/edits"))
        self.assertIn(b'name="image"', outgoing.data)
        self.assertIn(b'name="model"', outgoing.data)
        self.assertNotIn(b"sub2api-test-key", outgoing.data)

    def test_deterministic_text_layout_uses_exact_slots_and_safe_area(self):
        source = png_bytes((230, 235, 240))
        sentinel = object()
        font_path = Path(self.module._cjk_font_paths()[0])
        with patch.object(
            self.module,
            "_tensor_png",
            return_value=source,
        ), patch.object(
            self.module,
            "_pil_tensor",
            return_value=sentinel,
        ), patch.object(
            self.module,
            "_cjk_font_paths",
            return_value=(font_path, font_path),
        ):
            result, quality_json = (
                self.module.AssetFactoryDeterministicTextLayout().compose(
                    image=object(),
                    layout_json=json.dumps(
                        {
                            "schema_version": "layout_payload_v1",
                            "approved_copy": {
                                "headline": "核心卖点",
                                "body": "真实参数由系统精确排版",
                            },
                            "layout_plan": {
                                "anchor": "top_right",
                                "alignment": "right",
                                "safe_area_percent": 8,
                                "max_width_percent": 48,
                                "max_height_percent": 34,
                                "product_avoidance": True,
                                "panel": {
                                    "style": "adaptive_backplate",
                                    "opacity": 0.82,
                                },
                                "colors": {
                                    "accent": "#14b8a6",
                                    "panel": "#f8fafc",
                                    "text": "#0f172a",
                                },
                            },
                        },
                        ensure_ascii=False,
                    ),
                    canvas_width=512,
                    canvas_height=512,
                    safe_area_percent=8,
                )
            )
        quality = json.loads(quality_json)
        self.assertIs(result, sentinel)
        self.assertEqual(quality["status"], "passed")
        self.assertEqual(quality["slot_count"], 2)
        self.assertTrue(quality["safe_area_ok"])
        self.assertFalse(quality["overflow"])
        self.assertEqual(quality["anchor"], "top_right")
        self.assertEqual(quality["alignment"], "right")
        self.assertEqual(
            quality["approved_text_sha256"],
            quality["rendered_text_sha256"],
        )
        panel_height = quality["panel_bbox"][3] - quality["panel_bbox"][1]
        self.assertLessEqual(panel_height, round(512 * 0.34))
        self.assertNotEqual(panel_height, round(512 * 0.28))
        self.assertTrue(quality["text_bboxes"])

    def test_deterministic_text_layout_never_splits_latin_words(self):
        source = png_bytes((230, 235, 240))
        sentinel = object()
        font_path = Path(self.module._cjk_font_paths()[0])
        with patch.object(self.module, "_tensor_png", return_value=source), patch.object(
            self.module, "_pil_tensor", return_value=sentinel
        ), patch.object(
            self.module, "_cjk_font_paths", return_value=(font_path, font_path)
        ):
            _, quality_json = self.module.AssetFactoryDeterministicTextLayout().compose(
                image=object(),
                layout_json=json.dumps(
                    {
                        "approved_copy": {
                            "headline": "Dove Essential Care洗发水",
                            "body": "温和清洁 清晰呈现",
                        },
                        "layout_plan": {
                            "anchor": "top_left",
                            "max_width_percent": 48,
                            "max_height_percent": 38,
                        },
                    },
                    ensure_ascii=False,
                ),
                canvas_width=512,
                canvas_height=512,
                safe_area_percent=8,
            )
        quality = json.loads(quality_json)
        headline_lines = [
            box["text"] for box in quality["text_bboxes"] if box["role"] == "headline"
        ]
        self.assertEqual("".join(headline_lines).replace(" ", ""), "DoveEssentialCare洗发水")
        self.assertFalse(any(line.endswith("Essentia") for line in headline_lines))
        self.assertFalse(any(line.startswith("l Care") for line in headline_lines))

    def test_sub2api_video_resumes_accepted_task_without_resubmitting(self):
        state_identity = self.module._paid_request_identity(
            "comfy-video-JOB-2001-20",
            "video",
            "JOB-2001",
            20,
            "storyboard_video_v2",
            1,
        )
        self.assertIsNone(
            self.module._begin_paid_dispatch(state_identity, "video")
        )
        self.module._update_paid_dispatch(
            state_identity,
            {
                "status": "accepted",
                "request_id": "video-task-existing",
                "model": "video-model",
            },
        )
        completed = {
            "id": "video-task-existing",
            "status": "completed",
            "video_url": "https://example.com/result.mp4",
            "usage": {"total_tokens": 12},
        }
        with patch.object(
            self.module.urllib.request,
            "urlopen",
            return_value=FakeResponse(json.dumps(completed).encode()),
        ) as request:
            result = self.module.AssetFactorySub2ApiVideo().generate(
                prompt="Slow camera orbit.",
                negative_prompt="",
                reference_image_url="",
                sub2api_model="video-model",
                ratio="16:9",
                resolution="720p",
                duration_seconds=5,
                generate_audio=False,
                job_no="JOB-2001",
                item_id=20,
                workflow_code="storyboard_video_v2",
                workflow_version=1,
                idempotency_key="comfy-video-JOB-2001-20",
            )
        self.assertEqual(result[0], "https://example.com/result.mp4")
        self.assertEqual(request.call_count, 1)
        outgoing = request.call_args.args[0]
        self.assertEqual(outgoing.method, "GET")
        self.assertTrue(outgoing.full_url.endswith("/v1/videos/video-task-existing"))


if __name__ == "__main__":
    unittest.main()
