from __future__ import annotations

import json
import threading
import unittest
import urllib.error
import urllib.request
from http.server import ThreadingHTTPServer
from typing import Any

import app


class FakeBridge:
    internal_key = "test-internal-key"

    def authorize(self, value: str) -> None:
        if value != "Bearer " + self.internal_key:
            raise app.BridgeError(401, "invalid_api_key", "Invalid API key")

    def models(self) -> dict[str, Any]:
        return {
            "object": "list",
            "data": [
                {
                    "id": app.SEEDREAM_MODEL,
                    "capabilities": ["image_generation", "image_edit"],
                },
                {"id": app.GLM_IMAGE_MODEL, "capabilities": ["image_generation"]},
                {
                    "id": app.SEEDANCE_MODEL,
                    "capabilities": ["video_i2v", "video_t2v"],
                },
            ],
        }

    def generate_image(self, payload: dict[str, Any]) -> dict[str, Any]:
        return {"data": [{"url": "https://example.com/image.png"}], "received": payload}

    def edit_image(
        self,
        fields: dict[str, str],
        uploads: list[dict[str, Any]],
        mask: dict[str, Any] | None,
    ) -> dict[str, Any]:
        return {
            "data": [{"url": "https://example.com/edited.png"}],
            "fields": fields,
            "upload_count": len(uploads),
            "mask": mask is not None,
        }

    def create_video(self, payload: dict[str, Any]) -> dict[str, Any]:
        return {
            "id": "video-task-1",
            "request_id": "video-task-1",
            "status": "queued",
            "received": payload,
        }

    def video_status(self, task_id: str) -> dict[str, Any]:
        return {
            "id": task_id,
            "status": "succeeded",
            "progress": 100,
            "url": "https://example.com/video.mp4",
        }


class BridgeHttpTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        handler = type("TestHandler", (app.Handler,), {"bridge": FakeBridge()})
        cls.server = ThreadingHTTPServer(("127.0.0.1", 0), handler)
        cls.base_url = "http://127.0.0.1:" + str(cls.server.server_address[1])
        cls.thread = threading.Thread(target=cls.server.serve_forever, daemon=True)
        cls.thread.start()

    @classmethod
    def tearDownClass(cls) -> None:
        cls.server.shutdown()
        cls.server.server_close()
        cls.thread.join(timeout=2)

    def request(
        self,
        path: str,
        *,
        method: str = "GET",
        payload: dict[str, Any] | None = None,
        content_type: str = "application/json",
        body: bytes | None = None,
        authorized: bool = True,
    ) -> tuple[int, dict[str, Any]]:
        if body is None and payload is not None:
            body = json.dumps(payload).encode("utf-8")
        headers = {"Content-Type": content_type}
        if authorized:
            headers["Authorization"] = "Bearer test-internal-key"
        request = urllib.request.Request(
            self.base_url + path,
            data=body,
            headers=headers,
            method=method,
        )
        try:
            response = urllib.request.urlopen(request, timeout=2)
        except urllib.error.HTTPError as error:
            try:
                return error.code, json.loads(error.read())
            finally:
                error.close()
        return response.status, json.loads(response.read())

    def test_health_is_public_but_models_require_auth(self) -> None:
        status, payload = self.request("/healthz", authorized=False)
        self.assertEqual(status, 200)
        self.assertEqual(payload["status"], "ok")

        status, payload = self.request("/v1/models", authorized=False)
        self.assertEqual(status, 401)
        self.assertEqual(payload["error"]["code"], "invalid_api_key")

    def test_model_catalog_exposes_image_and_video_capabilities(self) -> None:
        status, payload = self.request("/v1/models")
        self.assertEqual(status, 200)
        self.assertEqual(len(payload["data"]), 3)
        self.assertEqual(payload["data"][1]["capabilities"], ["image_generation"])
        self.assertIn("video_i2v", payload["data"][2]["capabilities"])

    def test_image_generation_forwards_standard_payload(self) -> None:
        status, payload = self.request(
            "/v1/images/generations",
            method="POST",
            payload={
                "model": app.SEEDREAM_MODEL,
                "prompt": "商品主图",
                "size": "1024x1024",
            },
        )
        self.assertEqual(status, 200)
        self.assertEqual(payload["received"]["prompt"], "商品主图")

    def test_multipart_edit_accepts_multiple_reference_images(self) -> None:
        boundary = "ark-bridge-test-boundary"
        chunks = []
        for name, value in (
            ("model", app.SEEDREAM_MODEL),
            ("prompt", "保持商品一致"),
            ("seed", "20260728"),
        ):
            chunks.append(
                f"--{boundary}\r\nContent-Disposition: form-data; name=\"{name}\"\r\n\r\n{value}\r\n".encode()
            )
        for index in range(2):
            chunks.append(
                (
                    f"--{boundary}\r\n"
                    f"Content-Disposition: form-data; name=\"image[{index}]\"; filename=\"ref-{index}.png\"\r\n"
                    "Content-Type: image/png\r\n\r\n"
                ).encode()
                + b"fake-png"
                + b"\r\n"
            )
        chunks.append(f"--{boundary}--\r\n".encode())
        status, payload = self.request(
            "/v1/images/edits",
            method="POST",
            body=b"".join(chunks),
            content_type="multipart/form-data; boundary=" + boundary,
        )
        self.assertEqual(status, 200)
        self.assertEqual(payload["upload_count"], 2)
        self.assertEqual(payload["fields"]["prompt"], "保持商品一致")
        self.assertEqual(payload["fields"]["seed"], "20260728")

    def test_video_submit_and_poll(self) -> None:
        status, payload = self.request(
            "/v1/videos/generations",
            method="POST",
            payload={
                "model": app.SEEDANCE_MODEL,
                "prompt": "商品轻微旋转展示",
                "image": ["https://example.com/reference.png"],
                "duration": 5,
            },
        )
        self.assertEqual(status, 200)
        self.assertEqual(payload["id"], "video-task-1")

        status, payload = self.request("/v1/videos/video-task-1")
        self.assertEqual(status, 200)
        self.assertEqual(payload["status"], "succeeded")
        self.assertEqual(payload["url"], "https://example.com/video.mp4")


