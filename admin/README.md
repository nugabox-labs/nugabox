# NUGABOX 관리자 (`/admin`)

`upload/` 폴더의 파일을 웹에서 업로드 · 이름변경 · 삭제하고, 그 결과를 곧바로
git 에 커밋 · 푸시해 **서버에 올라간 내용과 저장소를 항상 같은 상태로 유지**합니다.

- 화면 스타일: `nugabox/web-publish-admin` 의 `src/admin` 을 그대로 사용
- 메뉴: 대시보드 / 파일 관리
- 업로드한 파일은 `https://nugabox.com/upload/<파일명>` 으로 바로 열립니다.
- 운영 환경: 시놀로지 DSM(웹 스테이션 nginx + PHP-FPM) + GitHub Actions 자체 호스팅 러너

이 문서는 설치 절차뿐 아니라 **실제 구축에서 부딪힌 함정과 그 근거**까지 기록합니다.
같은 구조를 다른 사이트에 옮길 때 [설계 기록](#설계-기록) 절을 먼저 읽으세요.

---

## 구조

```
admin/
├── index.php                  대시보드 (업로드 현황 · 저장소 상태 · 진단)
├── files.php                  파일 관리 (업로드 · 이름변경 · 삭제)
├── login.php / logout.php     세션 로그인
├── .env.php                   아이디 · 비밀번호 · 토큰 등 실제 설정 (git 제외)
├── .env.php.example           설정 예시 (커밋됨)
├── config.php                 .env.php 를 읽어 기본값과 합치는 곳
├── api/files.php              list · upload · rename · delete · sync API
├── include/
│   ├── env.php                .env 파서 + 파일 상태 판별
│   ├── bootstrap.php          세션 · CSRF · 파일명 검증 · 런타임 경로 · 유틸
│   ├── git.php                커밋 · 푸시 (flock, 자격증명, rebase 재시도)
│   └── header.php / footer.php
└── assets/                    css · js · fonts · images

upload/                        공개 업로드 폴더
.github/
├── workflows/deploy.yml       배포 워크플로
└── slack_notify.py            배포 결과 슬랙 알림 (Block Kit)
```

---

## 설치

### 1. 설정 파일 만들기

```bash
cd /volume1/Develop/Sites/nugabox.github.io/admin
cp .env.php.example .env.php
vi .env.php        # ADMIN_ID, ADMIN_PASSWORD, GIT_TOKEN 을 실제 값으로
```

`.env.php` 는 `.gitignore` 로 제외되어 커밋되지 않고, 배포 시
`git reset --hard` 에도 지워지지 않습니다.

#### 왜 `.env` 가 아니라 `.env.php` 인가

이 저장소 루트가 곧 웹 문서 루트입니다. 평범한 `.env` 를 두면
`https://nugabox.com/.env` 로 그대로 열려 버리고, 시놀로지 DSM 에서는
그걸 막을 nginx 설정(`location ~ /\.`)을 건드릴 수 없습니다.

그래서 내용은 `.env` 문법 그대로 두되 파일 앞뒤를 PHP 주석으로 감쌉니다.

```php
<?php /*
ADMIN_ID=admin
ADMIN_PASSWORD=실제비밀번호
*/
```

`/admin/.env.php` 를 주소창으로 직접 열면 웹서버가 PHP 로 실행하므로
**본문 0바이트**가 나오고 내용은 노출되지 않습니다. (실측 확인)
첫 줄 `<?php /*` 와 마지막 줄의 닫는 주석은 지우지 마세요.
값 안에 별표+슬래시 조합을 쓰면 주석이 거기서 끊깁니다.

파서는 `KEY=VALUE` 만 인식하므로 `<?php /*` 와 `*/` 두 줄은 자연히 무시됩니다.
따옴표, 인라인 `#` 주석, `export` 접두사, 값 안의 `=` 도 처리합니다.

비밀번호를 평문으로 두기 싫으면 해시를 쓰면 됩니다. 둘 다 있으면 해시가 우선입니다.

```bash
php -r "echo password_hash('실제로쓸비밀번호', PASSWORD_DEFAULT), PHP_EOL;"
# 출력값을 ADMIN_PASSWORD_HASH 에 넣고 ADMIN_PASSWORD 는 비웁니다
```

설정 파일을 문서 루트 바깥에 두고 싶다면 환경변수로 경로를 지정할 수도 있습니다.

```
NUGABOX_ENV_FILE=/volume1/Develop/Sites/.env
```

> **⚠ `.env.php` 는 웹서버 사용자가 읽을 수 있어야 합니다.**
> 못 읽으면 설정이 **통째로** 비어 보입니다. 토큰뿐 아니라 비밀번호까지
> 없는 것처럼 동작해서 로그인조차 되지 않습니다.
> 파일을 서버에서 직접 편집하거나 새로 올린 뒤에는 반드시 권한을 확인하세요.
>
> ```bash
> su http -s /bin/sh -c 'cat admin/.env.php > /dev/null' && echo "읽기 OK"
> chgrp http admin/.env.php && chmod 640 admin/.env.php
> ```
>
> 대시보드 `설정 파일` 행에 `읽기 불가` 가 뜨면 이 상태입니다.

### 2. 권한

먼저 PHP-FPM 실행 사용자를 확인합니다. 시놀로지 웹 스테이션은 보통 `http` 입니다.

```bash
ps -ef | grep php-fpm      # 'php-fpm: pool www' 프로세스의 소유자
```

PHP 가 **써야** 하는 곳은 `upload/` 와 `.git/` 둘뿐이고,
**읽어야** 하는 곳에 `admin/.env.php` 가 추가됩니다.
잠금 파일과 로그인 시도 기록은 저장소가 아니라 시스템 임시 폴더에 씁니다.
(`RUNTIME_DIR` 로 바꿀 수 있습니다)

> **⚠ 함정 1 — `chown` 으로 소유자를 바꾸지 마세요.**
> `.git` 의 소유자가 웹서버 사용자로 바뀌면, 배포 러너(root)가 같은 저장소에서
> `fatal: detected dubious ownership` 로 멈춰 **CI/CD 가 깨집니다.**
> 소유자는 그대로 두고 **그룹만** 열어 주는 것이 맞습니다.

> **⚠ 함정 2 — 대상 폴더의 소유자가 이미 웹서버 사용자면 그룹 비트가 무시됩니다.**
> POSIX 는 **첫 번째로 일치하는 클래스만** 봅니다. `upload` 가 `http` 소유인데
> owner 비트가 `r-x` 면, group 에 `rwx` 를 줘도 owner 에서 판정이 끝나 쓰기가 막힙니다.
> 이 경우 `u+rwX` 도 함께 줘야 합니다.
>
> ```
> dr-xrwx---  ngjang http  .git    ← 소유자 ≠ http → group rwx 적용 → 쓰기 OK
> dr-xrwx---  http   http  upload  ← 소유자 = http → owner r-x 만 적용 → 쓰기 불가
> ```

```bash
cd /volume1/Develop/Sites/nugabox.github.io

chgrp -R http .git upload                          # 소유자 유지, 그룹만 http
chmod -R ug+rwX .git upload                        # owner 비트도 함께 (함정 2)
find .git upload -type d -exec chmod g+s {} \;     # 새로 생기는 파일도 그룹 상속

chgrp http admin/.env.php                          # 설정 파일은 읽기만
chmod 640 admin/.env.php
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

### 3. git push 자격증명

**`git push` 가 되는 건 당신 계정이지 웹서버 사용자가 아닙니다.**
`http` 는 자기 홈에 자격증명이 없어서 push 만 실패합니다.

```
fatal: could not read Username for 'https://github.com': terminal prompts disabled
```

`.env.php` 에 토큰을 넣으면 해결됩니다.

```
GIT_TOKEN=ghp_...           # 또는 github_pat_...
GIT_USERNAME=nugaBox        # GitHub 로그인 아이디. 비우면 원격 URL 의 사용자명을 씀
```

- Fine-grained 토큰이면 이 저장소에 **Contents: Read and write** 권한만 있으면 됩니다.
- 토큰은 0600 임시 파일을 거쳐 `credential.helper=store` 로 전달됩니다.
  **`ps` 의 명령줄에도, 화면 로그에도, API 응답에도 남지 않고** 작업이 끝나면 삭제됩니다.
- `GIT_USERNAME` 에는 표시 이름(예: `Nuga Jang`)이 아니라 **로그인 아이디**를 넣으세요.
  PAT 는 사용자명을 따지지 않아 동작은 하지만 혼란스럽습니다.

이미 만들어 둔 자격증명 파일이 있으면 그쪽을 써도 됩니다.

```
GIT_CREDENTIALS_FILE=/volume1/Develop/Sites/.git-credentials
```

원격이 SSH 면 토큰 대신 `GIT_HOME` 으로 키가 있는 홈을 지정합니다.

### 4. 웹서버 (시놀로지 DSM)

**웹 스테이션 > 웹 서비스** 에서 이 폴더를 문서 루트로 하는 웹 서비스를 만들고,
PHP 프로필을 연결하면 됩니다. nginx 설정을 따로 만질 필요는 없습니다.

- `/admin` 하위가 PHP 로 실행되면 끝입니다. `.env.php` 보호도 여기에 딸려옵니다.
- **`.env.php` 를 올리기 전에 반드시** `https://nugabox.com/admin/login.php` 를 열어
  로그인 화면이 그려지는지 확인하세요. PHP 소스가 그대로 보이면 PHP 가 연결되지
  않은 상태이고, 그때 `.env.php` 를 올리면 비밀번호가 평문으로 노출됩니다.
- 업로드 용량은 **PHP 프로필 > 핵심 설정** 의 `upload_max_filesize` ·
  `post_max_size` 와 웹 서비스의 최대 업로드 크기에 걸립니다.
  `MAX_UPLOAD_MB` 를 크게 잡아도 **둘 중 작은 값**이 적용되며,
  실제 상한은 파일 관리 화면 상단에 표시됩니다.

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

### 5. 배포 알림 (Slack)

GitHub 저장소 **Settings > Secrets and variables > Actions** 에
`SLACK_WEBHOOK_URL` 을 등록합니다. 없으면 배포는 되고 알림만 건너뜁니다.

메시지는 `.github/slack_notify.py` 가 **Block Kit** 으로 만듭니다.
슬랙에는 마크다운 표가 없어서, 표 문법을 쓰면 그대로 풀려 읽기 어려워집니다.
`section fields` 로 2열 배치를 만들어 저장소 · 브랜치 · 커밋 · 시각(KST)을 보여주고,
커밋 제목과 작성자를 덧붙입니다. 원본 진단 덤프는 **실패 알림에만** 넣습니다.

---

## 설치 확인 체크리스트

`/admin` 로그인 후 대시보드 **저장소 · 환경** 카드가 곧 진단 화면입니다.

| 행 | 정상 | 비정상일 때 |
|---|---|---|
| `upload 쓰기` | 가능 | 불가 → [2. 권한](#2-권한) |
| `.git 쓰기` | 가능 | 불가 → [2. 권한](#2-권한). 커밋 자체가 안 됩니다 |
| `push 자격증명` | `토큰 사용 (아이디@github.com)` | `GIT_TOKEN 이 비어 있습니다` → [3. 자격증명](#3-git-push-자격증명) |
| `설정 파일` | 파일 경로 | `읽기 불가` / `없음` → `.env.php` 권한 |
| `실행 사용자 · PHP` | `http · 8.x` | — |
| 상단 상태 태그 | `동기화됨` | `푸시 안 된 커밋 N개` → **git 동기화** 클릭 |

마지막으로 이미지 하나를 올려 URL 이 열리고, GitHub Actions 에 배포가 한 번 더
도는지, 슬랙 알림이 오는지 확인하면 끝입니다.

---

## 동작

| 작업 | 서버에서 일어나는 일 |
|---|---|
| 업로드 | `upload/` 에 저장 → `git add -A -- upload` → `커밋: upload 파일 업로드` → `git push origin HEAD:main` |
| 이름 변경 | `rename()` → 위와 동일 |
| 삭제 | `unlink()` → 위와 동일 |
| git 동기화 버튼 | 밀린 커밋 · 변경분을 다시 커밋 · 푸시 |

- 커밋 메시지는 항상 `upload 파일 업로드` 입니다. (`GIT_COMMIT_MESSAGE`)
- 동시 작업이 겹치지 않도록 임시 폴더의 잠금 파일로 직렬화합니다.
- 여러 파일을 한 번에 올려도 **하나씩 순차 처리**합니다. 커밋이 직렬화되어야 하기 때문입니다.
- push 가 **원격이 앞서서** 거부되면 `fetch` → `rebase --autostash` 후 한 번 더 시도합니다.
  `--autostash` 는 배포 디렉터리에 커밋되지 않은 변경이 남아 있어도 rebase 가 멈추지
  않게 합니다.
- 인증 실패 · 네트워크 오류처럼 rebase 로 풀리지 않는 실패는 재시도하지 않고
  **원인별 안내 문구**를 그대로 보여줍니다.
- push 가 끝내 실패해도 **파일은 서버에 남아 URL 로 접근 가능**합니다.
  원인을 해결한 뒤 **git 동기화** 버튼을 누르면 밀린 커밋이 올라갑니다.

> **⚠ 함정 3 — push 실패는 방치하면 안 됩니다.**
> 커밋만 되고 push 가 안 된 상태에서 다른 배포가 돌면
> `git reset --hard origin/main` 이 그 커밋을 되돌리면서 **올린 파일도 사라집니다.**
> (실측: 배포 후 해당 URL 이 404)
> 그래서 대시보드와 파일 목록에 `푸시 안 된 커밋 N개` 경고를 띄웁니다.
> 이 경고가 보이면 먼저 **git 동기화** 를 눌러 주세요.

배포 워크플로(`.github/workflows/deploy.yml`)는 `git reset --hard origin/main` 만
수행합니다. 관리자가 이미 커밋 · 푸시해 둔 상태이므로 사실상 no-op 이며,
서버와 저장소가 어긋나지 않았음을 확인하는 역할을 합니다.
배포 단계의 git 명령에는 `-c safe.directory` 가 붙어 있습니다.
관리자가 만든 다른 사용자 소유 파일이 `.git` 에 섞여도 멈추지 않게 하기 위해서입니다.

---

## 보안 처리

| 항목 | 처리 |
|---|---|
| 자격증명 | `admin/.env.php` (git 제외). PHP 로 실행되어 웹에서 내용을 읽을 수 없음 |
| git 토큰 | 0600 임시 파일 경유. 명령줄 · 로그 · API 응답 어디에도 남지 않음 |
| 인증 | PHP 세션 로그인. 모든 페이지 · API 가 로그인 검사 |
| 세션 | 쿠키 `path=/admin`, `HttpOnly`, HTTPS 면 `Secure`, `SameSite=Lax`, 4시간 무활동 만료 |
| CSRF | 모든 변경 API 가 세션 토큰 검사 |
| 무차별 대입 | IP 기준 15분 내 10회 실패 시 차단 |
| 파일명 | `basename()` + 화이트리스트 정규식. 경로 탈출 · 숨김파일 차단 |
| 확장자 | `.php` `.phtml` `.htaccess` 등은 이름 어디에 있어도 거부 (`a.php.jpg` 포함) |
| 허용 확장자 | `ALLOWED_EXT` 화이트리스트. `svg` 는 기본 제외 |
| 오픈 리다이렉트 | 로그인 후 이동 경로를 `/admin/…` 으로 제한 |
| 런타임 파일 | 잠금 · 시도 기록을 임시 폴더에 둬 `admin/` 에 쓰기 권한이 필요 없음 |
| 검색 노출 | 모든 관리자 페이지에 `noindex,nofollow` |

거부 동작은 실제로 확인했습니다 — `.php` 업로드, 이중 확장자 `a.php.jpg`,
경로 탈출 `../../evil.jpg`(→ `evil.jpg` 로 정규화되어 `upload/` 안에 저장),
숨김 파일 `.htaccess`, CSRF 토큰 누락, 미로그인 요청, `rename` 으로 `.php` 화.

`svg` 를 허용하려면 `.env.php` 의 `ALLOWED_EXT` 에 확장자를 직접 나열하세요.
같은 오리진에서 스크립트가 실행될 수 있으므로 신뢰하는 파일만 올려야 합니다.

---

## 문제 해결

### 로그인이 안 된다 — "관리자 비밀번호가 설정되지 않았습니다"

`.env.php` 는 있는데 **웹서버가 읽지 못하는** 경우가 대부분입니다.
파일을 서버에서 편집하거나 새로 올리면 `root:root 600` 이 되기 쉽습니다.
설정이 통째로 비어 보이므로 비밀번호도 토큰도 없는 것처럼 동작합니다.

이미 로그인된 세션은 살아 있어서 **대시보드는 멀쩡해 보입니다.**
로그아웃해야 드러나므로, 증상이 애매하면 이것부터 의심하세요.

```bash
cd /volume1/Develop/Sites/nugabox.github.io/admin
su http -s /bin/sh -c 'cat .env.php > /dev/null' && echo "읽기 OK" || echo "읽기 불가"
chgrp http .env.php && chmod 640 .env.php
```

로그인 화면과 대시보드가 이 상태를 구분해서 알려 줍니다
(`파일은 있지만 웹서버가 읽지 못합니다` / `없습니다`).

파일 구조가 깨졌을 수도 있습니다. 첫 줄 `<?php /*` 와 마지막 줄 닫는 주석이
남아 있는지, 값에 별표+슬래시가 들어가지 않았는지 확인하세요.

### 업로드는 되는데 push 만 실패한다

대시보드 `push 자격증명` 행을 보세요.

| 표시 | 의미 | 조치 |
|---|---|---|
| `GIT_TOKEN 이 비어 있습니다` | 토큰을 못 읽음 | 값이 있는지, `.env.php` 를 읽을 수 있는지 확인 |
| `원격 URL 을 읽지 못했습니다` | `git remote` 실패 | `.git` 읽기 권한, 원격 설정 확인 |
| `토큰 사용 (…@github.com)` 인데도 실패 | GitHub 이 거부 | 토큰 만료 · `Contents: write` 권한 확인 |

오류 문구도 두 경우를 구분합니다.

```
GitHub 인증에 실패했습니다. 자격증명이 구성되지 않았습니다 — GIT_TOKEN 이 비어 있습니다.
GitHub 이 자격증명을 거부했습니다 (토큰 사용 …). 토큰이 만료되었거나 …
```

### "upload 폴더에 쓸 권한이 없습니다"

[2. 권한](#2-권한) 을 확인하세요. 특히 **함정 2**(소유자가 `http` 인데 owner 비트에
쓰기가 없는 경우)를 놓치기 쉽습니다.

### 대시보드에 "푸시 안 된 커밋 N개"

이전 push 가 실패해 로컬에만 커밋이 남아 있습니다.
**그대로 두면 다음 배포에서 파일이 사라집니다.**
원인을 해결한 뒤 **git 동기화** 를 누르세요.

### 배포가 `dubious ownership` 으로 멈춘다

`.git` 소유자를 웹서버 사용자로 바꾼 경우입니다(함정 1).
소유자를 원래대로 되돌리고 그룹만 열어 주세요.
워크플로에도 `-c safe.directory` 를 넣어 뒀지만, 소유권 자체를 바꾸지 않는 것이 맞습니다.

### 슬랙 알림이 안 온다

Actions 로그의 `Send Success Notification` 단계를 보세요.

- `SLACK_WEBHOOK_URL 없음 — 알림 생략` → 시크릿 미등록
- `slack error: 400 invalid_payload` → 블록 구조 문제
- `slack: ok` → 정상 전송. 채널 설정을 확인하세요

### `setlocale: LC_ALL: cannot change locale` 경고가 섞인다

해결됐습니다. 예전에는 `LC_ALL=C.UTF-8` 을 강제해서 해당 로케일이 없는
시놀로지에서 경고가 git stderr 에 섞였습니다. 지금은 시스템 설정을 그대로 씁니다.

---

## 설계 기록

구축 과정에서 실제로 부딪혀 설계가 바뀐 지점들입니다.

**설정 파일을 `.env.php` 로 감쌌다.**
문서 루트 = 저장소 루트라 `.env` 가 웹에 그대로 노출됩니다. DSM 에서는 이를 막을
nginx 설정을 건드릴 수 없어, PHP 로 실행되게 만들어 원천 차단했습니다.
문서 루트 바깥에 두는 방법(`NUGABOX_ENV_FILE`)도 남겨 뒀지만,
DSM 의 PHP 프로필에 따라 상위 폴더를 못 읽을 수 있어 기본값은 `.env.php` 입니다.

**`.git` 소유자를 바꾸지 않는다.**
처음에는 `chown -R http .git` 을 안내했는데, 배포 러너(root)가 같은 저장소에서
`dubious ownership` 으로 멈추는 것을 재현했습니다. PHP 쪽은 `-c safe.directory`
로 통과하지만 러너는 그렇지 않았습니다. 그룹 + setgid 방식으로 바꾸니 양쪽 모두
정상 동작했습니다.

**push 실패를 눈에 띄게 만들었다.**
`git status` 만 보면 커밋 후에는 깨끗하므로 대시보드가 `동기화됨` 으로 보였습니다.
그 상태에서 배포가 돌면 `reset --hard` 가 파일을 되돌립니다(실측 404).
`rev-list --count origin/main..HEAD` 로 밀린 커밋 수를 세어 경고합니다.

**런타임 파일을 저장소 밖으로 뺐다.**
잠금 파일과 로그인 시도 기록을 `admin/` 에 쓰고 있었는데, 그러려면 웹서버
사용자에게 PHP 가 실행되는 디렉터리의 쓰기 권한을 줘야 합니다. 임시 폴더로
옮겨서 필요한 권한을 `upload/` 와 `.git/` 로 줄였습니다.

**설정을 못 읽으면 조용히 넘어가지 않는다.**
`.env.php` 를 읽지 못할 때 빈 설정으로 동작하면서 "파일을 먼저 만들어 주세요"
라고 안내해 엉뚱한 곳을 찾게 만들었습니다. 파일이 없는 경우와 있는데 못 읽는
경우를 구분해 각각 다르게 알리고, 고치는 명령까지 화면에 띄웁니다.

**토큰을 명령줄에 노출하지 않는다.**
`https://user:token@github.com` 형태로 remote 를 바꾸면 `ps` 에 토큰이 보입니다.
0600 임시 파일 + `credential.helper=store --file=` 로 넘겨 경로만 argv 에 남깁니다.

**push 실패 원인을 구분한다.**
모든 push 실패를 "원격과 충돌"로 간주해 불필요하게 rebase 를 시도하고 있었습니다.
인증 실패였는데 충돌이라고 안내해 진단을 어렵게 만들었습니다.

**슬랙은 표를 지원하지 않는다.**
Mattermost 에서 쓰던 마크다운 표가 슬랙에서는 그대로 풀립니다.
Block Kit `section fields` 로 2열 배치를 만들었습니다.

---

## 로컬에서 테스트하기

운영에 손대기 전 도커로 전 과정을 재현할 수 있습니다.

```bash
# php + git 이미지
docker build -t nugabox-admin-test - <<'EOF'
FROM php:8.2-cli
RUN apt-get update && apt-get install -y --no-install-recommends git curl ca-certificates \
 && rm -rf /var/lib/apt/lists/*
EOF

# 저장소 사본 + 로컬 bare 원격 (GitHub 에 아무것도 나가지 않음)
docker run -d --name admin-test -p 8080:8080 nugabox-admin-test sleep infinity
docker cp . admin-test:/srv/live
docker exec admin-test bash -euc '
  useradd -m -s /bin/sh http
  rm -rf /srv/live/.git                      # 실수로 실제 origin 에 push 하지 않도록
  chown -R root:root /srv/live
  git init -q --bare -b main /srv/remote.git
  cd /srv/live
  git init -q -b main
  git config user.name test && git config user.email test@example.com
  git remote add origin /srv/remote.git
  git add -A && git commit -qm init && git push -q origin main
  chgrp -R http .git upload && chmod -R ug+rwX .git upload
  find .git upload -type d -exec chmod g+s {} \;'
docker exec -d admin-test su http -s /bin/sh -c \
  'PUBLIC_BASE_URL=http://localhost:8080 php -S 0.0.0.0:8080 -t /srv/live'
```

`http://localhost:8080/admin` 으로 접속합니다.
권한 함정을 재현하려면 `chmod 550 upload` 처럼 되돌린 뒤 대시보드를 보면 됩니다.

슬랙 메시지는 전송 없이 확인할 수 있습니다.

```bash
DEPLOY_STATUS=success GH_SERVER=https://github.com GH_REPO=nugaBox/nugabox.github.io \
GH_REF=main GH_SHA=$(git rev-parse HEAD) GH_RUN_URL=https://example.com \
DEPLOY_DIR=/volume1/Develop/Sites/nugabox.github.io DEPLOY_SUBJECT="테스트" \
python3 -c "import sys,json;sys.path.insert(0,'.github');
from slack_notify import build_payload;print(json.dumps(build_payload(),ensure_ascii=False,indent=2))"
```
