# NUGABOX 관리자 설치

`/admin` — 아이콘 배치 · 아이콘 파일 · 업로드 파일 관리 · 주소 연결. 저장하면 파일을 쓰고 곧바로 커밋 · 푸시한다.

**업로드 파일 관리**는 `/upload` 폴더를 다룬다. 아이콘과 달리 이미지만 받지는 않지만,
아무거나 받지도 않는다. 문서 · 이미지 · 오디오/영상 · 압축 · 글꼴만 허용하는
화이트리스트이고, **허용 목록은 페이지 맨 위에 그대로 펼쳐져 있다.**
바꾸려면 `.env.php` 의 `UPLOAD_ALLOWED_EXT`.

무엇을 허용하든 실행 · 스크립트 · 소스 파일은 언제나 거부된다
(`FORBIDDEN_EXT` — php js html svg exe sh jar sql key …). 이 검사는 마지막 확장자만
보지 않고 이름의 모든 조각을 보므로 `파일.php.pdf` 도 막힌다.

올린 파일은 `https://<도메인>/upload/파일명` 으로 바로 열린다. 목록의 링크 복사 버튼이
지금 접속한 주소 기준의 전체 링크를 그대로 복사해 준다. (아이콘 파일 화면도 같다)

배포가 `git reset --hard origin/main` 이므로 **push 까지 성공해야 변경이 남는다.**

아래는 시놀로지 DSM(웹서버 계정 `http`) 기준. 배포 경로는 `/volume1/Develop/webapps/nugabox`.

## 1. 설정 파일

```bash
cd /volume1/Develop/webapps/nugabox
cp admin/.env.php.example admin/.env.php
vi admin/.env.php
```

최소 두 개:

```
ADMIN_PASSWORD=...     # 비면 /admin 이 503 으로 막힌다
GIT_TOKEN=...          # GitHub PAT, 이 저장소에 Contents: write
```

해시로 두려면:

```bash
php -r "echo password_hash('바꿀비밀번호', PASSWORD_DEFAULT), PHP_EOL;"
```

`.env.php` 는 `.gitignore` 에 있어 커밋되지 않고 `reset --hard` 에도 안 지워진다.
파일 맨 위 `<?php /*` 와 맨 끝 `*/` 는 지우지 말 것. 지우면 설정이 그대로 노출된다.

값을 고치면 다음 요청부터 바로 적용된다. 재시작 불필요.

## 2. 권한

웹서버 계정 확인:

```bash
ps -eo user,args | grep -i php | grep -v grep | awk '{print $1}' | sort -u
```

`http` 로 나온다는 전제로:

```bash
cd /volume1/Develop/webapps/nugabox
chown -R root:http .
chmod -R u+rwX,g+rwX,o+rX .
find . -type d -exec chmod g+s {} \;
```

`chmod` 는 대문자 `X` 여야 한다. 소문자 `x` 는 모든 파일을 실행 파일로 만든다.
`g+s` 는 새로 생기는 파일이 `http` 그룹을 물려받게 한다.

확인:

```bash
sudo -u http touch .git/permtest && rm .git/permtest && echo "쓰기 OK"
```

### git 내부 권한

`setgid` 는 그룹만 물려주고 그룹 쓰기 비트는 umask 가 정한다. 이대로 두면
`.git/objects/xx/` 같은 새 폴더가 그룹 쓰기 없이 생겨, 나중에 다른 계정이
그 안에 쓸 때 `Permission denied` 가 재발한다.

```bash
git config core.sharedRepository group
chmod -R g+rwX .git
```

### ACL 을 쓸 경우

`chmod` 는 ACL 이 걸린 항목에서 **ACL 을 제거한다.** 위 POSIX 방식과 섞지 말 것.
ACL 로 갈 거면 `chown`/`chmod` 블록 대신:

```bash
synoacltool -add . 'user:http:allow:rwxpdDaARWc--:fd--'
synoacltool -enforce-inherit .            # 상속은 기존 항목에 자동 적용되지 않는다
synoacltool -get .git | head              # "It's Linux mode" 면 상속이 안 내려간 것
```

한 단계만 내려가면:

```bash
find . -type d -exec synoacltool -enforce-inherit {} \;
```

## 3. DSM 웹 스테이션

- PHP 프로필의 **인덱스 파일 목록에 `index.php`** 가 있어야 첫 화면이 뜬다.
- 업로드 상한은 `MAX_UPLOAD_MB` 와 PHP 프로필의 `upload_max_filesize` ·
  `post_max_size` 중 작은 쪽. 실제 적용값은 관리자 화면에 표시된다.

## 4. 확인

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://nugabox.com/
curl -s -o /dev/null -w '%{http_code}\n' https://nugabox.com/admin/login.php
```

`/admin` 에서 아무거나 저장 → 초록 배너 `저장 · 커밋 · 푸시 완료` 면 정상.

## 문제 해결

| 증상 | 조치 |
|------|------|
| 503 `관리자가 설정되지 않았습니다` | `admin/.env.php` 없음 · 못 읽음 · 비밀번호 빔. 화면에 어느 쪽인지 나온다 |
| `index.lock: Permission denied` | 2번 권한 |
| 빨간 배너 `GitHub 반영에 실패` | 대개 `GIT_TOKEN`. `git 로그` 를 펼쳐 원문 확인 |
| `원격을 확인할 수 없습니다` | `git show-ref \| grep remotes` 로 `refs/remotes/origin/main` 존재 확인 |
| 저장 후 `쓰지 못했습니다` | `data/` · `assets/images/icons/` 쓰기 권한 |
| 배포하면 변경이 되돌아감 | push 실패. 상단 경고와 `지금 푸시` 확인 |
| 첫 화면 404 · 소스 노출 | 3번 인덱스 파일 목록 |

## 로컬에서 보기

```bash
php -S localhost:8080
# 또는
docker run --rm -v "$PWD":/app -w /app -p 8080:8080 php:8.2-cli php -S 0.0.0.0:8080
```

로컬 `admin/.env.php` 에는 `GIT_ENABLED=false` 를 두는 게 좋다.
켜 두면 저장할 때 실제 GitHub 로 푸시를 시도한다.
