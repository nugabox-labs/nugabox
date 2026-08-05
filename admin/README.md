# NUGABOX 관리자 (`/admin`)

`upload/` 폴더의 파일을 웹에서 업로드 · 이름변경 · 삭제하고, 그 결과를 곧바로
git 에 커밋 · 푸시해 **서버에 올라간 내용과 저장소를 항상 같은 상태로 유지**합니다.

- 화면 스타일: `nugabox/web-publish-admin` 의 `src/admin` 을 그대로 사용
- 메뉴: 대시보드 / 파일 관리
- 업로드한 파일은 `https://nugabox.com/upload/<파일명>` 으로 바로 열립니다.

---

## 구조

```
admin/
├── index.php                  대시보드 (업로드 현황 · 저장소 상태)
├── files.php                  파일 관리 (업로드 · 이름변경 · 삭제)
├── login.php / logout.php     세션 로그인
├── .env.php                   아이디 · 비밀번호 등 실제 설정 (git 제외)
├── .env.php.example           설정 예시 (커밋됨)
├── config.php                 .env.php 를 읽어 기본값과 합치는 곳
├── api/files.php              list · upload · rename · delete · sync API
├── include/
│   ├── env.php                .env 파서
│   ├── bootstrap.php          세션 · CSRF · 파일명 검증 · 유틸
│   ├── git.php                커밋 · 푸시 (flock, rebase 재시도)
│   ├── header.php / footer.php
└── assets/                    css · js · fonts · images
upload/                        공개 업로드 폴더
```

---

## 설치

### 1. 설정 파일 만들기

```bash
cd /volume1/Develop/Sites/nugabox.github.io/admin
cp .env.php.example .env.php
vi .env.php        # ADMIN_ID, ADMIN_PASSWORD 를 실제 값으로
```

`.env.php` 는 `.gitignore` 로 제외되어 커밋되지 않고, 배포 시
`git reset --hard` 에도 지워지지 않습니다.

#### 왜 `.env` 가 아니라 `.env.php` 인가

이 저장소 루트가 곧 웹 문서 루트입니다. 평범한 `.env` 를 두면
`https://nugabox.com/.env` 로 그대로 열려 버리고, 시놀로지 DSM 에서는
그걸 막을 nginx 설정을 건드릴 수 없습니다.

그래서 내용은 `.env` 문법 그대로 두되 파일 앞뒤를 PHP 주석으로 감쌉니다.

```php
<?php /*
ADMIN_ID=admin
ADMIN_PASSWORD=실제비밀번호
*/
```

`/admin/.env.php` 를 주소창으로 직접 열면 웹서버가 PHP 로 실행하므로
**빈 화면만 나오고 내용은 노출되지 않습니다.** 첫 줄과 마지막 줄은 지우지 마세요.

비밀번호를 평문으로 두기 싫으면 해시를 쓰면 됩니다. 둘 다 있으면 해시가 우선합니다.

```bash
php -r "echo password_hash('실제로쓸비밀번호', PASSWORD_DEFAULT), PHP_EOL;"
# 출력값을 ADMIN_PASSWORD_HASH 에 넣고 ADMIN_PASSWORD 는 비웁니다
```

설정 파일을 문서 루트 바깥에 두고 싶다면 환경변수로 경로를 지정할 수도 있습니다.

```
NUGABOX_ENV_FILE=/volume1/Develop/Sites/.env
```

대시보드의 **저장소 · 환경** 카드에 실제로 읽어들인 설정 파일 경로가 표시됩니다.

### 2. 권한

먼저 PHP-FPM 실행 사용자를 확인합니다. 시놀로지 웹 스테이션은 보통 `http` 입니다.

```bash
ps -ef | grep php-fpm      # 'php-fpm: pool www' 프로세스의 소유자
```

PHP 가 써야 하는 곳은 **`upload/` 와 `.git/` 둘뿐**입니다.
잠금 파일과 로그인 시도 기록은 저장소가 아니라 시스템 임시 폴더에 씁니다.
(`RUNTIME_DIR` 로 바꿀 수 있습니다)

