from contextlib import asynccontextmanager
import hmac
import time

from fastapi import FastAPI, Header, HTTPException
from pydantic import BaseModel, Field

from app.config import INTERNAL_TOKEN
from app.face_service import FaceProcessingError, FaceService

_service: FaceService | None = None
_initialization_ms: float | None = None


@asynccontextmanager
async def lifespan(_: FastAPI):
    global _service, _initialization_ms
    started = time.perf_counter()
    _service = FaceService()
    _initialization_ms = round((time.perf_counter() - started) * 1000, 3)
    yield
    _service = None


app = FastAPI(title="RF V2 vision service", docs_url=None, redoc_url=None, lifespan=lifespan)


class ImageRequest(BaseModel):
    image: str = Field(min_length=32, max_length=7_200_000)


def authorize(token: str | None) -> None:
    if token is None or not hmac.compare_digest(INTERNAL_TOKEN, token):
        raise HTTPException(403, "Chamada interna nao autorizada.")


@app.get("/health")
def health():
    return {"status": "ok", "models_loaded": _service is not None}


@app.get("/warmup")
def warmup(x_internal_token: str | None = Header(default=None)):
    authorize(x_internal_token)
    return {"ready": _service is not None, "initialization_ms": _initialization_ms}


@app.post("/extract")
def extract(request: ImageRequest, x_internal_token: str | None = Header(default=None)):
    authorize(x_internal_token)
    if _service is None:
        raise HTTPException(503, "Modelos faciais indisponiveis.")
    try:
        embedding, metrics = _service.process_data_url(request.image)
        return {"embedding": embedding, "metrics": metrics}
    except FaceProcessingError as error:
        raise HTTPException(422, str(error)) from error
