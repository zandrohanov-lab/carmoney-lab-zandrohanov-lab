#!/usr/bin/env bash
set -euo pipefail

usage() {
    printf 'Использование: %s <email> <тело> <вложение> [файл .eml]\n' "$0" >&2
    exit 64
}

[[ $# -ge 3 && $# -le 4 ]] || usage

recipient=$1
body=$2
attachment=$3
output=${4:-"${attachment%.*}.eml"}

[[ $recipient != *$'\r'* && $recipient != *$'\n'* && $recipient == *@* ]] || {
    printf 'Некорректный email получателя.\n' >&2
    exit 65
}

[[ -f $attachment && -r $attachment ]] || {
    printf 'Вложение недоступно: %s\n' "$attachment" >&2
    exit 66
}

[[ $output == *.eml ]] || output="${output}.eml"
output_dir=$(dirname "$output")
[[ -d $output_dir ]] || {
    printf 'Каталог результата не существует: %s\n' "$output_dir" >&2
    exit 73
}

boundary="release-notes-$(date +%s)-$$"
attachment_name=$(basename "$attachment")

{
    printf 'To: %s\r\n' "$recipient"
    printf 'From: release-notes@localhost\r\n'
    printf 'Subject: Release notes\r\n'
    printf 'MIME-Version: 1.0\r\n'
    printf 'Content-Type: multipart/mixed; boundary="%s"\r\n' "$boundary"
    printf '\r\n'
    printf '%s\r\n' "--$boundary"
    printf 'Content-Type: text/plain; charset=UTF-8\r\n'
    printf 'Content-Transfer-Encoding: base64\r\n'
    printf '\r\n'
    printf '%s\n' "$body" | base64
    printf '\r\n'
    printf '%s\r\n' "--$boundary"
    printf 'Content-Type: application/octet-stream; name="%s"\r\n' "$attachment_name"
    printf 'Content-Transfer-Encoding: base64\r\n'
    printf 'Content-Disposition: attachment; filename="%s"\r\n' "$attachment_name"
    printf '\r\n'
    base64 -i "$attachment"
    printf '\r\n--%s--\r\n' "$boundary"
} > "$output"

printf 'Файл письма создан: %s\n' "$output"
