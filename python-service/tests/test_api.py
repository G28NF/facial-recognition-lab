import os

os.environ.setdefault("RFV2_PYTHON_TOKEN", "t" * 64)

from fastapi.testclient import TestClient

from app.config import INTERNAL_TOKEN
from app.main import app


def test_models_are_loaded_once_and_internal_routes_require_token():
    with TestClient(app) as client:
        health = client.get("/health")
        assert health.status_code == 200
        assert health.json() == {"status": "ok", "models_loaded": True}

        assert client.get("/warmup").status_code == 403
        headers = {"X-Internal-Token": INTERNAL_TOKEN}
        first = client.get("/warmup", headers=headers)
        second = client.get("/warmup", headers=headers)
        assert first.status_code == 200
        assert first.json()["ready"] is True
        assert first.json()["initialization_ms"] == second.json()["initialization_ms"]


def test_invalid_image_is_rejected_without_returning_an_embedding():
    with TestClient(app) as client:
        response = client.post(
            "/extract",
            headers={"X-Internal-Token": INTERNAL_TOKEN},
            json={"image": "data:image/jpeg;base64," + "YQ==" * 20},
        )
        assert response.status_code == 422
        assert "embedding" not in response.json()
