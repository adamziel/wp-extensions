#!/usr/bin/env python3
"""Optional Lucene-IDF BM25 reference check using bm25s.

This script is intentionally separate from tests/run.php so the normal PHP suite
does not require Python packages. It compares a fixed tokenized corpus against the
same Lucene-style IDF formula used by the PHP engine.
"""

from __future__ import annotations

import importlib.util
import json
import math
import sys
from collections import Counter
from typing import Iterable


CORPUS = [
    ["apple", "banana", "cafe"],
    ["banana", "carrot", "carrot"],
    ["durian", "apple"],
    ["apple", "carrot"],
]
DOC_IDS = [101, 202, 303, 404]
QUERIES = {
    "apple": ["apple"],
    "carrot apple": ["carrot", "apple"],
    "banana missing": ["banana", "missing"],
}
K1 = 1.2
B = 0.75
EPSILON = 1e-5


def local_bm25_scores(query_tokens: Iterable[str]) -> dict[int, float]:
    doc_lens = [len(doc) for doc in CORPUS]
    avgdl = sum(doc_lens) / len(doc_lens)
    doc_counts = [Counter(doc) for doc in CORPUS]
    scores: dict[int, float] = {}

    for term in query_tokens:
        df = sum(1 for counts in doc_counts if counts[term] > 0)
        if df == 0:
            continue

        idf = math.log(1.0 + ((len(CORPUS) - df + 0.5) / (df + 0.5)))
        for i, counts in enumerate(doc_counts):
            tf = counts[term]
            if tf == 0:
                continue

            normalizer = tf + K1 * (1.0 - B + B * (doc_lens[i] / max(1.0, avgdl)))
            scores[DOC_IDS[i]] = scores.get(DOC_IDS[i], 0.0) + idf * ((tf * (K1 + 1.0)) / normalizer)

    return scores


def bm25s_scores(query_tokens: list[str]) -> dict[int, float]:
    import bm25s  # type: ignore[import-not-found]

    retriever = bm25s.BM25(method="atire", idf_method="lucene", k1=K1, b=B)
    retriever.index(CORPUS)

    try:
        docs, scores = retriever.retrieve([query_tokens], corpus=DOC_IDS, k=len(CORPUS))
        docs_are_final_ids = True
    except TypeError:
        docs, scores = retriever.retrieve([query_tokens], k=len(CORPUS))
        docs_are_final_ids = False

    result: dict[int, float] = {}
    for raw_doc, raw_score in zip(docs[0], scores[0]):
        score = float(raw_score)
        if score <= 0.0:
            continue

        doc = int(raw_doc)
        doc_id = doc if docs_are_final_ids else DOC_IDS[doc]
        result[doc_id] = score

    return result


def main() -> int:
    if importlib.util.find_spec("bm25s") is None:
        print("Optional dependency bm25s is not installed; install it to run this reference harness.", file=sys.stderr)
        return 2

    report = {}
    max_delta = 0.0
    for name, query_tokens in QUERIES.items():
        expected = local_bm25_scores(query_tokens)
        actual = bm25s_scores(query_tokens)
        all_doc_ids = sorted(set(expected) | set(actual))
        deltas = {
            str(doc_id): abs(expected.get(doc_id, 0.0) - actual.get(doc_id, 0.0))
            for doc_id in all_doc_ids
        }
        max_delta = max(max_delta, *(deltas.values() or [0.0]))
        report[name] = {
            "expected": {str(k): v for k, v in sorted(expected.items())},
            "actual": {str(k): v for k, v in sorted(actual.items())},
            "delta": deltas,
        }

    print(json.dumps({"max_delta": max_delta, "queries": report}, indent=2, sort_keys=True))
    return 0 if max_delta <= EPSILON else 1


if __name__ == "__main__":
    raise SystemExit(main())
