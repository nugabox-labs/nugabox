<?php
// 프로젝트 목록은 공개하지 않는다. 주소를 아는 사람만 /pages/<코드>/ 로 들어온다.
http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><meta charset="utf-8"><title>404</title>';
