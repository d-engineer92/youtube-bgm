"""Validate a synthetic test-DB export read from stdin, never live data."""
import json
import pathlib
import sys
import jsonschema
schema = json.loads(pathlib.Path('config/schemas/tracking-review-v1.schema.json').read_text())
jsonschema.Draft202012Validator.check_schema(schema)
jsonschema.validate(json.load(sys.stdin), schema)
print('PASS: synthetic export matches tracking-review-v1 JSON Schema')