> **⚠ `chown` 으로 소유자를 바꾸지 마세요.**
> `.git` 의 소유자가 웹서버 사용자로 바뀌면, 배포 러너(root)가 같은 저장소에서
> `fatal: detected dubious ownership` 로 멈춰 **CI/CD 가 깨집니다.**
> 소유자는 그대로 두고 **그룹만** 열어 주는 것이 맞습니다.

```bash
cd /volume1/Develop/Sites/nugabox.github.io

chgrp -R http .git upload                          # 소유자 유지, 그룹만 http
chmod -R g+rwX .git upload
find .git upload -type d -exec chmod g+s {} \;     # 새로 생기는 파일도 그룹 상속
```

시놀로지 공유 폴더에 ACL 이 켜져 있으면(`ls -l` 결과 끝에 `+` 표시)
`chmod` 가 덮어써질 수 있습니다. 그럴 때는 ACL 로 넣습니다.

```bash
synoacltool -get upload                            # 현재 ACL 확인
synoacltool -add upload "user:http:allow:rwxpdDaARWc--:fd--"
synoacltool -add .git   "user:http:allow:rwxpdDaARWc--:fd--"
```

설정한 뒤 실제로 쓸 수 있는지 확인합니다.

```bash
su http -s /bin/sh -c 'touch upload/.probe' && echo OK && rm -f upload/.probe
```

가장 확실한 확인은 관리자 **대시보드**입니다.
`저장소 · 환경` 카드에 `upload 쓰기` · `.git 쓰기` 가 각각 **가능** 으로 뜨고
`실행 사용자` 가 표시됩니다. 하나라도 **불가** 면 경고 문구가 함께 나옵니다.

### 3. 웹서버 (시놀로지 DSM)

**웹 스테이션 > 웹 서비스** 에서 이 폴더를 문서 루트로 하는 웹 서비스를 만들고,
PHP 프로필을 연결하면 됩니다. nginx 설정을 따로 만질 필요는 없습니다.

- `/admin` 하위가 PHP 로 실행되면 끝입니다. `.env.php` 보호도 여기에 딸려옵니다.
- 업로드 용량을 늘리려면 **PHP 프로필 > 핵심 설정** 에서
  `upload_max_filesize` 와 `post_max_size` 를 올리고,
  웹 서비스의 최대 업로드 크기도 함께 올립니다.
  실제로 적용된 상한은 파일 관리 화면 상단에 표시됩니다.

nginx 를 직접 다룰 수 있는 환경이라면 아래를 권장합니다.

```nginx
# 업로드 폴더는 정적 파일로만 서빙 — PHP 실행 금지
location ^~ /upload/ {
    location ~ \.(php|phtml|phar|cgi|pl|py|sh)$ { deny all; }
    autoindex off;
    add_header X-Content-Type-Options nosniff;
}

# 내부 파일 접근 차단
location ~ ^/admin/include/ { deny all; }
location ~ /\.git { deny all; }

client_max_body_size 100m;
```

> `/upload` 하위에서 PHP 실행을 못 막더라도, 업로드 API 가
> `.php` · `.phtml` · `.htaccess` 등을 이름 어디에 있든 거부하기 때문에
> 실행 가능한 파일 자체가 올라가지 않습니다.

---

## 동작

| 작업 | 서버에서 일어나는 일 |
|---|---|
| 업로드 | `upload/` 에 저장 → `git add -A -- upload` → `커밋: upload 파일 업로드` → `git push origin HEAD:main` |
| 이름 변경 | `rename()` → 위와 동일 |
| 삭제 | `unlink()` → 위와 동일 |
| git 동기화 버튼 | 밀린 커밋 · 변경분을 다시 커밋 · 푸시 |

- 커밋 메시지는 항상 `upload 파일 업로드` 입니다. (`config.php` 의 `git.commit_message`)
- 동시에 여러 작업이 겹치지 않도록 `admin/.git-sync.lock` 으로 직렬화합니다.
- push 가 거부되면 `fetch` → `rebase --autostash` 후 한 번 더 시도합니다.
- push 가 끝내 실패해도 **파일은 서버에 남아 URL 로 접근 가능**합니다.
  원인을 해결한 뒤 파일 관리 화면의 **git 동기화** 버튼을 누르면 밀린 커밋이 올라갑니다.

