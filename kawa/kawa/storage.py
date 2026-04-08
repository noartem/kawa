from copy import deepcopy
from typing import Any, final

from pydash import get as pydash_get
from pydash import set_ as pydash_set
from pydash import unset as pydash_unset


_MISSING = object()


@final
class ContextStorage:
    def __init__(self, data: Any):
        self._data = data if isinstance(data, (dict, list)) else {}

    def get(self, key: str, default: Any = None) -> Any:
        resolved = pydash_get(self._data, key, _MISSING)
        if resolved is _MISSING:
            return default

        return deepcopy(resolved)

    def set(self, key: str, value: Any) -> None:
        pydash_set(self._data, key, deepcopy(value))

    def delete(self, key: str) -> None:
        pydash_unset(self._data, key)
