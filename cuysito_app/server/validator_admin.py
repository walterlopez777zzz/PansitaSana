#!/usr/bin/env python3
# validator_admin.py
# Validate generated Fortnite section index + section files in cache directory.
# - Usage: validator_admin.py --cache-dir /path/to/cache [--json-output] [--schema path/schema.json] [--verbose]
# - Exit codes: 0 = OK (no critical errors), 1 = warnings only, 2 = critical errors
#
# Designed to be conservative: report problems but avoid modifying files.

from __future__ import annotations
import os
import sys
import json
import argparse
import logging
from typing import Any, Dict, List, Tuple, Optional
from urllib.parse import urlparse
from collections import defaultdict

# Optional dependency
try:
    import jsonschema
    JSONSCHEMA_AVAILABLE = True
except Exception:
    JSONSCHEMA_AVAILABLE = False

DEFAULT_INDEX_FILENAME = "section.index.json"

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s %(message)s",
    stream=sys.stdout
)
logger = logging.getLogger("validator_admin")

# Helper utils
def now_iso() -> str:
    from datetime import datetime, timezone
    return datetime.now(timezone.utc).isoformat()

def load_json_file(path: str) -> Tuple[Optional[Any], Optional[str]]:
    """
    Load JSON from path. Returns (obj, error_message)
    """
    if not os.path.exists(path):
        return None, f"missing:{path}"
    try:
        with open(path, "r", encoding="utf-8") as fh:
            data = json.load(fh)
        return data, None
    except json.JSONDecodeError as e:
        return None, f"json_error:{path}:{str(e)}"
    except Exception as e:
        return None, f"read_error:{path}:{str(e)}"

def is_probable_items_container(obj: Any) -> bool:
    """
    Heuristics to decide if a JSON object represents a section items file.
    Accepts if it has an 'items' array, or 'offers', or 'entries' (common variants).
    """
    if not isinstance(obj, dict):
        return False
    for key in ("items", "offers", "entries", "shop", "items_list"):
        if key in obj and isinstance(obj[key], list):
            return True
    # fallback: top-level is a list of items
    if isinstance(obj, list):
        return True
    return False

def resolve_items_local_path(items_url: str, cache_dir: str) -> Optional[str]:
    """
    Try to map items_url to a local file path under cache_dir using heuristics:
     - if items_url is absolute path and exists -> return it
     - if items_url is relative path -> join with cache_dir and check
     - if items_url looks like a full URL, try to extract trailing path pieces and
       search in cache_dir for matching filenames or within /sections/.
     - as fallback try basename under cache_dir or cache_dir/sections/
    Returns absolute path or None if not found.
    """
    # absolute path
    if os.path.isabs(items_url) and os.path.exists(items_url):
        return os.path.abspath(items_url)

    # join relative
    candidate = os.path.join(cache_dir, items_url)
    if os.path.exists(candidate):
        return os.path.abspath(candidate)

    # if URL, parse and try to extract path
    try:
        parsed = urlparse(items_url)
        if parsed.scheme in ("http", "https"):
            # try to find portion after "/cache/" if present
            p = parsed.path
            segments = p.strip("/").split("/")
            # try full path under cache_dir using last segments
            for take in ("/".join(segments[i:]) for i in range(len(segments))):
                if not take:
                    continue
                cand = os.path.join(cache_dir, take)
                if os.path.exists(cand):
                    return os.path.abspath(cand)
            # try basename
            base = os.path.basename(parsed.path)
            cand = os.path.join(cache_dir, base)
            if os.path.exists(cand):
                return os.path.abspath(cand)
            cand2 = os.path.join(cache_dir, "sections", base)
            if os.path.exists(cand2):
                return os.path.abspath(cand2)
    except Exception:
        pass

    # try basename naive
    base = os.path.basename(items_url)
    cand = os.path.join(cache_dir, base)
    if os.path.exists(cand):
        return os.path.abspath(cand)
    cand2 = os.path.join(cache_dir, "sections", base)
    if os.path.exists(cand2):
        return os.path.abspath(cand2)

    return None

