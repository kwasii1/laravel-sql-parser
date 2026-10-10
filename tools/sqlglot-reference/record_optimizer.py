"""Record sqlglot's optimizer fixture cases as JSON for the PHP test suite.

Dev-only. Run once per pinned sqlglot version, like record.py:

    python -I tools/sqlglot-reference/record_optimizer.py <sqlglot-checkout> <output-file>

Every `check_file()` call in sqlglot's tests/test_optimizer.py is captured instead of run: the
fixture file, the optimizer function (by qualified name), its keyword arguments (schemas with
their types as SQL) and, per fixture case, the input, metadata and the output sqlglot produces.
"""

from __future__ import annotations

import functools
import json
import os
import sys


def main(checkout: str, output: str) -> None:
    sys.path.insert(0, checkout)
    os.chdir(checkout)

    import unittest

    from sqlglot import exp, parse_one
    from sqlglot.schema import MappingSchema
    from tests import test_optimizer
    from tests.helpers import load_sql_fixture_pairs, string_to_bool

    def plain(value):
        if isinstance(value, exp.DataType):
            return {"@type": value.sql(), "nullable": value.args.get("nullable")}
        if isinstance(value, MappingSchema):
            return {"@schema": plain(value.mapping), "visible": {k: sorted(v) for k, v in (value.visible or {}).items()}, "dialect": type(value.dialect).__name__.lower() if value.dialect else None}
        if isinstance(value, dict):
            return {str(k): plain(v) for k, v in value.items()}
        if isinstance(value, (list, tuple)):
            return [plain(v) for v in value]
        if isinstance(value, functools.partial):
            return {"@partial": plain(value.func), "kwargs": plain(value.keywords)}
        if callable(value):
            return f"{value.__module__}.{value.__qualname__}"
        return value

    files = []

    def check_file(self, file, func, pretty=False, execute=None, only=None, **kwargs):
        cases = []
        for i, (meta, sql, expected) in enumerate(load_sql_fixture_pairs(f"optimizer/{file}.sql"), start=1):
            dialect = meta.get("dialect")
            func_kwargs = dict(kwargs)
            if schema := meta.get("schema"):
                func_kwargs["schema"] = json.loads(schema)
            for key in ("leave_tables_isolated", "validate_qualify_columns", "canonicalize_table_aliases"):
                if meta.get(key) is not None:
                    func_kwargs[key] = string_to_bool(meta[key])
            if dialect:
                func_kwargs["dialect"] = dialect

            try:
                actual = func(parse_one(sql, read=dialect), **func_kwargs).sql(pretty=pretty, dialect=dialect)
            except Exception as e:  # recorded so the PHP side can expect the same failure
                actual = f"!{type(e).__name__}: {e}"

            cases.append(
                {
                    "title": meta.get("title") or f"{i}, {sql}",
                    "sql": sql,
                    "expected": expected,
                    "actual": actual,
                    "dialect": dialect,
                    "kwargs": plain({k: v for k, v in func_kwargs.items() if k not in kwargs or v is not kwargs[k]}),
                }
            )

        files.append(
            {
                "file": file,
                "function": plain(func),
                "pretty": pretty,
                "kwargs": plain(kwargs),
                "cases": cases,
            }
        )

    test_optimizer.TestOptimizer.check_file = check_file
    suite = unittest.defaultTestLoader.loadTestsFromTestCase(test_optimizer.TestOptimizer)
    unittest.TextTestRunner(stream=open(os.devnull, "w"), verbosity=0).run(suite)

    with open(output, "w") as f:
        json.dump({"files": files}, f, indent=1, ensure_ascii=False)
        f.write("\n")

    print(f"{len(files)} fixture files, {sum(len(f['cases']) for f in files)} cases")


if __name__ == "__main__":
    main(*sys.argv[1:3])
