"""Controlled ComfyUI nodes used by the AI commerce asset factory."""

from .asset_factory_nodes import (
    AssetFactoryRoutedImage,
    AssetFactoryRoutedVideo,
    AssetFactoryDeterministicTextLayout,
    AssetFactorySaveImage,
    AssetFactorySaveVideo,
    AssetFactorySeedreamProductImage,
    AssetFactorySub2ApiImage,
    AssetFactorySub2ApiVideo,
)


NODE_CLASS_MAPPINGS = {
    "AssetFactoryRoutedImage": AssetFactoryRoutedImage,
    "AssetFactoryRoutedVideo": AssetFactoryRoutedVideo,
    "AssetFactoryDeterministicTextLayout": AssetFactoryDeterministicTextLayout,
    "AssetFactorySub2ApiImage": AssetFactorySub2ApiImage,
    "AssetFactorySaveImage": AssetFactorySaveImage,
    "AssetFactorySub2ApiVideo": AssetFactorySub2ApiVideo,
    "AssetFactorySaveVideo": AssetFactorySaveVideo,
    "AssetFactorySeedreamProductImage": AssetFactorySeedreamProductImage,
}

NODE_DISPLAY_NAME_MAPPINGS = {
    "AssetFactoryRoutedImage": "Asset Factory - ThinkPHP Routed Image",
    "AssetFactoryRoutedVideo": "Asset Factory - ThinkPHP Routed Video",
    "AssetFactoryDeterministicTextLayout": "Asset Factory V2 - Deterministic CJK Text Layout",
    "AssetFactorySub2ApiImage": "Asset Factory V2 - Sub2API Image",
    "AssetFactorySaveImage": "Asset Factory V2 - Save Image",
    "AssetFactorySub2ApiVideo": "Asset Factory V2 - Sub2API Video",
    "AssetFactorySaveVideo": "Asset Factory V2 - Save Video",
    "AssetFactorySeedreamProductImage": "Asset Factory - Legacy Routed Image",
}

__all__ = ["NODE_CLASS_MAPPINGS", "NODE_DISPLAY_NAME_MAPPINGS"]
