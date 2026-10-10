"""Extract sqlglot's declarative tables into JSON specs for the PHP code generators.

Dev-only. Run once per pinned sqlglot version:

    python -I tools/sqlglot-reference/extract_specs.py <output-dir>

Writes:
    token_types.json        TokenType names in declaration order (value = position + 1)
    expressions.json        every Expression class: parent, mixins, arg types, SQL names, flags
    dialects/<name>.json    Dialect / Tokenizer / Parser / Generator class attributes; every
                            dialect except `base` stores only the attributes that differ from
                            its parent dialect
    methods.json            methods each dialect's Parser / Generator class defines itself, so
                            helper functions can be renamed when they'd clash with one (PHP
                            method names are case-insensitive)

Callables (parser lambdas, generator transforms, function builders) can't be ported
automatically, so they're described by qualified name, closure values and source so
the hand-written PHP side can be checked against them.
"""

from __future__ import annotations

import enum
import inspect
import json
import os
import sys
import textwrap

EXPRESSION_MODULES = [
    "core",
    "datatypes",
    "query",
    "ddl",
    "dml",
    "properties",
    "constraints",
    "math",
    "string",
    "temporal",
    "aggregate",
    "array",
    "json",
    "functions",
]


def expression_classes():
    from sqlglot import exp

    classes = {}
    for module in EXPRESSION_MODULES:
        mod = __import__(f"sqlglot.expressions.{module}", fromlist=["_"])
        for name, obj in vars(mod).items():
            if inspect.isclass(obj) and issubclass(obj, exp.Expr) and obj.__module__ == mod.__name__:
                classes[name] = (module, obj)
    return classes


def ser(value, depth=0):
    """Serialize a class attribute into tagged JSON that keeps key and value types."""
    from sqlglot import exp
    from sqlglot.tokenizer_core import TokenType
    from sqlglot.expressions.datatypes import DType

    # Enums first: TokenType is an IntEnum, so it would otherwise pass as a plain int.
    if isinstance(value, TokenType):
        return {"@token": value.name}
    if isinstance(value, DType):
        return {"@dtype": value.name}
    if isinstance(value, enum.Enum):
        return {"@enum": f"{type(value).__name__}.{value.name}"}
    if value is None or isinstance(value, (bool, int, float, str)):
        return value
    if inspect.isclass(value) and issubclass(value, exp.Expr):
        return {"@exp": value.__name__}
    if isinstance(value, dict):
        entries = [[ser(k, depth + 1), ser(v, depth + 1)] for k, v in value.items()]
        # Maps keyed by expression classes are often built from sets, so their order isn't stable
        for tag in ("@exp", "@dtype"):
            if entries and all(isinstance(k, dict) and tag in k for k, _ in entries):
                entries.sort(key=lambda e: e[0][tag])
        return {"@map": entries}
    if isinstance(value, (set, frozenset)):
        items = [ser(v, depth + 1) for v in value]
        return {"@set": sorted(items, key=lambda x: json.dumps(x, sort_keys=True))}
    if isinstance(value, (list, tuple)):
        return [ser(v, depth + 1) for v in value]
    if callable(value):
        return describe_callable(value)
    return {"@repr": repr(value)}


def describe_callable(fn):
    import functools

    from sqlglot import exp

    if isinstance(fn, functools.partial):
        # e.g. functools.partial(transforms.unnest_to_explode, unnest_using_arrays_zip=False)
        out = describe_callable(fn.func)
        out["partial"] = {"args": [ser(a) for a in fn.args], "kwargs": {k: ser(v) for k, v in fn.keywords.items()}}
        return out

    target = getattr(fn, "__func__", fn)
    owner = getattr(fn, "__self__", None)
    out = {"@fn": getattr(target, "__qualname__", repr(target)), "module": getattr(target, "__module__", None)}

    if inspect.isclass(owner) and issubclass(owner, exp.Expr):
        out["bound"] = owner.__name__

    closure = getattr(target, "__closure__", None)
    code = getattr(target, "__code__", None)
    if closure and code:
        values = {}
        for name, cell in zip(code.co_freevars, closure):
            try:
                contents = cell.cell_contents
            except ValueError:
                continue
            if callable(contents) and not inspect.isclass(contents):
                values[name] = describe_callable(contents)
            else:
                try:
                    values[name] = ser(contents)
                except Exception:  # noqa: BLE001
                    values[name] = {"@repr": repr(contents)}
        if values:
            out["closure"] = values

    if out["@fn"].endswith("<lambda>"):
        try:
            source = textwrap.dedent(inspect.getsource(target)).strip()
            out["source"] = source[:400]
        except (OSError, TypeError):
            pass

    return out


