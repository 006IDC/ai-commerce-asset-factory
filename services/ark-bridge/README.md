# AI Media Provider Bridge

This loopback-only service keeps Volcengine and Zhipu credentials outside
Sub2API and translates the existing OpenAI/xAI media routes into provider
requests.

Supported routes:

- `GET /v1/models`
- `POST /v1/images/generations`
- `POST /v1/images/edits`
- `POST /v1/videos/generations`
- `GET /v1/videos/{task_id}`
- `GET /v1/videos/{task_id}/content`
- `GET /healthz`

The production service must bind to `127.0.0.1`. Store `VOLC_ACCESSKEY`,
`VOLC_SECRETKEY`, `ZHIPU_API_KEY`, and a random `ARK_BRIDGE_API_KEY` in a
root-owned environment file with mode `0600`; never place them in this
repository.

Create a dedicated virtual environment and install the pinned dependencies
from `requirements.txt`. Do not use the operating system Python environment
for the service.

Seedream reference editing supports one to six JPG, PNG, or WebP images. Ark
does not provide pixel-mask semantics for this model, so requests containing a
mask fail explicitly instead of silently performing a full-image edit.

Seedream 5.0 requires roughly 2K output area. The bridge maps the application's
legacy square, landscape, and portrait sizes to valid 2K dimensions while
preserving their aspect ratio. Ark SDK releases that still use Pydantic v1 are
also supported by the response normalizer.

Sub2API currently validates media model families before forwarding. The bridge
therefore exposes stable gateway aliases and converts them to official model
IDs:

- `gpt-image-glm` -> `glm-image`
- `gpt-image-seedream-5` -> `doubao-seedream-5-0-260128`
- `grok-imagine-video-seedance-2` -> `doubao-seedance-2-0-260128`

GLM-Image is intentionally text-to-image only. Reference-image requests must
use Seedream or another image-edit-capable model.