class ProviderNormalizationTest(unittest.TestCase):
    def test_seedance_omits_unsupported_camera_fixed_option(self) -> None:
        captured: dict[str, Any] = {}

        class FakeTasks:
            def create(self, **kwargs: Any) -> Any:
                captured.update(kwargs)
                return type("Response", (), {"id": "video-task-1"})()

        class FakeClient:
            content_generation = type(
                "ContentGeneration",
                (),
                {"tasks": FakeTasks()},
            )()

        bridge = object.__new__(app.ArkBridge)
        bridge.client = lambda _model, _timeout: FakeClient()
        result = bridge.create_video(
            {
                "model": app.SEEDANCE_MODEL,
                "prompt": "固定机位测试",
                "camera_fixed": False,
            }
        )

        self.assertEqual(result["id"], "video-task-1")
        self.assertNotIn("camera_fixed", captured)
        captured.clear()

        result = bridge.create_video(
            {
                "model": app.SEEDANCE_MODEL,
                "prompt": "参考图测试",
                "image": ["https://example.com/reference.png"],
                "camera_fixed": True,
            }
        )

        self.assertEqual(result["id"], "video-task-1")
        self.assertNotIn("camera_fixed", captured)

    def test_seedream_size_upscales_legacy_image_dimensions(self) -> None:
        self.assertEqual(app.normalize_image_size("1024x1024"), "2048x2048")
        self.assertEqual(app.normalize_image_size("1536x1024"), "2496x1664")
        self.assertEqual(app.normalize_image_size("1024x1536"), "1664x2496")
        self.assertEqual(app.normalize_image_size("invalid"), "2048x2048")

    def test_model_json_supports_pydantic_v1_compatibility(self) -> None:
        class LegacyResponse:
            def model_dump(self, **kwargs: Any) -> dict[str, Any]:
                if "mode" in kwargs:
                    raise ValueError("mode is only supported in Pydantic v2")
                return {}

            def json(self, **kwargs: Any) -> str:
                return json.dumps({"data": [{"url": "https://example.com/image.png"}]})

        self.assertEqual(
            app.model_json(LegacyResponse())["data"][0]["url"],
            "https://example.com/image.png",
        )

    def test_glm_image_size_accepts_supported_custom_dimensions(self) -> None:
        self.assertEqual(app.normalize_glm_image_size("1536x1024"), "1536x1024")
        self.assertEqual(app.normalize_glm_image_size("1024x1536"), "1024x1536")
        self.assertEqual(app.normalize_glm_image_size("1000x1000"), "1024x1024")
        self.assertEqual(app.normalize_glm_image_size("4096x4096"), "1024x1024")

    def test_seedream_seed_is_limited_to_the_provider_range(self) -> None:
        self.assertEqual(app.normalize_image_seed(1), 1)
        self.assertEqual(app.normalize_image_seed(2_147_483_647), 2_147_483_647)
        self.assertEqual(app.normalize_image_seed(9_999_999_999), 2_147_483_647)
        self.assertEqual(app.normalize_image_seed(-8), 1)
        self.assertIsNone(app.normalize_image_seed(None))


if __name__ == "__main__":
    unittest.main()
