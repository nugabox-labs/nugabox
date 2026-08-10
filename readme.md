<div align="center">

# NUGABOX

🧑‍💻 Made by NUGA

</div>

## 로컬에서 실행

PHP 사이트입니다. 정적 서버로는 첫 화면과 리다이렉트가 동작하지 않습니다.

```bash
php -S localhost:1000
```

PHP 가 없으면 도커로도 됩니다.

```bash
docker run --rm -v "$PWD":/app -w /app -p 1000:1000 php:8.2-cli php -S 0.0.0.0:1000
```

## 구조

| 파일 | 설명 |
|------|------|
| `index.php` | 첫 화면. 앱 아이콘은 `data/icons.json` 에서 읽어 그립니다. |
| `include/site.php` | 데이터 읽기 · 렌더링 공용 코어 |
| `_redirect.php` | `/blog` 처럼 바깥으로 보내는 주소를 한 곳에서 처리 |
| `data/*.json` | 아이콘 배치 · 주소 연결 · 업데이트 날짜 |
| `admin/` | 관리자 페이지 ([설정 방법](admin/README.md)) |

아이콘과 주소 연결은 `/admin` 에서 고치는 것이 정석입니다. 저장하면 JSON 을 쓰고
곧바로 커밋 · 푸시까지 합니다. JSON 을 직접 고쳐 커밋해도 똑같이 동작합니다.

## 보조 프로그램

`dev.nugabox.com/pg/` 하위에 있던 페이지를 `nugabox.com/` 서브폴더로 이전했습니다.

| 경로 | URL | 설명 |
|------|-----|------|
| `search/` | https://search.nugabox.com (겸용: /search) | 가벼운 네이버 검색 포털 |
| `tools/` | https://nugabox.com/tools | 장인의 도구 (일반/개발/메시지 유틸) |
| `clock/` | https://nugabox.com/clock | 아날로그 시계 (라이트/다크 테마) |
| `error/` | https://nugabox.com/error | Windows BSOD 스타일 오류 페이지 |
| `cyworld/` | https://nugabox.com/cyworld | 싸이월드 미니홈피 스타일 페이지 |
| `upload/` | https://nugabox.com/upload/ | 공개 파일 |

### tools 하위

- `/tools` — 일반 도구 (경로 변환, 한글 변환, 이메일 서명 등)
- `/tools/dev` — 개발 도구 (인코딩, Base64, Punycode 등)
- `/tools/message` — 메시지 포맷 도구 (카카오톡/카카오워크/Slack)
