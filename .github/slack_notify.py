#!/usr/bin/env python3
"""배포 결과를 슬랙 수신 웹훅으로 보낸다.

슬랙에는 마크다운 표가 없어서, Block Kit 의 section fields 로 2열 배치를 만든다.
환경변수로만 동작하며 외부 의존성은 없다.

  SLACK_WEBHOOK_URL  (필수)
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


def esc(text: str) -> str:
    """슬랙 mrkdwn 에서 링크 문법으로 오해될 문자를 이스케이프한다."""
    return text.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")


def env(name: str, default: str = "") -> str:
    return (os.environ.get(name) or default).strip()


def build_payload() -> dict:
    ok = env("DEPLOY_STATUS", "success") == "success"
    server = env("GH_SERVER", "https://github.com").rstrip("/")
    repo = env("GH_REPO")
    ref = env("GH_REF")
    sha = env("GH_SHA")
    run_url = env("GH_RUN_URL")
    deploy_dir = env("DEPLOY_DIR")
    subject = env("DEPLOY_SUBJECT")
    author = env("DEPLOY_AUTHOR")

    title = f"{'✅ 배포 성공' if ok else '❌ 배포 실패'} · {SITE}"

    fields = [
        f"*저장소*\n<{server}/{repo}|{esc(repo)}>",
        f"*브랜치*\n<{server}/{repo}/tree/{ref}|{esc(ref)}>",
    ]
    if sha:
        # 링크 라벨 안에서는 백틱이 코드 서식으로 렌더링되지 않는다. 그냥 둔다.
        fields.append(f"*커밋*\n<{server}/{repo}/commit/{sha}|{sha[:7]}>")
    fields.append(f"*시각*\n{datetime.now(KST):%Y-%m-%d %H:%M} KST")

    blocks: list[dict] = [
        {"type": "header", "text": {"type": "plain_text", "text": title, "emoji": True}},
        {"type": "section", "fields": [{"type": "mrkdwn", "text": f} for f in fields]},
    ]

    if subject:
        line = esc(subject)
        if author:
            line += f"  —  {esc(author)}"
        blocks.append({"type": "section", "text": {"type": "mrkdwn", "text": line}})

    # 실패했을 때만 원본 진단을 붙인다. 성공 알림에 넣으면 위 필드와 중복된다.
    if not ok:
        info_file = env("DEPLOY_INFO_FILE")
        info = ""
        if info_file and os.path.isfile(info_file):
            with open(info_file, encoding="utf-8", errors="replace") as fh:
                info = fh.read().strip()
        if info:
            if len(info) > 2600:
                info = info[:2600] + "\n…(이하 생략)"
            blocks.append({"type": "divider"})
            blocks.append({
                "type": "section",
                "text": {"type": "mrkdwn", "text": f"*마지막으로 수집된 배포 정보*\n```{info}```"},
            })

    context = f"<{run_url}|Actions 로그 보기>" if run_url else ""
    if deploy_dir:
        context += f"{'  ·  ' if context else ''}`{esc(deploy_dir)}`"
    if context:
        blocks.append({"type": "context", "elements": [{"type": "mrkdwn", "text": context}]})

    # text 는 알림 목록·푸시에 쓰이는 대체 문구다.
    return {"text": title, "blocks": blocks}


def main() -> int:
    url = env("SLACK_WEBHOOK_URL")
    if not url:
        print("SLACK_WEBHOOK_URL 없음 — 알림 생략")
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
            print("slack:", res.read().decode("utf-8", "replace").strip())
    except urllib.error.HTTPError as e:
        # invalid_blocks · channel_not_found 등 슬랙이 돌려주는 사유를 로그에 남긴다.
        print("slack error:", e.code, e.read().decode("utf-8", "replace").strip())
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
