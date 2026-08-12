# Asset Factory ComfyUI Nodes

This package contains the production-controlled ComfyUI nodes used by the AI
commerce asset factory. It must be installed as:

```text
/opt/comfyui/custom_nodes/asset_factory_nodes
```

The current business application publishes only `canvas_edit_v1` and uses the
image provider/save nodes for canvas editing. Product listing, detail-page,
free-creation and video tasks call Sub2API directly. Legacy and video node
classes remain for compatibility, but they are not a
supported business-workflow route and must not be published as application
templates.

`AssetFactoryRoutedImage` accepts zero to six images already uploaded to
ComfyUI's input directory. It calls a loopback-only ThinkPHP endpoint using a
short-lived, task-scoped token and writes the verified result to ComfyUI's
output directory.

The V2 provider nodes split orchestration from provider execution:

- `AssetFactorySub2ApiImage` calls the loopback Sub2API image endpoint and
  returns a ComfyUI `IMAGE`.
- `AssetFactorySaveImage` saves the verified tensor after downstream nodes have
  finished processing it.
- `AssetFactorySub2ApiVideo` submits a paid video task once and resumes polling
  the accepted provider task after a workflow restart.
- `AssetFactorySaveVideo` downloads and stores the completed video.

Every paid dispatch is written atomically to
`/var/lib/asset-factory/comfyui/provider-state` before the network call. A
completed image can be replayed from its verified cache, but a dispatched task
with an ambiguous result is never submitted again.

Required service environment:

```text
ASSET_FACTORY_CONTROL_PLANE_URL=http://127.0.0.1
ASSET_FACTORY_CONTROL_PLANE_HOST=studio.example.com
```

The ComfyUI-only Sub2API service key is loaded with systemd
`LoadCredential=` from the root-only file below. It is not stored in
`comfyui.env`, workflow JSON, node inputs, logs, or saved dispatch metadata.

```text
/etc/asset-factory/sub2api/asset-factory-comfyui-production.env
```

Security boundaries:

- ComfyUI must listen only on `127.0.0.1`.
- The control-plane URL must be a loopback HTTP address.
- Every call requires a short-lived token bound to task, model, prompt, size
  and seed.
- Reference paths cannot leave ComfyUI's input directory.
- Provider credentials, channel selection, merchant sessions, billing and
  database access are not available to the node.
- The V2 provider nodes may only call a loopback HTTP Sub2API address.
- Paid V2 calls use deterministic request and idempotency headers and are never
  retried automatically after dispatch.
- Generated remote URLs must use HTTPS and may not resolve to private ranges.
- Production installs pin both ComfyUI and this package to reviewed versions.

Run the isolated node tests with:

```bash
python -m unittest test_asset_factory_nodes.py
```
