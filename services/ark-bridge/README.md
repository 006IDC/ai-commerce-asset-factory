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

The production service must bind to `127.0.0.1`. The supplied systemd unit
requires systemd 250+ and loads the host-encrypted `ark-bridge-secrets`
credential from `/etc/credstore.encrypted/ark-bridge-secrets`. The historical
`infra/scripts/install-ark-bridge-env.py` filename is retained; it now pipes
the provisioned `VOLC_ACCESSKEY`, `VOLC_SECRETKEY`, and `ZHIPU_API_KEY` into
the official `systemd-creds` encryptor and generates a random internal key.
Only ciphertext is persisted, with an atomic replacement and mode `0600`.
Encryption failure leaves the previous credential intact and never writes
a plaintext fallback. It requires root and the host's `systemd-creds` tool.

systemd supplies the decrypted JSON through its private runtime credential
directory. The application reads it only in memory and rejects symlinks,
shared-readable files, missing values and malformed credentials. Existing
non-systemd process-environment launches remain supported; never commit or
log provider keys or the internal `ARK_BRIDGE_API_KEY`.

For an existing installation, provision the encrypted credential and update
the unit together, verify the loopback service, then remove the old plaintext
`/etc/ark-bridge.env` through the separately authorized operational process.
This source update does not migrate or delete a running installation's files.
Back up the host encryption key through the host's protected recovery process;
the encrypted credential cannot be recovered on another host without that key.
Hosts without systemd 250+ must keep their existing launch arrangement until
their deployment plan is updated; the new installer fails instead of silently
weakening encryption.

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
