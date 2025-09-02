from fastapi import HTTPException

def bad_request(detail: str) -> HTTPException:
    return HTTPException(status_code=400, detail=detail)

def not_found(detail: str) -> HTTPException:
    return HTTPException(status_code=404, detail=detail)
