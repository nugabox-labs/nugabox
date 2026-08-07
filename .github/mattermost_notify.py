#!/usr/bin/env python3
"""배포 결과를 Mattermost 수신 웹훅으로 보낸다.

Mattermost 기본 마크다운은 <details>/<summary> 같은 raw HTML을 렌더링하지 않고
그대로 텍스트로 보여준다 — 접이식 대신 표 + 코드블록으로 표시한다.
환경변수로만 동작하며 외부 의존성은 없다.

  MATTERMOST_WEBHOOK_URL  (필수)
  DEPLOY_STATUS      success | failure
  GH_SERVER GH_REPO GH_REF GH_SHA GH_RUN_URL
  DEPLOY_DIR DEPLOY_SUBJECT DEPLOY_AUTHOR
  DEPLOY_INFO_FILE   실패 알림에 덧붙일 진단 파일 (선택)
"""
from __future__ import annotations

import json
import os
import sys
import urllib.error
import urllib.request
from datetime import datetime, timedelta, timezone

KST = timezone(timedelta(hours=9))
SITE = "nugabox.github.io"

STATUS_TITLES = {
    "success": ("✅", "성공"),
    "failure": ("❌", "실패"),
    "cancelled": ("⚠️", "취소"),
}


def env(name: str, default: str = "") -> str:
    return (os.environ.get(name) or default).strip()


def build_payload() -> dict:
    status = env("DEPLOY_STATUS", "unknown")
    emoji, status_word = STATUS_TITLES.get(status, ("⚠️", status))

    server = env("GH_SERVER", "https://github.com").rstrip("/")
    repo = env("GH_REPO")
    ref = env("GH_REF")
    sha = env("GH_SHA")
    short_sha = sha[:12] if sha else ""
    run_url = env("GH_RUN_URL")
    deploy_dir = env("DEPLOY_DIR")
    subject = env("DEPLOY_SUBJECT")
    author = env("DEPLOY_AUTHOR")
    now_kst = datetime.now(KST).strftime("%Y-%m-%d %H:%M")

    lines = [
        f"#### {emoji} {SITE} 배포 {status_word}",
        "",
        "| 구분 | 내용 |",
        "|---|---|",
        f"| 저장소 | [{repo}]({server}/{repo}) |",
        f"| 브랜치 | [{ref}]({server}/{repo}/tree/{ref}) |",
    ]
    if sha:
        lines.append(f"| 커밋 | [{short_sha}]({server}/{repo}/commit/{sha}) |")
    if subject:
        lines.append(f"| 커밋 제목 | {subject} |")
    if author:
        lines.append(f"| 작성자 | {author} |")
    if run_url:
        lines.append(f"| 워크플로 | [실행 로그]({run_url}) |")

    info_lines = [f"Timestamp (KST): {now_kst}"]
    if deploy_dir:
        info_lines.append(f"Deploy dir: {deploy_dir}")
    lines += ["", "**배포 정보**", "```", *info_lines, "```"]

    if status == "failure":
        info_file = env("DEPLOY_INFO_FILE")
        diag = ""
        if info_file and os.path.isfile(info_file):
            with open(info_file, encoding="utf-8", errors="replace") as fh:
                diag = fh.read().strip()
        if diag:
            lines += ["", "**진단 정보**", "```", diag[:3000], "```"]

    return {"text": "\n".join(lines)}


def main() -> int:
    url = env("MATTERMOST_WEBHOOK_URL")
    if not url:
        print("MATTERMOST_WEBHOOK_URL 없음 — 알림 생략")
        return 0

    payload = build_payload()
    req = urllib.request.Request(
        url,
        data=json.dumps(payload, ensure_ascii=False).encode("utf-8"),
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=45) as res:
            print("mattermost:", res.read().decode("utf-8", "replace").strip())
    except urllib.error.HTTPError as e:
        print("mattermost error:", e.code, e.read().decode("utf-8", "replace").strip())
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
