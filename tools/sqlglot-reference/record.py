"""Record sqlglot reference outputs as JSON fixtures for the PHP test suite.

Dev-only. Run once per pinned sqlglot version:

    python -I tools/sqlglot-reference/record.py <sqlglot-checkout> <output-dir>

The checkout must be the git tag matching the installed `sqlglot` package. The
script patches the upstream `Validator` helpers, runs sqlglot's own dialect
tests and records every `validate_identity` / `validate_all` /
`validate_transpile` call together with the canonical AST of each input. It
also records tokens, ASTs and outputs for `identity.sql` and `pretty.sql`.
"""

from __future__ import annotations

import contextlib
import enum
import json
import logging
import os
import sys
import traceback

UNSUPPORTED = "__UNSUPPORTED__"


def tree(value):
    """Canonical, comparison-friendly representation of an AST value."""
    from sqlglot import exp
    from sqlglot.dialects.dialect import Dialect

    if isinstance(value, exp.Expr):
        node = {"k": value.key}
        args = {}
        for key, arg in value.args.items():
            if arg is None or arg is False or (isinstance(arg, list) and not arg):
                continue
            if isinstance(arg, Dialect):
                # e.g. the dialect a DataType was built with: not part of the tree
                continue
            args[key] = tree(arg)
        if args:
            node["a"] = args
        if value.comments:
            node["c"] = list(value.comments)
        return node
    if isinstance(value, list):
        return [tree(v) for v in value]
    if isinstance(value, enum.Enum):
        return value.name
    if isinstance(value, (str, bool, int, float)):
        return value
    return str(value)


@contextlib.contextmanager
def quiet():
    """Silence sqlglot's loggers so recording never changes what a test observes."""
    logging.disable(logging.CRITICAL)
    try:
        yield
    finally:
        logging.disable(logging.NOTSET)


def safe_tree(sql, dialect):
    import sqlglot

    try:
        with quiet():
            return tree(sqlglot.parse_one(sql, read=dialect))
    except Exception:  # noqa: BLE001 - reference data, best effort
        return None


def tokens(sql, dialect=None):
    import sqlglot

    try:
        with quiet():
            return [[t.token_type.name, t.text] for t in sqlglot.tokenize(sql, read=dialect)]
    except Exception:  # noqa: BLE001
        return None


class Recorder:
    def __init__(self):
        self.cases: dict[str, list[dict]] = {}

    def add(self, dialect, case):
        self.cases.setdefault(dialect or "", []).append(case)


def install(recorder):
    from sqlglot import UnsupportedError
    from tests.dialects import test_dialect

    validator = test_dialect.Validator
    original_identity = validator.validate_identity
    original_all = validator.validate_all
    original_transpile = validator.validate_transpile

    def validate_identity(self, sql, write_sql=None, pretty=False, check_command_warning=False, identify=False):
        case = {
            "type": "identity",
            "test": self.id(),
            "sql": sql,
            "expected": write_sql or sql,
            "pretty": pretty,
            "identify": identify,
            "command": check_command_warning,
        }
        try:
            result = original_identity(self, sql, write_sql, pretty, check_command_warning, identify)
            case["ok"] = True
        except Exception:
            case["ok"] = False
            raise
        finally:
            case["ast"] = safe_tree(sql, self.dialect)
            case["tokens"] = tokens(sql, self.dialect)
            recorder.add(self.dialect, case)
        return result

    def validate_all(self, sql, read=None, write=None, pretty=False, identify=False):
        case = {
            "type": "all",
            "test": self.id(),
            "sql": sql,
            "read": dict(read or {}),
            "write": {
                d: (UNSUPPORTED if w is UnsupportedError else w) for d, w in (write or {}).items()
            },
            "pretty": pretty,
            "identify": identify,
        }
        try:
            result = original_all(self, sql, read, write, pretty, identify)
            case["ok"] = True
        except Exception:
            case["ok"] = False
            raise
        finally:
            case["ast"] = safe_tree(sql, self.dialect)
            recorder.add(self.dialect, case)
        return result

    def validate_transpile(self, sql, write_sql, write_dialect=None):
        case = {
            "type": "transpile",
            "test": self.id(),
            "sql": sql,
            "expected": write_sql,
            "write_dialect": write_dialect or "",
        }
        try:
            result = original_transpile(self, sql, write_sql, write_dialect)
            case["ok"] = True
        except Exception:
            case["ok"] = False
            raise
        finally:
            recorder.add(self.dialect, case)
        return result

    validator.validate_identity = validate_identity
    validator.validate_all = validate_all
    validator.validate_transpile = validate_transpile


def load_lines(path):
    with open(path, encoding="utf-8") as f:
        return [
            line.strip()
            for line in f
            if line.strip() and not line.strip().startswith("--")
        ]


def load_pairs(path):
    with open(path, encoding="utf-8") as f:
        statements = [s.strip() for s in f.read().split(";") if s.strip()]
    return list(zip(statements[0::2], statements[1::2]))


def record_fixtures(checkout, out):
    import sqlglot

    identity = []
    for sql in load_lines(os.path.join(checkout, "tests/fixtures/identity.sql")):
        try:
            generated = sqlglot.transpile(sql)[0]
        except Exception as error:  # noqa: BLE001
            generated = None
            print(f"identity failed upstream: {sql!r}: {error}", file=sys.stderr)
        identity.append(
            {"sql": sql, "expected": generated, "ast": safe_tree(sql, None), "tokens": tokens(sql)}
        )

    pretty = []
    for sql, expected in load_pairs(os.path.join(checkout, "tests/fixtures/pretty.sql")):
        pretty.append({"sql": sql, "expected": expected, "ast": safe_tree(sql, None)})

    write_json(os.path.join(out, "identity.json"), identity)
    write_json(os.path.join(out, "pretty.json"), pretty)


def write_json(path, data):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8", newline="\n") as f:
        json.dump(data, f, ensure_ascii=False, indent=None, separators=(",", ":"))
        f.write("\n")


def main():
    if len(sys.argv) != 3:
        print(__doc__, file=sys.stderr)
        return 2

    checkout = os.path.abspath(sys.argv[1])
    out = os.path.abspath(sys.argv[2])
    # Append (not prepend) so the installed, versioned `sqlglot` package wins
    # over the checkout's source tree while `tests` stays importable.
    sys.path.append(checkout)

    import sqlglot
    import pytest

    print(f"sqlglot {sqlglot.__version__} from {sqlglot.__file__}", file=sys.stderr)

    recorder = Recorder()
    install(recorder)

    pytest.main(
        [
            os.path.join(checkout, "tests/dialects"),
            "-q",
            "-p",
            "no:cacheprovider",
            "--import-mode=append",
            "--rootdir",
            checkout,
        ]
    )

    meta = {"sqlglot": sqlglot.__version__}
    for dialect, cases in sorted(recorder.cases.items()):
        passing = [c for c in cases if c.get("ok")]
        name = dialect if isinstance(dialect, str) and dialect else "base"
        if not isinstance(dialect, str):
            name = str(dialect)
        write_json(os.path.join(out, "dialects", f"{name}.json"), {"meta": meta, "cases": passing})
        print(f"{name}: {len(passing)} cases ({len(cases) - len(passing)} failing upstream)", file=sys.stderr)

    try:
        record_fixtures(checkout, out)
    except Exception:  # noqa: BLE001
        traceback.print_exc()
        return 1

    return 0


if __name__ == "__main__":
    sys.exit(main())