def flatten_offer_ids_from_section_json(obj: Any) -> List[str]:
    """
    Try to extract offer identifiers from a section file using common keys.
    Returns list of strings (offerId or id).
    """
    ids = []
    if isinstance(obj, dict):
        # possible keys where items array lives
        for key in ("items", "offers", "entries", "shop", "items_list"):
            arr = obj.get(key)
            if isinstance(arr, list):
                for it in arr:
                    if isinstance(it, dict):
                        for candidate in ("offerId", "id", "mainId", "offer_id"):
                            if candidate in it:
                                ids.append(str(it[candidate]))
                                break
                        else:
                            # fallback: try 'name' if no id
                            if "name" in it:
                                ids.append("name:"+str(it["name"]))
                    else:
                        # primitive entry -> stringify
                        ids.append(str(it))
                if ids:
                    return ids
    elif isinstance(obj, list):
        for it in obj:
            if isinstance(it, dict):
                for candidate in ("offerId", "id", "mainId", "offer_id"):
                    if candidate in it:
                        ids.append(str(it[candidate]))
                        break
                else:
                    if "name" in it:
                        ids.append("name:"+str(it["name"]))
            else:
                ids.append(str(it))
        if ids:
            return ids
    return ids

def validate_index_structure(index_obj: Any) -> Tuple[bool, List[str]]:
    """
    Basic validations for section.index.json structure.
    Returns (ok, warnings)
    """
    warnings = []
    ok = True
    if not isinstance(index_obj, list):
        warnings.append("index_not_array")
        ok = False
        return ok, warnings
    # check each entry minimal fields
    ranks = []
    names = []
    seen_baseids = set()
    for i, entry in enumerate(index_obj):
        if not isinstance(entry, dict):
            warnings.append(f"index_entry_not_object_{i}")
            ok = False
            continue
        # name
        if "name" not in entry:
            warnings.append(f"entry_missing_name_{i}")
        else:
            names.append(str(entry.get("name")))
        # items_url or items_path
        if "items_url" not in entry and "items_path" not in entry and "baseId" not in entry:
            warnings.append(f"entry_missing_items_url_or_baseId_{i}")
            ok = False
        # rank
        if "rank" in entry:
            try:
                r = int(entry["rank"])
                ranks.append(r)
            except Exception:
                warnings.append(f"entry_rank_not_integer_{i}")
        # baseId uniqueness
        if "baseId" in entry:
            bid = str(entry["baseId"])
            if bid in seen_baseids:
                warnings.append(f"duplicate_baseId_{bid}")
            seen_baseids.add(bid)
    # check rank duplicates
    dup_ranks = [r for r in set(ranks) if ranks.count(r) > 1]
    if dup_ranks:
        warnings.append(f"duplicate_ranks:{','.join(map(str,dup_ranks))}")
    return ok, warnings