def class_attributes(cls):
    attrs = {}
    for name in sorted(dir(cls)):
        if not name.isupper() or name.startswith("_"):
            continue
        try:
            value = getattr(cls, name)
        except Exception:  # noqa: BLE001
            continue
        if inspect.isfunction(value) or inspect.ismethod(value) or isinstance(value, property):
            continue
        attrs[name] = ser(value)
    return attrs


def private_tokenizer_attributes(cls):
    names = [
        "_QUOTES",
        "_IDENTIFIERS",
        "_FORMAT_STRINGS",
        "_STRING_ESCAPES",
        "_BYTE_STRING_ESCAPES",
        "_ESCAPE_FOLLOW_CHARS",
        "_IDENTIFIER_ESCAPES",
        "_COMMENTS",
        "_KEYWORD_TRIE",
    ]
    return {name: ser(getattr(cls, name)) for name in names if hasattr(cls, name)}


def extract_token_types():
    from sqlglot.tokenizer_core import TokenType

    members = sorted(TokenType, key=lambda t: t.value)
    for position, member in enumerate(members, start=1):
        assert member.value == position, member
    return [m.name for m in members]


def extract_dtypes():
    from sqlglot.expressions.datatypes import DType

    return [[member.name, member.value] for member in DType]


def extract_expressions():
    from sqlglot import exp

    classes = expression_classes()
    out = []
    for name, (module, cls) in classes.items():
        bases = [b.__name__ for b in cls.__bases__ if b is not object]
        is_mixin = not issubclass(cls, exp.Expression)
        spec = {
            "name": name,
            "key": cls.key,
            "module": module,
            "mixin": is_mixin,
            "bases": bases,
            "own_arg_types": "arg_types" in vars(cls),
            "arg_types": [[k, v] for k, v in cls.arg_types.items()],
            "is_var_len_args": cls.is_var_len_args,
            "var_len_arg_key": cls.var_len_arg_key,
            "is_primitive": cls.is_primitive,
            "hash_raw_args": cls._hash_raw_args,
            "is_subquery": cls.is_subquery,
            "is_cast": cls.is_cast,
            "is_data_type": cls.is_data_type,
            "is_func": issubclass(cls, exp.Func) and not is_mixin,
            "is_agg": issubclass(cls, exp.AggFunc) and not is_mixin,
            "doc": (inspect.getdoc(cls) or "").split("\n\n")[0][:300],
        }
        if spec["is_func"]:
            spec["sql_names"] = cls.sql_names()
            spec["own_sql_names"] = "_sql_names" in vars(cls)
        constants = {}
        for attr, value in vars(cls).items():
            if attr.isupper() and not attr.startswith("_"):
                constants[attr] = ser(value)
        if constants:
            spec["constants"] = constants
        out.append(spec)
    return out


def dialect_parent(cls):
    from sqlglot.dialects.dialect import Dialect

    for base in cls.__mro__[1:]:
        if issubclass(base, Dialect) and base is not cls:
            return base
    return None


def dialect_name(cls):
    from sqlglot.dialects.dialect import Dialect

    for name, klass in Dialect.classes.items():
        if klass is cls:
            return name or "base"
    return cls.__name__.lower()


def extract_dialects():
    import sqlglot.dialects as dialects
    from sqlglot.dialects.dialect import Dialect

    for name in dialects.DIALECTS:
        getattr(dialects, name)

    out = {}
    for key, cls in sorted(Dialect.classes.items()):
        name = key or "base"
        parent = dialect_parent(cls)
        out[name] = {
            "name": name,
            "class": cls.__name__,
            "parent": dialect_name(parent) if parent is not None else None,
            "settings": class_attributes(cls),
            "tokenizer": {
                "class": cls.tokenizer_class.__qualname__,
                **class_attributes(cls.tokenizer_class),
                **private_tokenizer_attributes(cls.tokenizer_class),
            },
            "jsonpath_tokenizer": {
                "class": cls.jsonpath_tokenizer_class.__qualname__,
                **class_attributes(cls.jsonpath_tokenizer_class),
                **private_tokenizer_attributes(cls.jsonpath_tokenizer_class),
            },
            "parser": {"class": cls.parser_class.__qualname__, **class_attributes(cls.parser_class)},
            "generator": {"class": cls.generator_class.__qualname__, **class_attributes(cls.generator_class)},
        }
    return out


