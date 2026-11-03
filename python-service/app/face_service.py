from __future__ import annotations

import base64
import binascii
import time

import cv2
import numpy as np

from app.config import MAX_IMAGE_BYTES, SFACE_MODEL, YUNET_MODEL


class FaceProcessingError(ValueError):
    pass


class FaceService:
    def __init__(self):
        if not YUNET_MODEL.is_file() or not SFACE_MODEL.is_file():
            raise RuntimeError("Modelos YuNet/SFace ausentes.")
        self.detector = cv2.FaceDetectorYN.create(str(YUNET_MODEL), "", (320, 320), 0.9, 0.3, 5000)
        self.recognizer = cv2.FaceRecognizerSF.create(str(SFACE_MODEL), "")

    def process_data_url(self, data_url: str) -> tuple[list[float], dict[str, float | int]]:
        started = time.perf_counter()
        try:
            prefix, encoded = data_url.split(",", 1)
            if prefix != "data:image/jpeg;base64":
                raise FaceProcessingError("Somente imagem JPEG e aceita.")
            raw = base64.b64decode(encoded, validate=True)
        except (ValueError, binascii.Error) as error:
            raise FaceProcessingError("Imagem invalida.") from error
        if not raw or len(raw) > MAX_IMAGE_BYTES:
            raise FaceProcessingError("Imagem vazia ou maior que 5 MB.")

        decode_started = time.perf_counter()
        image = cv2.imdecode(np.frombuffer(raw, np.uint8), cv2.IMREAD_COLOR)
        decode_ms = (time.perf_counter() - decode_started) * 1000
        if image is None:
            raise FaceProcessingError("Nao foi possivel decodificar a imagem.")
        height, width = image.shape[:2]
        if width < 160 or height < 120 or width > 4096 or height > 4096:
            raise FaceProcessingError("Dimensoes da imagem invalidas.")

        self.detector.setInputSize((width, height))
        detection_started = time.perf_counter()
        _, faces = self.detector.detect(image)
        detection_ms = (time.perf_counter() - detection_started) * 1000
        count = 0 if faces is None else len(faces)
        if count == 0:
            raise FaceProcessingError("Nenhum rosto foi detectado.")
        if count > 1:
            raise FaceProcessingError("Mais de um rosto foi detectado.")

        extraction_started = time.perf_counter()
        aligned = self.recognizer.alignCrop(image, faces[0])
        feature_array = self.recognizer.feature(aligned).flatten().astype(float)
        norm = float(np.linalg.norm(feature_array))
        if not np.isfinite(norm) or norm <= 0.0:
            raise FaceProcessingError("O SFace produziu um embedding invalido.")
        feature = (feature_array / norm).tolist()
        extraction_ms = (time.perf_counter() - extraction_started) * 1000
        return feature, {
            "image_decode_ms": round(decode_ms, 3),
            "detection_ms": round(detection_ms, 3),
            "extraction_ms": round(extraction_ms, 3),
            "facial_processing_ms": round((time.perf_counter() - started) * 1000, 3),
            "faces_detected": count,
        }