def main():
    parser = argparse.ArgumentParser(description="validator_admin.py - validate section.index.json and sections/*.json")
    parser.add_argument("--cache-dir", required=True, help="Directory where section.index.json and sections/ reside")
    parser.add_argument("--index-file", default=DEFAULT_INDEX_FILENAME, help="Index filename (default section.index.json)")
    parser.add_argument("--schema", default=None, help="Optional JSON Schema to validate section files (requires jsonschema)")
    parser.add_argument("--json-output", action="store_true", help="Emit machine-readable JSON summary to stdout")
    parser.add_argument("--verbose", action="store_true", help="Verbose logging")
    args = parser.parse_args()

    if args.verbose:
        logger.setLevel(logging.DEBUG)

    cache_dir = os.path.abspath(args.cache_dir)
    index_path = os.path.join(cache_dir, args.index_file)

    summary: Dict[str, Any] = {
        "ts": now_iso(),
        "cache_dir": cache_dir,
        "index_path": index_path,
        "errors": [],
        "warnings": [],
        "sections": [],
        "metrics": {}
    }

    # 1) load index
    index_obj, err = load_json_file(index_path)
    if err:
        summary["errors"].append({"what": "index_load", "detail": err})
        # fatal
        if args.json_output:
            print(json.dumps(summary, ensure_ascii=False, indent=2))
        logger.error("Cannot load index: %s", err)
        sys.exit(2)

    # optional schema validation for index
    if args.schema:
        if not JSONSCHEMA_AVAILABLE:
            summary["warnings"].append("jsonschema_not_available_for_schema_validation")
            logger.warning("jsonschema not available; skipping schema validation")
        else:
            try:
                with open(args.schema, "r", encoding="utf-8") as f:
                    schema_def = json.load(f)
                jsonschema.validate(instance=index_obj, schema=schema_def)
            except Exception as e:
                summary["errors"].append({"what": "index_schema_invalid", "detail": str(e)})
                logger.error("Index schema validation failed: %s", e)
                if args.json_output:
                    print(json.dumps(summary, ensure_ascii=False, indent=2))
                sys.exit(2)

    # basic structure checks
    ok_struct, struct_warnings = validate_index_structure(index_obj)
    summary["warnings"].extend(struct_warnings)
    if not ok_struct:
        summary["errors"].append({"what": "index_structure", "detail": "index structure failed basic checks"})
        logger.error("Index failed basic structure checks: %s", struct_warnings)
        if args.json_output:
            print(json.dumps(summary, ensure_ascii=False, indent=2))
        sys.exit(2)

    # iterate sections
    seen_ranks = defaultdict(list)
    global_offer_ids = {}
    total_sections = 0
    missing_section_files = []
    parse_errors = []
    total_items = 0

    for i, entry in enumerate(index_obj):
        total_sections += 1
        sec_summary: Dict[str, Any] = {"index": i, "name": entry.get("name"), "rank": entry.get("rank"), "items_url": entry.get("items_url") or entry.get("items_path") or None, "issues": []}
        items_url = sec_summary["items_url"]
        # if no items_url, attempt derive from baseId
        if not items_url and "baseId" in entry:
            # common convention: sections/<baseId>.json
            items_url = f"sections/{entry['baseId']}.json"
            sec_summary["items_url_derived"] = items_url

        if not items_url:
            sec_summary["issues"].append("no_items_url")
            summary["warnings"].append({"section_index": i, "what": "no_items_url"})
            summary["sections"].append(sec_summary)
            continue

        # resolve to local file
        local_path = resolve_items_local_path(items_url, cache_dir)
        if not local_path:
            sec_summary["issues"].append("items_file_missing")
            missing_section_files.append({"section_index": i, "items_url": items_url})
            summary["warnings"].append({"section_index": i, "what": "items_file_missing", "items_url": items_url})
            summary["sections"].append(sec_summary)
            continue

        # load section file
        sec_obj, sec_err = load_json_file(local_path)
        if sec_err:
            sec_summary["issues"].append("items_json_error")
            parse_errors.append({"path": local_path, "err": sec_err})
            summary["errors"].append({"what": "items_json_error", "path": local_path, "detail": sec_err})
            summary["sections"].append(sec_summary)
            continue

        # heuristic check: is items container
        if not is_probable_items_container(sec_obj):
            sec_summary["issues"].append("items_unexpected_structure")
            summary["warnings"].append({"section_index": i, "what": "items_unexpected_structure", "path": local_path})

        # collect offer ids for duplicate detection
        offer_ids = flatten_offer_ids_from_section_json(sec_obj)
        sec_summary["found_offer_count"] = len(offer_ids)
        total_items += len(offer_ids)
        sec_summary["offer_sample"] = offer_ids[:6]
        for oid in offer_ids:
            if oid in global_offer_ids:
                # record duplication
                prev = global_offer_ids[oid]
                summary["warnings"].append({"what": "duplicate_offerId", "offerId": oid, "previous_section": prev, "current_section": entry.get("name")})
            else:
                global_offer_ids[oid] = entry.get("name")
        summary["sections"].append(sec_summary)

    # metrics
    summary["metrics"]["sections_total"] = total_sections
    summary["metrics"]["items_total"] = total_items
    summary["metrics"]["offerId_unique_count"] = len(global_offer_ids)
    summary["metrics"]["missing_section_files"] = len(missing_section_files)
    summary["metrics"]["parse_errors"] = len(parse_errors)

    # Decide status
    status = "ready"
    if summary["errors"]:
        status = "error"
    elif summary["warnings"]:
        status = "warn"
    summary["status"] = status

    # Logging summary
    logger.info("Validation completed: status=%s sections=%d items=%d missing_files=%d parse_errors=%d",
                status, total_sections, total_items, len(missing_section_files), len(parse_errors))

    # Output JSON or human summary
    if args.json_output:
        print(json.dumps(summary, ensure_ascii=False, indent=2))
    else:
        # human readable
        print("Validation summary:", summary["status"])
        print(f" sections: {summary['metrics']['sections_total']}")
        print(f" items (approx): {summary['metrics']['items_total']}")
        print(f" unique offerIds: {summary['metrics']['offerId_unique_count']}")
        if summary["warnings"]:
            print(" WARNINGS:")
            for w in summary["warnings"][:50]:
                print("  -", w)
        if summary["errors"]:
            print(" ERRORS:")
            for e in summary["errors"][:50]:
                print("  -", e)

    # exit codes
    if summary["errors"]:
        sys.exit(2)
    elif summary["warnings"]:
        sys.exit(1)
    else:
        sys.exit(0)

if __name__ == "__main__":
    main()
