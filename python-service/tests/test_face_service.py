import base64
import threading
import time
from typing import Any

import numpy as np

from app import face_service
from app.face_service import FaceService


class FakeDetector:
    def __init__(self):
        self.active = 0
        self.max_active = 0
        self.guard = threading.Lock()

    def setInputSize(self, _size: tuple[int, int]) -> None:
        pass

    def detect(self, _image: Any) -> tuple[None, np.ndarray]:
        with self.guard:
            self.active += 1
            self.max_active = max(self.max_active, self.active)
        time.sleep(0.02)
        with self.guard:
            self.active -= 1
        return None, np.array([[0, 0, 100, 100]], dtype=np.float32)


class FakeRecognizer:
    def alignCrop(self, image: Any, _face: Any) -> Any:
        return image

    def feature(self, _aligned: Any) -> np.ndarray:
        return np.array([[3.0, 4.0]], dtype=np.float32)


def test_shared_opencv_objects_are_serialized(monkeypatch):
    service = object.__new__(FaceService)
    service.detector = FakeDetector()
    service.recognizer = FakeRecognizer()
    service._processing_lock = threading.Lock()
    monkeypatch.setattr(face_service.cv2, "imdecode", lambda *_: np.zeros((120, 160, 3), dtype=np.uint8))
    payload = "data:image/jpeg;base64," + base64.b64encode(b"image").decode()
    outputs = []

    threads = [threading.Thread(target=lambda: outputs.append(service.process_data_url(payload))) for _ in range(4)]
    for thread in threads:
        thread.start()
    for thread in threads:
        thread.join()

    assert len(outputs) == 4
    assert service.detector.max_active == 1