> **⚠ push 실패는 방치하면 안 됩니다.**
> 커밋만 되고 push 가 안 된 상태에서 다른 배포가 돌면
> `git reset --hard origin/main` 이 그 커밋을 되돌리면서 **올린 파일도 사라집니다.**
> 그래서 대시보드와 파일 목록에 `푸시 안 된 커밋 N개` 경고를 띄웁니다.
> 이 경고가 보이면 먼저 **git 동기화** 를 눌러 주세요.
- push 가 성공하면 GitHub Actions 의 배포 워크플로가 돌면서 Slack 으로 알림이 갑니다.
  (저장소 시크릿 `SLACK_WEBHOOK_URL`. 없으면 배포는 되고 알림만 건너뜁니다)

---

## 보안 처리

| 항목 | 처리 |
|---|---|
| 자격증명 | `admin/.env.php` (git 제외). PHP 로 실행되어 웹에서 내용을 읽을 수 없음 |
| 인증 | PHP 세션 로그인. 모든 페이지 · API 가 로그인 검사 |
| 세션 | 쿠키 `path=/admin`, `HttpOnly`, HTTPS 면 `Secure`, `SameSite=Lax`, 4시간 무활동 만료 |
| CSRF | 모든 변경 API 가 세션 토큰 검사 |
| 무차별 대입 | IP 기준 15분 내 10회 실패 시 차단 |
| 파일명 | `basename()` + 화이트리스트 정규식. 경로 탈출 · 숨김파일 차단 |
| 확장자 | `.php` `.phtml` `.htaccess` 등은 이름 어디에 있어도 거부 (`a.php.jpg` 포함) |
| 허용 확장자 | `ALLOWED_EXT` 화이트리스트. `svg` 는 기본 제외 |
| 오픈 리다이렉트 | 로그인 후 이동 경로를 `/admin/…` 으로 제한 |
| 검색 노출 | 모든 관리자 페이지에 `noindex,nofollow` |

`svg` 를 허용하려면 `.env.php` 의 `ALLOWED_EXT` 에 확장자를 직접 나열하세요.
같은 오리진에서 스크립트가 실행될 수 있으므로 신뢰하는 파일만 올려야 합니다.

---

## 문제 해결

**업로드는 되는데 push 만 실패한다** — 거의 항상 자격증명 문제입니다.

```
fatal: could not read Username for 'https://github.com': terminal prompts disabled
```

`git push` 가 되는 건 **당신 계정**이지 웹서버 사용자(`http`)가 아닙니다.
`http` 는 자기 홈에 자격증명이 없습니다. `.env.php` 에 토큰을 넣어 주세요.

```
GIT_TOKEN=github_pat_...
GIT_USERNAME=nugaBox        # 비우면 원격 URL 의 사용자명을 씁니다
```

Fine-grained 토큰이면 이 저장소에 **Contents: Read and write** 권한만 있으면 됩니다.
토큰은 임시 파일(0600)을 거쳐 전달되므로 `ps` 의 명령줄이나 화면 로그에 남지 않습니다.

이미 만들어 둔 자격증명 파일이 있으면 그쪽을 써도 됩니다.

```
GIT_CREDENTIALS_FILE=/volume1/Develop/Sites/.git-credentials
```

**로그인이 안 된다 / "비밀번호가 설정되지 않았습니다"** — 대시보드는 못 보니
`admin/.env.php` 가 있는지, 첫 줄 `<?php /*` 와 마지막 줄 닫는 주석이 남아 있는지
확인하세요. 값에 별표+슬래시가 들어가면 주석이 거기서 끊깁니다.

**"upload 폴더에 쓸 권한이 없습니다"** — 위 2번 권한 설정을 확인하세요.

**대시보드에 "커밋 안 된 변경 있음"** — 이전 push 가 실패했거나 파일이 직접
복사된 경우입니다. 파일 관리 화면의 **git 동기화** 를 누르세요.
