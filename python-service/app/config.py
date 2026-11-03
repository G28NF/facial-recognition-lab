from pathlib import Path
import os

ROOT = Path(__file__).resolve().parents[1]
MODELS_DIR = ROOT / "models"
YUNET_MODEL = Path(os.getenv("RFV2_YUNET_MODEL", MODELS_DIR / "face_detection_yunet_2023mar.onnx"))
SFACE_MODEL = Path(os.getenv("RFV2_SFACE_MODEL", MODELS_DIR / "face_recognition_sface_2021dec.onnx"))
MAX_IMAGE_BYTES = 5 * 1024 * 1024
INTERNAL_TOKEN = os.getenv("RFV2_PYTHON_TOKEN", "")

if len(INTERNAL_TOKEN) < 32:
    raise RuntimeError("RFV2_PYTHON_TOKEN nao configurado.")
