# sqlglot reference fixtures

These fixtures were recorded from [sqlglot](https://github.com/tobymao/sqlglot) v30.21.0 (MIT
licensed, see `LICENSE` in this directory) by `tools/sqlglot-reference/record.py`:

- `identity.json` — every line of sqlglot's `tests/fixtures/identity.sql` with the expected
  round-trip SQL, the reference AST and the reference tokens.
- `pretty.json` — the input/output pairs of `tests/fixtures/pretty.sql`.
- `dialects/<dialect>.json` — every `validate_identity`, `validate_all` and `validate_transpile`
  call made by sqlglot's own `tests/dialects/test_<dialect>.py`, with reference ASTs (and tokens
  for identity cases).

ASTs use a compact form: `{"k": key, "a": {arg: value}, "c": [comments]}` with null, false and
empty-list arguments dropped.

To re-record after bumping the pinned sqlglot version:

```bash
python -m venv .venv && .venv/bin/pip install sqlglot==<version> pytest pytz python-dateutil duckdb pandas
git clone --depth 1 --branch v<version> https://github.com/tobymao/sqlglot.git /tmp/sqlglot
.venv/bin/python -I tools/sqlglot-reference/record.py /tmp/sqlglot /tmp/reference
.venv/bin/python -I tools/sqlglot-reference/extract_specs.py tools/specs/sqlglot
```