def extract_methods():
    import sqlglot.dialects as dialects
    from sqlglot.dialects.dialect import Dialect

    for name in dialects.DIALECTS:
        getattr(dialects, name)

    def own(cls, base):
        # The base dialect's classes include everything they inherit (e.g. BaseParser -> Parser)
        classes = [c for c in cls.__mro__ if c is not object] if base else [cls]
        return sorted({n for c in classes for n, v in vars(c).items() if inspect.isfunction(v)})

    return {
        key or "base": {
            "parser": own(cls.parser_class, not key),
            "generator": own(cls.generator_class, not key),
        }
        for key, cls in sorted(Dialect.classes.items())
    }


SECTIONS = ("settings", "tokenizer", "jsonpath_tokenizer", "parser", "generator")


def diff_value(base, value):
    """Describe a changed map as a patch ({"@patch": [[k, v]...], "@delete": [k...]})."""
    if isinstance(base, dict) and isinstance(value, dict) and "@map" in base and "@map" in value:
        key = lambda k: json.dumps(k, sort_keys=True)  # noqa: E731
        old = {key(k): v for k, v in base["@map"]}
        new = {key(k): (k, v) for k, v in value["@map"]}
        # Some maps are ordered (e.g. AFTER_HAVING_MODIFIER_TRANSFORMS): a patch can't reorder keys
        common_old = [kk for kk in old if kk in new]
        common_new = [kk for kk in new if kk in old]
        if common_old != common_new:
            return value
        patch = [[k, v] for kk, (k, v) in new.items() if kk not in old or old[kk] != v]
        delete = [json.loads(kk) for kk in old if kk not in new]
        out = {"@patch": patch}
        if delete:
            out["@delete"] = delete
        return out
    if isinstance(base, dict) and isinstance(value, dict) and "@set" in base and "@set" in value:
        key = lambda k: json.dumps(k, sort_keys=True)  # noqa: E731
        old = {key(v) for v in base["@set"]}
        new = {key(v) for v in value["@set"]}
        return {
            "@add": [json.loads(v) for v in sorted(new - old)],
            "@remove": [json.loads(v) for v in sorted(old - new)],
        }
    return value


def diff_against_parent(dialects):
    """Keep only the attributes that differ from the parent dialect's effective values."""
    out = {}
    for name, spec in dialects.items():
        parent = dialects.get(spec["parent"]) if spec["parent"] else None
        if parent is None:
            out[name] = spec
            continue
        reduced = {k: v for k, v in spec.items() if k not in SECTIONS}
        for section in SECTIONS:
            reduced[section] = {}
            for key, value in spec[section].items():
                base = parent[section].get(key)
                if key == "class":
                    reduced[section][key] = value
                elif base != value:
                    reduced[section][key] = diff_value(base, value)
            removed = sorted(set(parent[section]) - set(spec[section]))
            if removed:
                reduced[section]["@removed"] = removed
        out[name] = reduced
    return out


def write_json(path, data):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8", newline="\n") as f:
        json.dump(data, f, ensure_ascii=False, separators=(",", ":"))
        f.write("\n")


def main():
    # sqlglot builds some tables by iterating sets; pin string hashing so the specs are reproducible.
    # (-I would ignore PYTHONHASHSEED, so re-exec with the equivalent -E -s -P flags instead.)
    if os.environ.get("PYTHONHASHSEED") != "0":
        env = {k: v for k, v in os.environ.items() if not k.startswith("PYTHON")}
        env["PYTHONHASHSEED"] = "0"
        os.execve(sys.executable, [sys.executable, "-s", "-P", *sys.argv], env)

    if len(sys.argv) != 2:
        print(__doc__, file=sys.stderr)
        return 2

    out = os.path.abspath(sys.argv[1])

    import sqlglot

    meta = {"sqlglot": sqlglot.__version__}
    write_json(os.path.join(out, "token_types.json"), {"meta": meta, "token_types": extract_token_types()})
    write_json(
        os.path.join(out, "expressions.json"),
        {"meta": meta, "dtypes": extract_dtypes(), "expressions": extract_expressions()},
    )
    write_json(os.path.join(out, "methods.json"), {"meta": meta, "methods": extract_methods()})
    for name, spec in diff_against_parent(extract_dialects()).items():
        write_json(os.path.join(out, "dialects", f"{name}.json"), {"meta": meta, **spec})

    print(f"sqlglot {sqlglot.__version__}: specs written to {out}", file=sys.stderr)
    return 0


if __name__ == "__main__":
    sys.exit(main())
